"""Checks after each pull, written to data_report.md in the data folder."""
from __future__ import annotations

import datetime as dt
from pathlib import Path

import numpy as np
import pandas as pd

from .store import Market


def price_gaps(m: Market, min_sessions: int = 10) -> pd.DataFrame:
    """Gaps over `min_sessions` trading days inside a company's listed life."""
    rows = []
    for sym in m.close.columns:
        s = m.close[sym]
        first, last = s.first_valid_index(), s.last_valid_index()
        if first is None:
            continue
        life = s.loc[first:last]
        missing = life.isna().to_numpy()
        if not missing.any():
            continue
        # Runs of missing sessions
        edges = np.diff(np.r_[0, missing.astype(int), 0])
        starts, ends = np.where(edges == 1)[0], np.where(edges == -1)[0]
        for a, b in zip(starts, ends):
            if b - a > min_sessions:
                rows.append((sym, life.index[a].date(), life.index[b - 1].date(), int(b - a)))
    return pd.DataFrame(rows, columns=["symbol", "from", "to", "sessions"])


def big_moves(m: Market, threshold: float = 0.5) -> pd.DataFrame:
    r = m.close.pct_change(fill_method=None)
    hits = r.stack()
    hits = hits[hits.abs() > threshold]
    return hits.rename("move").reset_index().rename(columns={"level_0": "date", "level_1": "symbol"})


def pounds_not_pence(data: Path) -> pd.DataFrame:
    """Lines quoted in pence where price x implied shares is a hundredth of the cap.

    Compares the raw close (as FMP sent it) with market cap / shares from the
    latest statement: for a pence line the ratio cap / (close x shares) is about
    0.01; near 1 means the price looks like pounds.
    """
    data = Path(data)
    if not (data / "statements.parquet").exists() or not (data / "market_cap.parquet").exists():
        return pd.DataFrame()
    master = pd.read_parquet(data / "symbols.parquet").set_index("symbol")
    prices = pd.read_parquet(data / "prices.parquet")
    caps = pd.read_parquet(data / "market_cap.parquet")
    st = pd.read_parquet(data / "statements.parquet")
    shares = st.sort_values("period_end").groupby("symbol")["weightedAverageShsOut"].last() \
        if "weightedAverageShsOut" in st else pd.Series(dtype=float)
    last_px = prices.sort_values("date").groupby("symbol").last()
    last_cap = caps.sort_values("date").groupby("symbol")["market_cap"].last()
    df = pd.DataFrame({"close": last_px["close"], "cap": last_cap, "shares": shares}).dropna()
    qc = master["quote_currency"] if "quote_currency" in master else master["currency"]
    df["currency"] = qc.reindex(df.index)
    df["ratio"] = df["cap"] / (df["close"] * df["shares"])
    pence = df["currency"].isin(["GBp", "GBX"])
    odd = df[(pence & (df["ratio"] > 0.3)) | (~pence & (df["ratio"] < 0.03))]
    return odd.reset_index().rename(columns={"index": "symbol"})


def empty_statements(data: Path) -> pd.DataFrame:
    p = Path(data) / "statements.parquet"
    if not p.exists():
        return pd.DataFrame()
    st = pd.read_parquet(p)
    out = []
    for c in ("revenue", "totalAssets"):
        if c in st:
            e = st[st[c].isna()][["symbol", "period_end", "freq"]].assign(field=c)
            mp = Path(data) / "symbols.parquet"
            if mp.exists():
                sec = pd.read_parquet(mp, columns=["symbol", "sector"]).drop_duplicates("symbol").set_index("symbol")["sector"]
                e["sector"] = e["symbol"].map(sec).fillna("(none)")
            out.append(e)
    return pd.concat(out, ignore_index=True) if out else pd.DataFrame()


def counts_by_year(m: Market, data: Path) -> pd.DataFrame:
    yr = m.close.notna().groupby(m.close.index.year).any().sum(axis=1).rename("with_prices")
    out = yr.to_frame()
    p = Path(data) / "statements.parquet"
    if p.exists():
        st = pd.read_parquet(p, columns=["symbol", "period_end"])
        out["with_statements"] = st.groupby(pd.to_datetime(st["period_end"]).dt.year)["symbol"].nunique()
    gone_years = pd.Series({s: d.year for s, d in m.last_price.items() if m.gone.get(s, False) and d is not None})
    out["delisted"] = gone_years.value_counts().reindex(out.index).fillna(0).astype(int)
    return out.fillna(0).astype(int)


def md(df: pd.DataFrame, index: bool = True) -> str:
    """A Markdown table without needing tabulate."""
    if index:
        df = df.reset_index()
    cols = [str(c) for c in df.columns]
    rows = ["| " + " | ".join(cols) + " |", "|" + "---|" * len(cols)]
    rows += ["| " + " | ".join(str(v) for v in r) + " |" for r in df.itertuples(index=False)]
    return "\n".join(rows)


def cap_rescale_section(data: Path, member) -> list[str]:
    """Z: market caps rescaled by 100 (pence read as pounds), counts and the 20 largest."""
    data = Path(data)
    out = ["## Market caps rescaled by 100 (pence read as pounds)", ""]
    p = data / "cap_rescaled.parquet"
    r = pd.read_parquet(p) if p.exists() else pd.DataFrame()
    if r.empty:
        out += ["Step check: none.", ""]
    else:
        out += [f"Step check (a one-day ratio of 70 to 140, or its inverse; the earlier segment lined up with the "
                f"latest level): {len(r)} symbols, {int(r['rows'].sum())} rows.", "",
                "The 20 largest corrections:", "",
                md(r.sort_values("correction", ascending=False).head(20)[
                    ["symbol", "rows", "from", "to", "largest_before", "largest_after"]], index=False), ""]
    q = data / "cap_lse_corrections.parquet"
    c = pd.read_parquet(q) if q.exists() else pd.DataFrame()
    out += [f"LSE check (FMP still 50 to 200 times the LSE's cap in a month, divided by 100): "
            f"{c['symbol'].nunique() if len(c) else 0} symbols, {len(c)} symbol-months.", ""]
    return out


