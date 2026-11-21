"""data_refresh (contracts/modules.yaml), the part that keeps the data version.

The fetch, clean and universe steps write the data. After each of them,
record() compares the data as the engine and the indicators see it (the
loaded market: raw and adjusted closes after bad ticks and the pence fix,
market caps after every cap fix and override, statements, FX, the symbol
master and the FTSE 350-like membership) with a snapshot of the last
recorded version, and writes down which companies and which months changed:
overrides, cap fixes, companies entering or leaving the universe, restated
statements, corrected prices, new days. A change bumps the version. A full
re-fetch (or a first record) is a change to everything.

Files under data/:

    data_version.json     {version, time, files}: the version now, and the size and time of each data
                          file when it was recorded
    data_changes.parquet  version, time, reason, panel, symbol, from, to: one row per company and panel
                          changed ('*' for everything); from/to are month bounds, a statement's month is
                          its period end, a master change has no dates (the whole history)
    data_snapshot.parquet panel, symbol, month, hash: what the next record compares with

current() refuses if a data file has changed since the last record without
being recorded, so nothing changes a stored value unrecorded and unnoticed.
"""
from __future__ import annotations

import datetime as dt
import json
import logging
from pathlib import Path

import numpy as np
import pandas as pd

log = logging.getLogger(__name__)

STATE = "data_version.json"
CHANGES = "data_changes.parquet"
SNAPSHOT = "data_snapshot.parquet"
FILES = ["symbols.parquet", "prices.parquet", "market_cap.parquet", "statements.parquet", "fx.parquet",
         "dividends.parquet",
         "bad_ticks.parquet", "cap_lse_corrections.parquet", "universe.parquet",
         # the N100's extra symbols (extra.py): their own panel, read by nothing in research
         "n100_extra/symbols.parquet", "n100_extra/prices.parquet", "n100_extra/dividends.parquet",
         "n100_extra/bad_ticks.parquet"]
EVERYTHING = "*"
# Which inputs of an indicator (indicators/base.py USES) each panel is.
PANEL_INPUT = {"close": "prices", "adj": "prices", "market_cap": "caps", "statements": "statements",
               "dividends": "dividends",
               "fx": "fx", "universe": "universe", "master": "master", EVERYTHING: EVERYTHING,
               "extra_close": "extra_prices"}


class UnrecordedChange(RuntimeError):
    pass


def fingerprint(data_dir: Path) -> dict:
    out = {}
    for f in FILES:
        p = Path(data_dir) / f
        if p.exists():
            st = p.stat()
            out[f] = [st.st_size, st.st_mtime_ns]
    return out


def state(data_dir: Path) -> dict | None:
    p = Path(data_dir) / STATE
    return json.loads(p.read_text()) if p.exists() else None


def current(data_dir: Path) -> int:
    """The data version now. Refused if a data file changed after the last record."""
    st = state(data_dir)
    if st is None:
        raise UnrecordedChange("the data has no version yet; run `research data-version`")
    now = fingerprint(data_dir)
    if now != st.get("files", {}):
        changed = sorted(k for k in set(now) | set(st.get("files", {})) if now.get(k) != st["files"].get(k))
        raise UnrecordedChange(f"data files changed since data version {st['version']} was recorded "
                               f"({', '.join(changed)}); run `research data-version`")
    return int(st["version"])


def changes_since(data_dir: Path, version: int) -> pd.DataFrame:
    p = Path(data_dir) / CHANGES
    if not p.exists():
        return pd.DataFrame(columns=["version", "time", "reason", "panel", "symbol", "from", "to"])
    c = pd.read_parquet(p)
    return c[c["version"] > int(version)].reset_index(drop=True)


# ---- the snapshot
# Hashes are kept per company and month, and per company and DAY from the first day of the month before
# the latest date: a nightly top-up then changes only its new day, and the job only adds that day. A
# month's hash is the sum of its days' hashes, so a day-level stretch folds into months exactly.
def _months(index: pd.DatetimeIndex) -> np.ndarray:
    return index.to_period("M").to_timestamp().to_numpy()


def daily_from(dates: pd.DatetimeIndex) -> pd.Timestamp:
    if len(dates) == 0:
        return pd.Timestamp.max
    return (pd.Timestamp(dates[-1]).to_period("M") - 1).to_timestamp()


def _group_sum(keys: np.ndarray, h: np.ndarray, seen: np.ndarray):
    """Sum hashes (wrapping, uint64) over runs of equal keys along axis 0; keys are sorted."""
    starts = np.r_[0, np.flatnonzero(keys[1:] != keys[:-1]) + 1]
    return keys[starts], np.add.reduceat(h, starts, axis=0), np.add.reduceat(seen, starts, axis=0) > 0


