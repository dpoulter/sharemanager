"""The FTSE 350-like universe, rebuilt on the first trading day of each month.

The 350 largest companies by market cap on that day, among those the symbol
master keeps (ordinary shares of companies), main market only unless AIM is
switched on. A company that grows into the top 350 joins at the next monthly
rebuild, not before. There is no universe before market cap history starts
(2007), and nothing may be run before it.
"""
from __future__ import annotations

import re
from pathlib import Path

import numpy as np
import pandas as pd

from .store import Market


def rebuild_dates(dates: pd.DatetimeIndex, start) -> pd.DatetimeIndex:
    d = dates[dates >= pd.Timestamp(start)]
    if not len(d):
        return d
    s = pd.Series(d, index=d)
    return pd.DatetimeIndex(s.groupby([d.year, d.month]).first().values)


def build_universe(m: Market, size: int = 350, large: int = 100, start="2007-01-01",
                   include_aim: bool = False, stale_days: int = 10,
                   eligible: pd.DataFrame | None = None, trust_caps: pd.DataFrame | None = None) -> pd.DataFrame:
    """date, symbol, rank, tier ('large' top 100 or 'mid' the rest), market_cap, is_aim, basis.

    `eligible` is month x symbol from the LSE issuer lists (lse.build_status):
    Main Market, and home-incorporated or premium-listed. A symbol with no
    LSE status that month falls back to the ISIN rule and the AIM flag.
    """
    from .lse import isin_fallback
    master = m.master
    ok = master.get("included", pd.Series(True, index=master.index)).fillna(False).astype(bool)
    aim = master.get("is_aim", pd.Series(False, index=master.index)).fillna(False).astype(bool)
    fallback = isin_fallback(master, include_aim)
    if eligible is None and not include_aim:
        ok &= ~aim
    cols = [c for c in m.close.columns if ok.get(c, False)]
    norm = master.get("norm", pd.Series(index=master.index, dtype=object)).reindex(cols)
    trusts_counted, trusts_top = {}, {}
    trust_norm = pd.Series(dtype=object)
    if trust_caps is not None:
        from .lse import norm_name
        trust_norm = master.get("name", pd.Series(index=master.index, dtype=object)) \
            .reindex(trust_caps.columns).map(norm_name).replace("", np.nan)
    if m.volume is not None:
        traded = (m.close[cols] * m.volume.reindex(columns=cols)).rolling(63, min_periods=5).mean()
    else:
        traded = None
    close = m.close[cols]
    cap = m.market_cap.reindex(columns=cols).ffill(limit=stale_days)
    priced = close.notna() | close.ffill(limit=5).notna()
    rows = []
    for d in rebuild_dates(m.dates, start):
        c = cap.loc[d].where(priced.loc[d])
        c = c[c > 0].dropna()
        basis = pd.Series("no LSE lists", index=c.index)
        if eligible is not None:
            month = pd.Timestamp(d.year, d.month, 1)
            e = eligible.loc[month].reindex(c.index) if month in eligible.index else pd.Series(np.nan, index=c.index)
            fb = fallback.reindex(c.index).fillna(True)
            keep = e.where(e.notna(), fb).astype(bool)
            basis = pd.Series(np.where(e.notna(), "LSE list", "ISIN fallback"), index=c.index)
            c = c[keep]
        # One line per company name on this date: the one trading now, then the
        # higher traded value over the last three months, then the larger cap.
        if norm.notna().any():
            names = norm.reindex(c.index)
            dup = names.notna() & (names != "") & names.duplicated(keep=False)
            if dup.any():
                tv = traded.loc[d].reindex(c.index).fillna(0) if traded is not None else pd.Series(0.0, index=c.index)
                g = pd.DataFrame({"name": names[dup], "tv": tv[dup], "cap": c[dup]})
                keep_line = g.sort_values(["tv", "cap"], ascending=False).groupby("name").head(1).index
                c = c.drop(g.index.difference(keep_line))
        if trust_caps is not None and len(trust_caps.columns):
            # Count the trusts in the top `size`, then leave them out.
            t = trust_caps.loc[:d].iloc[-stale_days:].ffill().iloc[-1] if d >= trust_caps.index[0] else pd.Series(dtype=float)
            t = t[t > 0].dropna().sort_values(ascending=False)
            if eligible is not None:
                # Main Market only, from the LSE lists: a trust the lists do not
                # place is not counted (open-ended funds are not on them at all).
                month = pd.Timestamp(d.year, d.month, 1)
                te = eligible.loc[month].reindex(t.index) if month in eligible.index else pd.Series(np.nan, index=t.index)
                t = t[te.fillna(False).astype(bool)]
            # One line per issuer: the largest.
            tn = trust_norm.reindex(t.index)
            if tn.notna().any():
                t = t.sort_values(ascending=False)
                t = t[~(tn.reindex(t.index).notna() & tn.reindex(t.index).duplicated())]
            both = pd.concat([c.rename("cap"), t.rename("cap")]).sort_values(ascending=False).head(size)
            n_trusts = int(both.index.isin(t.index).sum())
            trusts_counted[d] = n_trusts
            trusts_top[d] = list(t.index[t.index.isin(both.index)][:20])
            c = c[c.index.isin(both.index)]
        c = c.sort_values(ascending=False).head(size)
        for r, (sym, v) in enumerate(c.items(), 1):
            rows.append((d, sym, r, "large" if r <= large else "mid", float(v), bool(aim.get(sym, False)),
                         basis.get(sym)))
    out = pd.DataFrame(rows, columns=["date", "symbol", "rank", "tier", "market_cap", "is_aim", "basis"])
    out.attrs["trusts_counted"] = trusts_counted
    out.attrs["trusts_top"] = trusts_top
    return out