def moves_by_scope(moves: pd.DataFrame, member: pd.DataFrame | None) -> dict:
    out = {"overall": int(len(moves))}
    if member is not None and len(moves):
        d = pd.to_datetime(moves["date"])
        ok = [bool(member.at[x, s]) if (x in member.index and s in member.columns) else False
              for x, s in zip(d, moves["symbol"])]
        out["universe"] = int(sum(ok))
        out["universe_since_2022"] = int(sum(o and x >= pd.Timestamp("2022-01-01") for o, x in zip(ok, d)))
    out["since_2007"] = int((pd.to_datetime(moves["date"]) >= "2007-01-01").sum()) if len(moves) else 0
    return out


def write_data_report(m: Market, data: Path) -> Path:
    import json
    from .clean import find_bad_ticks
    from .universe import membership
    data = Path(data)
    master = m.master
    member = None
    up = data / "universe.parquet"
    if up.exists():
        u = pd.read_parquet(up)
        if len(u):
            member, _ = membership(u, m.dates)
    gaps = price_gaps(m)
    after = big_moves(m)
    raw = pd.read_parquet(data / "prices.parquet", columns=["symbol", "date", "close"])
    raw["date"] = pd.to_datetime(raw["date"])
    wide = raw.drop_duplicates(["symbol", "date"]).pivot(index="date", columns="symbol", values="close")
    r = wide.pct_change(fill_method=None).stack()
    before = r[r.abs() > 0.5].rename("move").reset_index()
    before.columns = ["date", "symbol", "move"]
    tp = data / "bad_ticks.parquet"
    ticks = pd.read_parquet(tp) if tp.exists() else find_bad_ticks(raw)
    b, a = moves_by_scope(before, member), moves_by_scope(after, member)
    if member is not None and len(after):
        keep = [bool(member.at[x, s]) if (x in member.index and s in member.columns) else False
                for x, s in zip(pd.to_datetime(after["date"]), after["symbol"])]
        after_listed = after[keep]
    else:
        after_listed = after
    pounds = pounds_not_pence(data)
    empty = empty_statements(data)
    counts = counts_by_year(m, data)
    excl = master["exclude_reason"].value_counts() if "exclude_reason" in master else pd.Series(dtype=int)
    mr = data / "master_report.json"
    msum = json.loads(mr.read_text()) if mr.exists() else {}
    move_table = pd.DataFrame({"before cleaning": b, "after cleaning": a}).fillna(0).astype(int)
    lines = [f"# Data report, {dt.datetime.now():%Y-%m-%d %H:%M}", "",
             f"Symbols in the master: {len(master)}; kept: {int(master.get('included', pd.Series()).sum())}; "
             f"with prices: {int(m.close.notna().any().sum())}; stopped trading: {int(m.gone.sum())}.", "",
             "## Excluded, by reason", "", md(excl.to_frame("symbols")) if len(excl) else "none", "",
             "## Master checks", "",
             f"- Overseas lines excluded, by ISIN prefix (for Dale to decide): {msum.get('overseas_by_isin_prefix', {})}",
             f"- Kept with no ISIN (country unknown): {msum.get('kept_without_isin', '?')}",
             f"- Quote currency of kept lines: {msum.get('quote_currency', {})}; cap currency: {msum.get('cap_currency', {})}",
             f"- Profile currency is not the quote (reporting currency, traded in pence): "
             f"{', '.join(msum.get('profile_currency_not_quote', [])[:60]) or 'none'}",
             f"- AIM file: {msum.get('aim_file') or 'NOT LOADED - put the LSE AIM companies file in data/reference/aim_companies.*'}; "
             f"lines marked AIM: {msum.get('aim_marked', 0)}. {msum.get('aim_caveat', '')}", "",
             *cap_rescale_section(data, member),
             f"## Price gaps over 10 trading days ({len(gaps)})", "", md(gaps.head(200), index=False) if len(gaps) else "none", "",
             "## Day moves over 50%", "",
             f"Bad ticks dropped (a move over 50% back within 20% of the earlier price inside 3 sessions): {len(ticks)} "
             "days, listed in data/bad_ticks.parquet.", "", md(move_table), "",
             f"Moves over 50% that remain, universe members only ({len(after_listed)}):", "",
             md(after_listed.head(300), index=False) if len(after_listed) else "none", "",
             f"## Prices that look like pounds rather than pence ({len(pounds)})", "",
             md(pounds.head(200), index=False) if len(pounds) else "none", "",
             f"## Statements with an empty revenue or totalAssets ({len(empty)})", "",
             "Banks and insurers have no revenue line, and interim rows often have no full balance sheet. "
             "A signal skips a company whose field is missing; it never reads it as zero.", "",
             md(empty.groupby(["field", "freq"]).size().to_frame("rows")) if len(empty) else "none", "",
             md(empty.groupby(["field", "sector"]).size().to_frame("rows").sort_values("rows", ascending=False).head(40))
             if len(empty) and "sector" in empty else "", "",
             "## Companies per year", "", md(counts), ""]
    path = data / "data_report.md"
    path.write_text("\n".join(lines))
    return path
