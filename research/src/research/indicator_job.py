"""indicator_job (contracts/modules.yaml): keeps the indicator store up to date.

    uv run research indicators                  run after the nightly data top-up
    uv run research indicators --rebuild NAME   rebuild NAME (every price basis of it) whole

For every indicator definition (indicators/), one stored indicator per price
basis it is built on:

- not in the store, or its definition's version changed: the whole history is
  worked out again and replaces it;
- otherwise the record of data changes (data_version.py) since the data
  version the store was built from is read: the companies and dates affected
  are worked out again (every company, for an indicator ranked across the
  universe or after an FX or universe change; the whole history after a full
  re-fetch), and every new date is added.

When an indicator is done the store is marked with the data version it read.
One indicator failing does not stop the others: each logs the dates added,
the dates and companies rebuilt, the companies covered, the time taken, or
the reason it failed (logs/indicator_job.jsonl, and the summary printed).

It does not run while the backtest worker has a run in hand (its state file
logs/worker_current_run.json), and holds data/indicators/.job.lock while it
runs, which the worker waits on.
"""
from __future__ import annotations

import datetime as dt
import json
import logging
import os
import time
from dataclasses import replace
from pathlib import Path

import numpy as np
import pandas as pd

from . import data_version as dv
from .indicator_store import IndicatorStore

log = logging.getLogger(__name__)


class JobRefused(RuntimeError):
    pass


# ---- the worker and the job keep out of each other's way
def lock_path(cfg) -> Path:
    return IndicatorStore.for_config(cfg).root / ".job.lock"


def worker_state_path(cfg) -> Path:
    return cfg.p("logs") / "worker_current_run.json"


def _alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def job_running(cfg) -> bool:
    p = lock_path(cfg)
    if not p.exists():
        return False
    try:
        pid = int(json.loads(p.read_text()).get("pid", 0))
    except (ValueError, OSError):
        return True
    return pid > 0 and _alive(pid)


def worker_busy(cfg) -> str | None:
    p = worker_state_path(cfg)
    if not p.exists():
        return None
    try:
        st = json.loads(p.read_text())
    except (ValueError, OSError):
        st = {}
    return f"the backtest worker has run {st.get('run_id', '?')} in hand (started {st.get('started', '?')})"


# ---- working values out
def _subset(m, symbols):
    cols = [c for c in m.close.columns if c in set(symbols)]
    st = m.statements
    if st is not None and not st.empty and "symbol" in st:
        st = st[st["symbol"].isin(cols)]
    return replace(m, close=m.close[cols], adj=m.adj[cols], market_cap=m.market_cap.reindex(columns=cols),
                   volume=None if m.volume is None else m.volume.reindex(columns=cols), statements=st,
                   last_price=m.last_price.reindex(cols), gone=m.gone.reindex(cols), _ma200=None)


def compute(cfg, defn, k: str, market, member, dates, symbols=None) -> pd.DataFrame:
    """The definition's values on `dates`, long (date, symbol, value), for `symbols` or every company."""
    from .indicators import Context, Fundamentals, market_on_basis
    m = market_on_basis(market, defn.basis_of(k)) if defn.by_basis else market
    if symbols is not None:
        m = _subset(m, symbols)
    pit = cfg["point_in_time"]
    f = Fundamentals(m.statements, pit["annual_lag_months"], pit["half_year_lag_months"])
    mem = None if member is None else member.reindex(columns=m.close.columns, fill_value=False)
    ctx = Context(m, f, mem)
    parts = []
    for d in pd.DatetimeIndex(dates):
        s = defn.fn(ctx, d)
        s = pd.to_numeric(pd.Series(s), errors="coerce")
        s = s[np.isfinite(s.to_numpy(dtype=float))]
        if symbols is not None:
            s = s[s.index.isin(list(symbols))]
        if len(s):
            parts.append(pd.DataFrame({"date": d, "symbol": s.index.astype(str), "value": s.to_numpy(dtype=float)}))
    if not parts:
        return pd.DataFrame({"date": pd.Series(dtype="datetime64[ns]"), "symbol": pd.Series(dtype=str),
                             "value": pd.Series(dtype=float)})
    return pd.concat(parts, ignore_index=True)