OPEN_ENDED = re.compile(r"\b(acc|inc|accumulation|income units?|class [a-z0-9]+|oeic|sicav|ucits|etf|etc|"
                        r"exchange traded|tracker|hedged|dist|distributing|units?)\b", re.I)


def trust_candidates(master: pd.DataFrame) -> list[str]:
    """Closed-ended investment companies and REIT-like trusts the FTSE 350 counts.

    Out: Morningstar 0P... codes (open-ended fund share classes), ETFs and
    ETCs, open-ended share classes by name (Acc, Inc, Class B, UCITS, OEIC),
    and overseas ISINs. Whether a candidate is on the Main Market is decided
    month by month from the LSE lists in build_universe.
    """
    from .fetch import TRUST_REASONS
    reason = master.get("exclude_reason", pd.Series(index=master.index, dtype=object))
    names = master.get("name", pd.Series(index=master.index, dtype=object)).fillna("").astype(str)
    etf = master.get("is_etf", pd.Series(False, index=master.index)).fillna(False).astype(bool)
    over = master.get("overseas_isin", pd.Series(False, index=master.index)).fillna(False).astype(bool)
    sel = reason.isin(TRUST_REASONS) & ~etf & ~over
    sel &= ~pd.Series(master.index.astype(str).str.upper().str.startswith("0P"), index=master.index)
    sel &= ~names.str.contains(OPEN_ENDED)
    return list(master.index[sel])


def load_trust_caps(data: Path, m: Market) -> pd.DataFrame | None:
    """date x symbol market caps (pounds) of the trust candidates (see trust_candidates)."""
    p = Path(data) / "market_cap.parquet"
    if not p.exists():
        return None
    trusts = trust_candidates(m.master)
    caps = pd.read_parquet(p)
    caps = caps[caps["symbol"].isin(trusts)]
    if caps.empty:
        return None
    caps["date"] = pd.to_datetime(caps["date"])
    w = caps.drop_duplicates(["symbol", "date"]).pivot(index="date", columns="symbol", values="market_cap").sort_index()
    return w.reindex(m.dates.union(w.index)).ffill(limit=10).reindex(m.dates)


