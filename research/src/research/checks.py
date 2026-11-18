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
    df["currency"] = master["currency"].reindex(df.index)
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


def write_data_report(m: Market, data: Path) -> Path:
    data = Path(data)
    master = m.master
    gaps = price_gaps(m)
    moves = big_moves(m)
    pounds = pounds_not_pence(data)
    empty = empty_statements(data)
    counts = counts_by_year(m, data)
    excl = master["exclude_reason"].value_counts() if "exclude_reason" in master else pd.Series(dtype=int)
    lines = [f"# Data report, {dt.datetime.now():%Y-%m-%d %H:%M}", "",
             f"Symbols in the master: {len(master)}; kept: {int(master.get('included', pd.Series()).sum())}; "
             f"with prices: {int(m.close.notna().any().sum())}; stopped trading: {int(m.gone.sum())}.", "",
             "## Excluded, by reason", "", md(excl.to_frame("symbols")) if len(excl) else "none", "",
             f"## Price gaps over 10 trading days ({len(gaps)})", "", md(gaps.head(200), index=False) if len(gaps) else "none", "",
             f"## Day moves over 50% ({len(moves)}): bad ticks or unadjusted splits", "",
             md(moves.head(200), index=False) if len(moves) else "none", "",
             f"## Prices that look like pounds rather than pence ({len(pounds)})", "",
             md(pounds.head(200), index=False) if len(pounds) else "none", "",
             f"## Statements with an empty revenue or totalAssets ({len(empty)})", "",
             md(empty.groupby("field").size().to_frame("rows")) if len(empty) else "none", "",
             "## Companies per year", "", md(counts), ""]
    path = data / "data_report.md"
    path.write_text("\n".join(lines))
    return path