def panel_hashes(frame: pd.DataFrame, panel: str, cutoff: pd.Timestamp | None = None) -> pd.DataFrame:
    """One hash per symbol and period of a date x symbol frame: a month before `cutoff`, a day from it.
    A period with no values has none."""
    if frame is None or frame.empty:
        return pd.DataFrame(columns=["panel", "symbol", "month", "hash"])
    frame = frame.sort_index()
    cutoff = daily_from(frame.index) if cutoff is None else cutoff
    a = frame.to_numpy(dtype=float)
    h = pd.util.hash_array(a.ravel()).reshape(a.shape)
    day = pd.util.hash_array(frame.index.asi8) | np.uint64(1)
    h = np.where(np.isnan(a), np.uint64(0), h * day[:, None])
    days = frame.index.to_numpy()
    months = _months(frame.index)
    period = np.where(days >= np.datetime64(cutoff), days, months)
    keys, sums, seen = _group_sum(period, h, (~np.isnan(a)).astype(np.int64))
    mi, si = np.nonzero(seen)
    return pd.DataFrame({"panel": panel, "symbol": np.asarray(frame.columns)[si].astype(str),
                         "month": keys[mi], "hash": sums[mi, si]})


def _row_hashes(df: pd.DataFrame, panel: str, month_col: str | None) -> pd.DataFrame:
    if df is None or df.empty:
        return pd.DataFrame(columns=["panel", "symbol", "month", "hash"])
    d = df.copy()
    d["month"] = (pd.to_datetime(d[month_col]).dt.to_period("M").dt.to_timestamp() if month_col
                  else pd.NaT)
    cols = sorted(c for c in d.columns if c not in ("month",))
    h = pd.util.hash_pandas_object(d[cols].astype(str), index=False).to_numpy()
    d = d.assign(_h=h).sort_values(["symbol", "month"], na_position="first")
    out = []
    for (sym, mon), g in d.groupby(["symbol", "month"], dropna=False, sort=False):
        out.append((panel, str(sym), mon, np.bitwise_xor.reduce(np.sort(g["_h"].to_numpy()) *
                                                                np.arange(1, len(g) + 1, dtype=np.uint64))))
    return pd.DataFrame(out, columns=["panel", "symbol", "month", "hash"])


def snapshot(m, member: pd.DataFrame | None, extra=None) -> pd.DataFrame:
    """The data as the engine and indicators see it, as hashes per panel, symbol and month (day, lately)."""
    cut = daily_from(m.dates)
    parts = [panel_hashes(m.close, "close", cut), panel_hashes(m.adj, "adj", cut),
             panel_hashes(m.market_cap, "market_cap", cut)]
    if m.fx is not None and not m.fx.empty:
        parts.append(panel_hashes(m.fx, "fx", cut))
    if member is not None and not member.empty:
        parts.append(panel_hashes(member.astype(float).where(member.astype(bool)), "universe", cut))
    st = m.statements
    if st is not None and not st.empty and "symbol" in st:
        end = "period_end" if "period_end" in st else ("date" if "date" in st else None)
        parts.append(_row_hashes(st, "statements", end))
    # Dividends: a change to any (amount, dates, kind) changes the data version. The engine credits them.
    dv = getattr(m, "dividends", None)
    if dv is not None and not dv.empty:
        parts.append(_row_hashes(dv, "dividends", "ex_date"))
    # Of the master, only what an indicator reads (the sector, for value_composite).
    if "sector" in m.master:
        master = m.master[["sector"]].rename_axis("symbol").reset_index()
        parts.append(_row_hashes(master, "master", None))
    # The N100's extra symbols (extra.py): their raw closes, as a panel of their own.
    if extra is not None and not extra.close.empty:
        parts.append(panel_hashes(extra.close, "extra_close", cut))
    snap = pd.concat([p for p in parts if len(p)], ignore_index=True)
    snap["hash"] = snap["hash"].astype(np.uint64)
    return snap