def fixed_universe(m: Market, symbols: list[str], start, large: int = 100) -> pd.DataFrame:
    """A fixed list (e.g. today's FTSE 350 from the N100) on every rebuild date.

    Tiers still follow market cap where it is known, so costs differ between
    large and mid as they would.
    """
    rows = []
    symbols = [to_fmp(s) for s in symbols]
    symbols = [s for s in symbols if s in m.close.columns]
    cap = m.market_cap.reindex(columns=symbols).ffill()
    for d in rebuild_dates(m.dates, start):
        c = cap.loc[d].fillna(0).sort_values(ascending=False)
        for r, (sym, v) in enumerate(c.items(), 1):
            rows.append((d, sym, r, "large" if r <= large else "mid", float(v), False))
    return pd.DataFrame(rows, columns=["date", "symbol", "rank", "tier", "market_cap", "is_aim"])


def membership(universe: pd.DataFrame, dates: pd.DatetimeIndex) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Daily boolean membership and tier ('large' True) frames, held until the next rebuild."""
    u = universe.copy()
    u["one"] = True
    member = u.pivot(index="date", columns="symbol", values="one").reindex(dates)
    rebuilt = pd.Series(dates.isin(pd.DatetimeIndex(u["date"].unique())), index=dates)
    # Forward fill from each rebuild, and only from rebuilds: a symbol that is
    # absent on a rebuild date must be False from then on, not carried over.
    member = member.where(~rebuilt.to_numpy()[:, None] | member.notna(), False)
    member = member.ffill().fillna(False).astype(bool)
    large = (u.assign(l=u["tier"] == "large").pivot(index="date", columns="symbol", values="l")
             .reindex(dates))
    large = large.where(~rebuilt.to_numpy()[:, None] | large.notna(), False).ffill().fillna(False).astype(bool)
    return member, large


def to_fmp(sym: str) -> str:
    """N100 / LSE code -> FMP's: BT.A -> BT-A.L, VOD -> VOD.L."""
    s = str(sym).strip().upper()
    if s.endswith(".L"):
        s = s[:-2]
    return s.replace(".", "-") + ".L"


def read_symbol_list(path: Path, drop_funds: bool = True) -> pd.DataFrame:
    """A list of symbols from the N100: tab- or comma-separated, with or without is_fund.

    A file ending .tsv, or whose first line has a tab, is read as tab-separated
    (sector names hold commas, so sniffing would guess wrong).
    """
    path = Path(path)
    first = path.read_text(errors="replace").splitlines()[:1]
    sep = "\t" if path.suffix.lower() == ".tsv" or (first and "\t" in first[0]) else ","
    df = pd.read_csv(path, sep=sep, dtype=str)
    df.columns = [c.strip().lower() for c in df.columns]
    col = "symbol" if "symbol" in df.columns else df.columns[0]
    out = pd.DataFrame({"symbol": df[col].map(to_fmp)})
    out["is_fund"] = df["is_fund"].fillna("N").str.strip().str.upper().eq("Y") if "is_fund" in df else False
    if drop_funds:
        out = out[~out["is_fund"]]
    return out.reset_index(drop=True)


def compare_with_live(universe: pd.DataFrame, live_csv: Path) -> dict:
    live_set = set(read_symbol_list(live_csv)["symbol"])
    last = universe[universe["date"] == universe["date"].max()]
    ours = set(last["symbol"])
    return {"date": str(last["date"].max())[:10], "rebuilt": len(ours), "live": len(live_set),
            "both": len(ours & live_set), "only_rebuilt": sorted(ours - live_set),
            "only_live": sorted(live_set - ours),
            "overlap_pct": round(100 * len(ours & live_set) / max(1, len(live_set)), 1)}
