"""The data_publish module (contract of 21 Nov 2026): the Mac mini is the one
source of prices and of the statistics below, sent to the N100 each night.

    uv run research publish            what changed since the data version the N100 holds
    uv run research publish --full     the whole history since 2021 (the first publish)
    uv run research publish --dry-run  work it all out, send nothing

Run by the nightly job after `research fetch` and `research indicators`, and
only if both finished with no failure for a published indicator: the last
fetch has to have finished (logs/fetch_last.json), the data must have no
unrecorded change, and each published indicator's last job entry must be ok
and its store built from the current data version up to the last price date.
Otherwise nothing is sent and it says why.

Sent, for every N100 symbol, in the N100's own units:

    historical_prices   the raw close (pence for a line quoted in pence), trading days
    close_price, ma50, ma200
                        from the store's `<name>@raw`, turned from pounds back to the quote unit
    1mnth 3mnth 6mnth 12mnth
                        per cent, as stored

Statistics for trading days only: the weekend and holiday rows the store keeps
for backtests are never sent, so the N100's live 200-day exit counts trading
days. Every other statistic stays the N100's (includes/data_ownership.php).

What is sent: every date added or changed since the data version the N100
holds. For each symbol, the first date whose prices changed since then
(data_version.changes_since: closes, the master, FX, or everything), or the
day after the N100's latest date, whichever is earlier; from there to the last
date, prices and statistics, replacing what the N100 has from that date on.
With --full, or before the first publish, 2021-01-01 for every symbol.

Whole or nothing: batches, then a closing call with the data version. A
failure part way sends `abort`, and the N100 stays on its previous version
(the close is one transaction there). An N100 symbol with no Mac mini data is
named in the close and the log, never skipped silently.

It talks only to data_import (public/data_api.php on the N100). Settings are in
the worker's file (worker.env, mode 600; RESEARCH_WORKER_SETTINGS overrides):

    SM_DATA_API_URL=http://<n100 tailscale name>/data_api.php
    SM_BACKTEST_API_TOKEN=...      the same token as the worker: one secret per machine pair

The token is never logged (config.mask()).
"""
from __future__ import annotations

import datetime as dt
import json
import logging
import os
import stat
import urllib.error
import urllib.request
import uuid
from pathlib import Path

import numpy as np
import pandas as pd

from . import config as config_mod
from . import data_version as dv
from .config import mask
from .engine import n100_symbol_key

log = logging.getLogger("research.publish")

PUBLISHED = ["close_price", "ma50", "ma200", "1mnth", "3mnth", "6mnth", "12mnth"]
IN_POUNDS = {"close_price", "ma50", "ma200"}       # stored in pounds; the N100 holds the quote unit
BASIS = "raw"                                      # the N100 prices on the raw close
FULL_FROM = pd.Timestamp("2021-01-01")
BATCH_ROWS = 20000
DEFAULT_SETTINGS = Path("~/.config/sharemanager-research/worker.env")
PRICE_PANELS = {"close", "master", "fx", dv.EVERYTHING}   # what the raw close and the @raw indicators read


class PublishRefused(RuntimeError):
    pass


# ---- settings and the door

def read_settings(path: Path | None = None) -> dict:
    path = Path(path or os.environ.get("RESEARCH_WORKER_SETTINGS") or DEFAULT_SETTINGS).expanduser()
    if not path.exists():
        raise PublishRefused(f"no settings file at {path}")
    mode = stat.S_IMODE(path.stat().st_mode)
    if mode & 0o077:
        raise PublishRefused(f"{path} is mode {mode:o}; it holds the token, so it has to be 600")
    out = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip().removeprefix("export ").strip()] = v.strip().strip('"').strip("'")
    if out.get("SM_BACKTEST_API_TOKEN"):
        config_mod._SECRETS.append(out["SM_BACKTEST_API_TOKEN"])
    for k in ("SM_DATA_API_URL", "SM_BACKTEST_API_TOKEN"):
        if not out.get(k):
            raise PublishRefused(f"{path} has no {k}")
    return out