def _fold(snap: pd.DataFrame, cutoff) -> pd.DataFrame:
    """Day-level hashes before `cutoff` summed into their month, so two snapshots compare like for like.
    Indexed by a text key; columns panel, symbol, month, hash."""
    d = snap.assign(month=pd.to_datetime(snap["month"]))
    dated = d["month"].notna() & (d["month"] < cutoff)
    d.loc[dated, "month"] = d.loc[dated, "month"].dt.to_period("M").dt.to_timestamp()
    if d.empty:
        return pd.DataFrame(columns=["panel", "symbol", "month", "hash"])
    month = d["month"].dt.strftime("%Y-%m-%d").fillna("whole history")
    d["key"] = d["panel"].astype(str) + "|" + d["symbol"].astype(str) + "|" + month.astype(str)
    d = d.sort_values("key", kind="mergesort").reset_index(drop=True)
    k = d["key"].to_numpy()
    starts = np.r_[0, np.flatnonzero(k[1:] != k[:-1]) + 1]
    out = d.iloc[starts][["key", "panel", "symbol", "month"]].copy()
    out["hash"] = np.add.reduceat(d["hash"].to_numpy(dtype=np.uint64), starts)
    return out.set_index("key")


def diff(old: pd.DataFrame, new: pd.DataFrame, cutoff) -> pd.DataFrame:
    """panel, symbol, from, to for every symbol whose hashes differ in some month (or day, from `cutoff`:
    the later of the two snapshots' first day-level date)."""
    cutoff = pd.Timestamp(cutoff)
    o, n = _fold(old, cutoff), _fold(new, cutoff)
    j = n[["panel", "symbol", "month"]].combine_first(o[["panel", "symbol", "month"]])
    j["old"], j["new"] = o["hash"].reindex(j.index), n["hash"].reindex(j.index)
    changed = j[~((j["old"] == j["new"]) & j["old"].notna() & j["new"].notna())].copy()
    if changed.empty:
        return pd.DataFrame(columns=["panel", "symbol", "from", "to"])
    m = pd.to_datetime(changed["month"])
    changed["start"] = m
    changed["end"] = m.where(m >= cutoff, m + pd.offsets.MonthEnd(0))
    g = changed.groupby(["panel", "symbol"], dropna=False)
    return pd.DataFrame({"from": g["start"].min(), "to": g["end"].max()}).reset_index()


# ---- recording
def record(cfg, reason: str, market=None, member: pd.DataFrame | None = None, full: bool = False) -> dict:
    """Compare the data with the last recorded snapshot; write down what changed and bump the version.

    `market`/`member` are the loaded data (loaded from cfg.data_dir when not given). `full` records a
    change to everything (a full re-fetch). Returns {version, changed, rows}.
    """
    data = Path(cfg.data_dir)
    data.mkdir(parents=True, exist_ok=True)
    if market is None:
        from .store import load_market
        market = load_market(data)
    if member is None and (data / "universe.parquet").exists():
        from .universe import membership
        member, _ = membership(pd.read_parquet(data / "universe.parquet"), market.dates)
    from .extra import load as load_extra
    new = snapshot(market, member, load_extra(cfg))
    snap_path = data / SNAPSHOT
    st = state(data)
    version = int(st["version"]) if st else 0
    if full or st is None or not snap_path.exists():
        ch = pd.DataFrame({"panel": [EVERYTHING], "symbol": [EVERYTHING], "from": [pd.NaT], "to": [pd.NaT]})
    else:
        ch = diff(pd.read_parquet(snap_path), new,
                  max(pd.Timestamp(st.get("daily_from") or daily_from(market.dates)), daily_from(market.dates)))
    now = dt.datetime.now().isoformat(timespec="seconds")
    if len(ch):
        version += 1
        ch = ch.assign(version=version, time=now, reason=reason)[
            ["version", "time", "reason", "panel", "symbol", "from", "to"]]
        p = data / CHANGES
        allc = pd.concat([pd.read_parquet(p), ch], ignore_index=True) if p.exists() else ch
        allc["from"] = pd.to_datetime(allc["from"])
        allc["to"] = pd.to_datetime(allc["to"])
        _atomic_parquet(allc, p)
        _atomic_parquet(new, snap_path)
        log.info("data version %d (%s): %d company/panel changes", version, reason, len(ch))
    (data / STATE).write_text(json.dumps({"version": version, "time": now if len(ch) or st is None else st["time"],
                                          "reason": reason if len(ch) else (st or {}).get("reason"),
                                          "daily_from": str(daily_from(market.dates).date()),
                                          "files": fingerprint(data)}, indent=1))
    return {"version": version, "changed": int(len(ch)), "rows": ch}


def _atomic_parquet(df: pd.DataFrame, path: Path) -> None:
    tmp = path.with_suffix(".tmp")
    df.to_parquet(tmp, index=False)
    tmp.replace(path)