def calendar(cfg, defn, market) -> pd.DatetimeIndex:
    """The dates an indicator is kept for: trading days, or every calendar day, from the universe start."""
    dates = market.dates
    start = max(pd.Timestamp(cfg["universe"]["start"]), dates[0])
    if defn.calendar == "daily":
        return pd.date_range(start, dates[-1], freq="D")
    return dates[dates >= start]


def _scope(defn, changes: pd.DataFrame, first) -> tuple[str, object, pd.Timestamp | None]:
    """('none' | 'all' | 'some', symbols, from date) for the changes an indicator reads."""
    if changes.empty:
        return "none", None, None
    c = changes[changes["panel"].map(dv.PANEL_INPUT).isin(set(defn.uses) | {dv.EVERYTHING})]
    if c.empty:
        return "none", None, None
    if (c["panel"] == dv.EVERYTHING).any():
        return "everything", None, None
    start = pd.to_datetime(c["from"]).min()
    start = first if pd.isna(start) else max(pd.Timestamp(start), first)
    if defn.cross_sectional or c["panel"].isin(["fx", "universe"]).any():
        return "all", None, start
    return "some", sorted(set(c["symbol"].astype(str))), start


def run(cfg, rebuild: list[str] | None = None, names: list[str] | None = None, market=None, universe=None,
        record_reason: str = "indicator job") -> list[dict]:
    """Bring the store up to date. `names` limits it to those definitions; `rebuild` forces those whole."""
    from .indicators import REGISTRY
    from .universe import membership
    store = IndicatorStore.for_config(cfg)
    store.root.mkdir(parents=True, exist_ok=True)
    busy = worker_busy(cfg)
    if busy:
        raise JobRefused(f"not running: {busy}")
    lock = lock_path(cfg)
    if job_running(cfg):
        raise JobRefused(f"another indicator job is running ({lock})")
    lock.write_text(json.dumps({"pid": os.getpid(), "started": dt.datetime.now().isoformat(timespec="seconds")}))
    try:
        busy = worker_busy(cfg)           # the worker may have claimed between the check and the lock
        if busy:
            raise JobRefused(f"not running: {busy}")
        if market is None:
            from .store import load_market
            market = load_market(cfg.data_dir)
        if universe is None:
            up = cfg.data_dir / "universe.parquet"
            if not up.exists():
                raise JobRefused("no universe yet: run `research universe` first")
            universe = pd.read_parquet(up)
        member, _ = membership(universe, market.dates)
        member = member.reindex(columns=market.close.columns, fill_value=False)
        rec = dv.record(cfg, record_reason, market=market, member=member)
        version_now = rec["version"]
        unknown = sorted(set(names or []) - set(REGISTRY))
        if unknown:
            raise JobRefused(f"no definition named {', '.join(unknown)}")
        defs = [REGISTRY[n] for n in (names or sorted(REGISTRY))]
        forced = set(rebuild or [])
        known_keys = {k for d in REGISTRY.values() for k in d.keys()}
        bad = forced - set(REGISTRY) - known_keys
        if bad:
            raise JobRefused(f"--rebuild {', '.join(sorted(bad))}: no such indicator")
        reports = []
        last = market.dates[-1]
        for defn in defs:
            for k in defn.keys():
                t0 = time.time()
                r = {"indicator": k, "time": dt.datetime.now().isoformat(timespec="seconds")}
                try:
                    r.update(_one(cfg, store, defn, k, market, member, version_now, last,
                                  force=(defn.name in forced or k in forced)))
                    r["ok"] = True
                except Exception as e:       # one failing does not stop the others
                    r.update({"ok": False, "reason": f"{type(e).__name__}: {e}"})
                    log.exception("indicator %s failed", k)
                r["seconds"] = round(time.time() - t0, 2)
                log.info("indicator %s: %s", k, json.dumps({x: y for x, y in r.items() if x != "indicator"},
                                                             default=str))
                reports.append(r)
        _log(cfg, reports)
        return reports
    finally:
        lock.unlink(missing_ok=True)