class DataApi:
    """data_import: symbols, batch, close, abort. Nothing else."""

    def __init__(self, url: str, token: str, timeout: float = 600.0):
        self.url, self._token, self.timeout = url, token, timeout
        config_mod._SECRETS.append(token)

    def call(self, payload: dict) -> dict:
        req = urllib.request.Request(self.url, data=json.dumps(payload, allow_nan=False).encode(), method="POST",
                                     headers={"Content-Type": "application/json",
                                              "Authorization": f"Bearer {self._token}"})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            try:
                body = json.loads(e.read().decode())
            except Exception:
                body = {"ok": False, "why": f"HTTP {e.code}"}
            body.setdefault("ok", False)
            return body

    def must(self, payload: dict) -> dict:
        out = self.call(payload)
        if not out.get("ok"):
            raise PublishRefused(f"data_import refused {payload.get('action')}: {mask(out.get('why'))}")
        return out


# ---- the checks before anything is sent

def fetch_marker(cfg) -> Path:
    return cfg.p("logs") / "fetch_last.json"


def check_ready(cfg, market, store) -> int:
    """The data version to publish, or PublishRefused naming what is not ready."""
    from .indicator_job import job_running
    if job_running(cfg):
        raise PublishRefused("the indicator job is running")
    mk = fetch_marker(cfg)
    if not mk.exists():
        raise PublishRefused(f"no record of a finished fetch ({mk}); run `research fetch`")
    marker = json.loads(mk.read_text())
    if not marker.get("ok"):
        raise PublishRefused(f"the last fetch did not finish (started {marker.get('started')})")
    try:
        version = dv.current(cfg.data_dir)
    except dv.UnrecordedChange as e:
        raise PublishRefused(str(e)) from None
    last_jobs: dict[str, dict] = {}
    jl = cfg.p("logs") / "indicator_job.jsonl"
    if jl.exists():
        for line in jl.read_text().splitlines():
            try:
                r = json.loads(line)
            except ValueError:
                continue
            last_jobs[r.get("indicator")] = r
    last_day = market.dates[-1]
    for name in PUBLISHED:
        k = f"{name}@{BASIS}"
        job = last_jobs.get(k)
        if job is None:
            raise PublishRefused(f"{k}: the indicator job has no record of it")
        if not job.get("ok"):
            raise PublishRefused(f"{k}: the last indicator job failed ({job.get('reason')})")
        if not store.has(k):
            raise PublishRefused(f"{k} is not in the store")
        meta = store.meta(k)
        if int(meta["data_version"]) != version:
            raise PublishRefused(f"{k} was built from data version {meta['data_version']}, not the current "
                                 f"{version}; run `research indicators`")
        if len(meta["dates"]) == 0 or meta["dates"][-1] < last_day:
            raise PublishRefused(f"{k} is built to {meta['dates'][-1].date() if len(meta['dates']) else 'nothing'}, "
                                 f"the prices to {last_day.date()}")
    return version


# ---- what to send

def symbol_map(market, n100_symbols: list[str]) -> tuple[dict[str, str], list[str]]:
    """N100 symbol -> the Mac mini's line, and the N100 symbols with no Mac mini data since 2021."""
    close = market.close
    have = close.loc[close.index >= FULL_FROM]
    by_n100: dict[str, str] = {}
    for fmp in close.columns:
        n = n100_symbol_key(fmp)
        if n not in n100_symbols or not have[fmp].notna().any():
            continue
        old = by_n100.get(n)
        # two lines for one N100 symbol: the one priced most recently
        if old is None or have[fmp].last_valid_index() > have[old].last_valid_index():
            by_n100[n] = fmp
    missing = sorted(set(n100_symbols) - set(by_n100))
    return by_n100, missing


def replace_from(cfg, mapping: dict[str, str], held_version, held_latest, full: bool) -> dict[str, pd.Timestamp]:
    """Each N100 symbol -> the first date whose rows are sent (and replaced on the N100)."""
    if full or held_version is None or held_latest is None:
        return {n: FULL_FROM for n in mapping}
    start = pd.Timestamp(held_latest) + pd.Timedelta(days=1)
    out = {n: start for n in mapping}
    ch = dv.changes_since(cfg.data_dir, int(held_version))
    ch = ch[ch["panel"].isin(PRICE_PANELS)]
    if not len(ch):
        return out
    if (ch["symbol"] == dv.EVERYTHING).any() or ch["panel"].isin({dv.EVERYTHING, "fx"}).any():
        first = pd.to_datetime(ch["from"]).min()
        first = FULL_FROM if pd.isna(first) or ch["panel"].eq(dv.EVERYTHING).any() else max(first, FULL_FROM)
        return {n: min(out[n], first) for n in mapping}
    back = {fmp: n for n, fmp in mapping.items()}
    for _, r in ch.iterrows():
        n = back.get(r["symbol"])
        if n is None:
            continue
        f = FULL_FROM if r["panel"] == "master" or pd.isna(r["from"]) else max(pd.Timestamp(r["from"]), FULL_FROM)
        out[n] = min(out[n], f)
    return out