def _one(cfg, store: IndicatorStore, defn, k: str, market, member, version_now: int, last, force: bool) -> dict:
    v = defn.version(cfg, k)
    cal = calendar(cfg, defn, market)
    info = {"name": defn.name, "basis": defn.basis_of(k) if defn.by_basis else None, "unit": defn.unit,
            "uses": sorted(defn.uses), "cross_sectional": defn.cross_sectional, "source": defn.source}
    whole = force or not store.has(k)
    why = "rebuild asked for" if force else "not in the store" if whole else None
    if not whole:
        meta = store.meta(k)
        if meta["version"] != v:
            whole, why = True, f"definition changed ({meta['version']} -> {v})"
    if not whole:
        changes = dv.changes_since(cfg.data_dir, meta["data_version"])
        how, symbols, start = _scope(defn, changes, cal[0])
        if how == "everything":
            whole, why = True, "the data changed everywhere (a full re-fetch)"
    if whole:
        rows = compute(cfg, defn, k, market, member, cal)
        store.write(k, rows, version=v, data_version=version_now, calendar=defn.calendar, dates=cal,
                    data_last_date=last, info=info)
        return {"action": "rebuilt whole", "why": why, "version": v, "data_version": version_now,
                "dates_added": len(cal), "companies_covered": int(rows["symbol"].nunique()) if len(rows) else 0}
    out = {"action": "updated", "version": v, "data_version": version_now, "dates_added": 0,
           "dates_rebuilt": 0, "companies_rebuilt": 0}
    built = meta["dates"]
    if how in ("some", "all"):
        redo = cal[(cal >= start) & (cal <= built[-1])]
        if len(redo):
            rows = compute(cfg, defn, k, market, member, redo, symbols=symbols)
            store.update(k, rows, version=v, data_version=None, dates=redo, symbols=symbols, data_last_date=last)
            out.update({"dates_rebuilt": len(redo), "rebuilt_from": str(redo[0].date()),
                        "companies_rebuilt": "all" if symbols is None else len(symbols)})
            if symbols is not None and len(symbols) <= 20:
                out["rebuilt_symbols"] = symbols
    new = cal[cal > built[-1]] if len(built) else cal
    if len(new):
        rows = compute(cfg, defn, k, market, member, new)
        store.update(k, rows, version=v, data_version=None, dates=new, data_last_date=last)
        out["dates_added"] = len(new)
    store.mark(k, version_now)
    latest = store.read(k, [cal[-1]]).frame if len(cal) else pd.DataFrame()
    out["companies_covered"] = int(latest.notna().sum(axis=1).iloc[0]) if len(latest) else 0
    return out


def _log(cfg, reports: list[dict]) -> None:
    p = cfg.p("logs") / "indicator_job.jsonl"
    p.parent.mkdir(parents=True, exist_ok=True)
    with p.open("a") as fh:
        for r in reports:
            fh.write(json.dumps(r, default=str) + "\n")


# ---- what the engine's callers hand it
def versions(cfg) -> dict[str, str]:
    """Every stored indicator's key -> its definition's version now (the engine refuses older ones)."""
    from .indicators import versions as v
    return v(cfg)


def workspace(cfg, market=None, universe=None):
    """The engine's Workspace, told the definition versions so it can refuse stale indicators."""
    from .strategy import Workspace
    return Workspace(cfg, market=market, universe=universe, versions=versions(cfg))


def definition(k: str):
    """The definition behind a stored indicator key."""
    from .indicators import definitions_by_key
    return definitions_by_key()[k]