def rows_to_send(market, store, mapping: dict[str, str], starts: dict[str, pd.Timestamp]):
    """(prices, statistics): lists of [symbol, date, price] and [symbol, date, indicator, value], trading days only."""
    from .store import to_pounds_factor
    if not starts:
        return [], []
    first = min(starts.values())
    days = market.dates[(market.dates >= first) & (market.dates.day_of_week < 5)]
    if not len(days):
        return [], []
    fmps = list(mapping.values())
    factor = to_pounds_factor(market.master.loc[fmps], market.fx, days)
    start_of = pd.Series({fmp: starts[n] for n, fmp in mapping.items()})
    keep = pd.DataFrame(days.to_numpy()[:, None] >= start_of[fmps].to_numpy()[None, :], index=days, columns=fmps)
    n100_of = {fmp: n for n, fmp in mapping.items()}

    def long(frame: pd.DataFrame, decimals: int) -> pd.DataFrame:
        f = frame.reindex(index=days, columns=fmps).where(keep)
        s = f.stack().dropna()
        s = s[np.isfinite(s.to_numpy(dtype=float))]
        out = s.round(decimals).rename("value").reset_index()
        out.columns = ["date", "fmp", "value"]
        out["symbol"] = out["fmp"].map(n100_of)
        out["date"] = pd.to_datetime(out["date"]).dt.strftime("%Y-%m-%d")
        return out

    p = long(market.close / factor, 4)
    p = p[p["value"] > 0]
    prices = [[s, d, float(v)] for s, d, v in zip(p["symbol"], p["date"], p["value"])]
    stats = []
    for name in PUBLISHED:
        vals = store.read(f"{name}@{BASIS}", days).frame
        if name in IN_POUNDS:
            vals = vals.reindex(index=days, columns=fmps) / factor
        st = long(vals, 6)
        stats.extend([s, d, name, float(v)] for s, d, v in zip(st["symbol"], st["date"], st["value"]))
    return prices, stats


def batches(prices: list, stats: list, size: int = BATCH_ROWS):
    """Yield (prices, statistics) in batches of at most `size` rows in all."""
    rows = [(0, r) for r in prices] + [(1, r) for r in stats]
    for k in range(0, len(rows), size):
        chunk = rows[k:k + size]
        yield [r for t, r in chunk if t == 0], [r for t, r in chunk if t == 1]


# ---- the run

def publish(cfg, *, full: bool = False, dry_run: bool = False, api: DataApi | None = None,
            market=None, batch_rows: int = BATCH_ROWS) -> dict:
    from .indicator_store import IndicatorStore
    from .store import load_market
    store = IndicatorStore.for_config(cfg)
    if market is None:
        market = load_market(cfg.data_dir)
    version = check_ready(cfg, market, store)
    if api is None:
        s = read_settings()
        api = DataApi(s["SM_DATA_API_URL"], s["SM_BACKTEST_API_TOKEN"])
    held = api.must({"action": "symbols"})
    n100 = [str(x) for x in held.get("symbols") or []]
    mapping, missing = symbol_map(market, n100)
    starts = replace_from(cfg, mapping, held.get("data_version"), held.get("latest_date"), full)
    prices, stats = rows_to_send(market, store, mapping, starts)
    sent = {r[0] for r in prices} | {r[0] for r in stats}
    # a symbol with nothing new and no change is left as it is on the N100
    held_latest = pd.Timestamp(held["latest_date"]) if held.get("latest_date") else None
    ranges = {n: str(d.date()) for n, d in starts.items()
              if full or n in sent or held_latest is None or d <= held_latest}
    pid = f"{dt.datetime.now():%Y%m%d-%H%M%S}-{uuid.uuid4().hex[:8]}"
    summary = {"publish_id": pid, "data_version": version, "held_version": held.get("data_version"),
               "held_latest": held.get("latest_date"), "full": bool(full), "prices": len(prices),
               "statistics": len(stats), "symbols": len(ranges), "missing": missing,
               "first_date": min(ranges.values()) if ranges else None,
               "last_date": str(market.dates[-1].date())}
    if missing:
        log.warning("N100 symbols with no Mac mini data, not sent: %s", ", ".join(missing))
    if dry_run:
        summary["dry_run"] = True
        _log(cfg, summary)
        return summary
    seq = 0
    try:
        for bp, bs in batches(prices, stats, batch_rows):
            api.must({"action": "batch", "publish_id": pid, "seq": seq, "prices": bp, "statistics": bs})
            seq += 1
        close = {"action": "close", "publish_id": pid, "data_version": version, "full": bool(full),
                 "replace_from": ranges, "batches": seq, "price_rows": len(prices),
                 "statistic_rows": len(stats), "missing": missing}
        try:
            out = api.call(close)
        except Exception as e:          # a timeout: the N100 finishes the close regardless
            out = {"ok": False, "why": f"no answer to the close ({e})"}
        if not out.get("ok"):
            now = api.call({"action": "symbols"})
            if now.get("ok") and now.get("publish_id") == pid:
                out = {"ok": True, "latest_date": now.get("latest_date"),
                       "note": "the close went through though its answer was lost; no difference report"}
            else:
                raise PublishRefused(f"data_import refused close: {mask(out.get('why'))}")
    except BaseException as e:
        try:
            api.call({"action": "abort", "publish_id": pid, "why": mask(str(e))[:500]})
        except Exception:
            pass
        summary.update({"ok": False, "why": mask(str(e)), "batches_sent": seq})
        _log(cfg, summary)
        raise
    summary.update({"ok": True, "batches": seq, "latest_date": out.get("latest_date"),
                    "prices_replaced": out.get("prices_replaced"), "statistics_replaced": out.get("statistics_replaced")})
    summary["report"] = str(write_report(cfg, summary, out.get("differences") or {}))
    _log(cfg, summary)
    return summary


def _log(cfg, summary: dict) -> None:
    p = cfg.p("logs") / "publish.jsonl"
    p.parent.mkdir(parents=True, exist_ok=True)
    with p.open("a") as fh:
        fh.write(mask(json.dumps({"time": dt.datetime.now().isoformat(timespec="seconds"), **summary}, default=str)) + "\n")
    log.info("publish %s", mask(json.dumps({k: v for k, v in summary.items() if k != "missing"}, default=str)))


def write_report(cfg, summary: dict, differences: dict) -> Path:
    """For Dale: rows replaced, symbols missing, and the largest differences against the old values."""
    lines = [f"# Publish {summary['publish_id']}", "",
             f"Data version {summary['data_version']}{' (full, since 2021)' if summary['full'] else ''}; "
             f"latest date {summary.get('latest_date')}.", "",
             f"- Prices sent: {summary['prices']:,}, replacing {summary.get('prices_replaced') or 0:,} on the N100",
             f"- Statistics sent: {summary['statistics']:,}, replacing {summary.get('statistics_replaced') or 0:,}",
             f"- Symbols: {summary['symbols']}",
             f"- N100 symbols with no Mac mini data ({len(summary['missing'])}): "
             f"{', '.join(summary['missing']) or 'none'}", "",
             "## Against the old values", "",
             "Rows on both sides; 'differing' is more than 0.5% apart.", ""]
    for what, d in differences.items():
        lines.append(f"### {what}: {d.get('compared', 0):,} compared, {d.get('differing', 0):,} differing")
        top = d.get("largest") or []
        if top:
            lines += ["", "| symbol | date | old | new | relative difference |", "|---|---|---|---|---|"]
            lines += [f"| {r['symbol']} | {r['date']} | {r['old_value']} | {r['new_value']} | {r['rel_diff']} |" for r in top]
        lines.append("")
    out = cfg.p("reports") / f"publish-{summary['publish_id']}.md"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text("\n".join(lines))
    (out.with_suffix(".json")).write_text(json.dumps({**summary, "differences": differences}, indent=1, default=str))
    return out


def main(cfg, full: bool = False, dry_run: bool = False) -> dict:
    s = publish(cfg, full=full, dry_run=dry_run)
    print(f"publish {s['publish_id']}: data version {s['data_version']}, {s['prices']:,} prices and "
          f"{s['statistics']:,} statistics for {s['symbols']} symbols from {s['first_date']}"
          + (" (dry run, nothing sent)" if dry_run else f", closed; report {s.get('report')}"))
    if s["missing"]:
        print(f"N100 symbols with no Mac mini data: {', '.join(s['missing'])}")
    return s
