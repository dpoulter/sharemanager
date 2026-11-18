"""Second pass over the symbol master, once prices, caps and statements are held.

Run after every merge. It decides, with the data rather than FMP's profile:

- the quote currency of each line and the currency of its market cap. FMP's
  profile `currency` is the company's REPORTING currency for some London
  lines (Compass, IHG, Baltic Classifieds, Metlen say EUR or USD, and trade
  in pence), so it cannot be trusted for the quote. Each line's last close
  and market cap are checked against shares outstanding and the unit pair
  that agrees is kept;
- lines whose price series looks like a bond (median near 100, very low
  volatility): loan stock under the issuer's name;
- overseas companies with a secondary London listing: ISIN country outside
  GB, JE, GG, IM. Those with no ISIN are kept and counted;
- second share classes: one line per company - the plain ticker, or the
  most traded line where there is no plain one (BT is BT-A.L on FMP);
- AIM, from the London Stock Exchange's AIM companies file in
  data/reference/aim_companies.* when it is there. It lists companies on AIM
  today only, so dead AIM companies stay unmarked.

Every exclusion keeps its reason. A summary goes to data/master_report.json.
"""
from __future__ import annotations

import json
import logging
import math
from pathlib import Path

import numpy as np
import pandas as pd

from .fetch import exclusion_reason

log = logging.getLogger(__name__)

HOME_ISIN = {"GB", "JE", "GG", "IM"}
PENCE = {"GBp", "GBX", "GBx"}


def _fx_last(fx: pd.DataFrame, cur: str) -> float:
    if cur in ("GBP",) or cur in PENCE:
        return 1.0
    s = fx[fx["currency"] == cur]
    return float(s.sort_values("date")["gbp_per_unit"].iloc[-1]) if len(s) else math.nan


def infer_units(close: float, cap: float, shares: float, profile_ccy: str | None, fx: pd.DataFrame) -> tuple[str, str, float]:
    """(quote_currency, cap_currency, log error) that best makes close x shares = cap.

    Prefers pence for the quote and pounds for the cap when the evidence is
    missing or equal, because that is what nearly every London line is.
    """
    prof = (profile_ccy or "GBp").strip()
    prof_major = "GBP" if prof in PENCE else prof
    quotes = ["GBp", "GBP"] + ([prof_major] if prof_major not in ("GBP",) else [])
    caps = ["GBP"] + ([prof_major] if prof_major != "GBP" else [])
    if not (close > 0 and cap > 0 and shares > 0):
        return "GBp", "GBP", math.nan
    best = ("GBp", "GBP", math.inf)
    for q in quotes:
        qf = 0.01 if q == "GBp" else _fx_last(fx, q)
        for c in caps:
            cf = _fx_last(fx, c)
            if not (qf > 0 and cf > 0):
                continue
            err = abs(math.log((close * qf * shares) / (cap * cf)))
            # A small preference for the usual pair, so a tie goes to pence/pounds.
            if q == "GBp" and c == "GBP":
                err -= 0.05
            if err < best[2]:
                best = (q, c, err)
    return best


def bond_like(close: pd.Series) -> bool:
    """Median near 100 and annualised volatility under 6%: a bond, not a share."""
    s = close.dropna()
    if len(s) < 60:
        return False
    r = s.pct_change().dropna()
    return 50 <= float(s.median()) <= 150 and float(r.std() * math.sqrt(252)) < 0.06


def base_ticker(sym: str) -> str:
    return sym[:-2].split("-")[0] if sym.endswith(".L") else sym.split("-")[0]


def load_aim(data: Path) -> tuple[set[str], set[str], str | None]:
    """ISINs and tickers from data/reference/aim_companies.{csv,xlsx,xls}."""
    ref = Path(data) / "reference"
    files = sorted(ref.glob("aim_companies.*")) if ref.exists() else []
    if not files:
        return set(), set(), None
    f = files[0]
    if f.suffix.lower() in (".xlsx", ".xls"):
        # The LSE file has a few title rows above the header; find the header row.
        raw = pd.read_excel(f, header=None)
        hdr = next((i for i in range(min(30, len(raw)))
                    if any("isin" in str(v).lower() for v in raw.iloc[i].tolist())), 0)
        df = pd.read_excel(f, header=hdr)
    else:
        df = pd.read_csv(f, sep=None, engine="python")
    cols = {c: str(c).lower() for c in df.columns}
    isin_col = next((c for c, l in cols.items() if "isin" in l), None)
    tick_col = next((c for c, l in cols.items() if l in ("tidm", "ticker", "code", "symbol", "epic")), None)
    isins = set(df[isin_col].dropna().astype(str).str.strip().str.upper()) if isin_col else set()
    ticks = set(df[tick_col].dropna().astype(str).str.strip().str.upper().str.replace(".L", "", regex=False)) if tick_col else set()
    return isins, ticks, f.name


def refine_master(data: Path) -> pd.DataFrame:
    data = Path(data)
    mp = data / "symbols.parquet"
    m = pd.read_parquet(mp)
    m["exclude_reason"] = m.apply(exclusion_reason, axis=1)
    prices = pd.read_parquet(data / "prices.parquet") if (data / "prices.parquet").exists() else pd.DataFrame(
        columns=["symbol", "date", "close", "volume"])
    caps = pd.read_parquet(data / "market_cap.parquet") if (data / "market_cap.parquet").exists() else pd.DataFrame(
        columns=["symbol", "date", "market_cap"])
    fx = pd.read_parquet(data / "fx.parquet") if (data / "fx.parquet").exists() else pd.DataFrame(
        columns=["date", "currency", "gbp_per_unit"])
    shares = pd.Series(dtype=float)
    if (data / "statements.parquet").exists():
        st = pd.read_parquet(data / "statements.parquet", columns=None)
        if "weightedAverageShsOut" in st:
            shares = st.dropna(subset=["weightedAverageShsOut"]).sort_values("period_end") \
                .groupby("symbol")["weightedAverageShsOut"].last()

    prices = prices.sort_values(["symbol", "date"])
    last_close = prices.dropna(subset=["close"]).groupby("symbol").last()
    caps = caps.sort_values(["symbol", "date"]).dropna(subset=["market_cap"])
    by_sym = dict(tuple(prices.groupby("symbol"))) if len(prices) else {}
    caps_by = dict(tuple(caps.groupby("symbol"))) if len(caps) else {}

    quote, capc, err, bond, traded = {}, {}, {}, {}, {}
    for sym in m["symbol"]:
        g = by_sym.get(sym)
        if g is None or g["close"].notna().sum() == 0:
            quote[sym], capc[sym], err[sym] = "GBp", "GBP", math.nan
            continue
        c = caps_by.get(sym, caps.iloc[0:0])
        lc = g.dropna(subset=["close"]).iloc[-1]
        cap_at = c[c["date"] <= lc["date"]]["market_cap"]
        cap_v = float(cap_at.iloc[-1]) if len(cap_at) else math.nan
        prof = m.loc[m["symbol"] == sym, "currency"].iloc[0] if "currency" in m else None
        q, k, e = infer_units(float(lc["close"]), cap_v, float(shares.get(sym, math.nan)),
                              prof if isinstance(prof, str) else None, fx)
        quote[sym], capc[sym], err[sym] = q, k, e
        bond[sym] = bond_like(g.set_index("date")["close"].iloc[-500:])
        vol = g["volume"] if "volume" in g else pd.Series(np.nan, index=g.index)
        traded[sym] = float((g["close"] * vol).median()) if vol.notna().any() else 0.0

    m["quote_currency"] = m["symbol"].map(quote)
    m["cap_currency"] = m["symbol"].map(capc)
    m["unit_check_error"] = m["symbol"].map(err)

    def add(mask, reason):
        sel = mask & m["exclude_reason"].isna()
        m.loc[sel, "exclude_reason"] = reason
        return int(sel.sum())

    counts = {}
    counts["bond-like price series"] = add(m["symbol"].map(bond).fillna(False).astype(bool), "price series looks like a bond")
    isin = m.get("isin", pd.Series(index=m.index, dtype=object)).astype("string").str.strip().str.upper()
    cc = isin.str[:2]
    overseas = cc.notna() & (isin.str.len() >= 12) & ~cc.isin(list(HOME_ISIN))
    m["isin_country"] = cc
    overseas_by_prefix = cc[overseas & m["exclude_reason"].isna()].value_counts().to_dict()
    for code in overseas_by_prefix:
        add(overseas & (cc == code), f"overseas company (ISIN {code})")

    # One line per company.
    m["base"] = m["symbol"].map(base_ticker)
    m["traded"] = m["symbol"].map(traded).fillna(0.0)
    alive = m[m["exclude_reason"].isna()]
    second = 0
    for base, g in alive.groupby("base"):
        if len(g) < 2:
            continue
        plain = g[g["symbol"] == f"{base}.L"]
        keep = plain["symbol"].iloc[0] if len(plain) else g.sort_values("traded", ascending=False)["symbol"].iloc[0]
        for sym in g["symbol"]:
            if sym != keep:
                m.loc[m["symbol"] == sym, "exclude_reason"] = f"second share class (kept {keep})"
                second += 1
    counts["second share class"] = second

    aim_isins, aim_ticks, aim_file = load_aim(data)
    if aim_file:
        tick = m["symbol"].str.replace(r"\.L$", "", regex=True).str.upper()
        m["is_aim"] = m["is_aim"].fillna(False).astype(bool) | isin.isin(list(aim_isins)).fillna(False) | tick.isin(aim_ticks)
    m["included"] = m["exclude_reason"].isna()
    m = m.drop(columns=["base"])
    m.to_parquet(mp)

    summary = {
        "symbols": int(len(m)), "kept": int(m["included"].sum()),
        "by_reason": m["exclude_reason"].value_counts().to_dict(),
        "overseas_by_isin_prefix": {k: int(v) for k, v in overseas_by_prefix.items()},
        "kept_without_isin": int((m["included"] & isin.isna()).sum()),
        "quote_currency": m.loc[m["included"], "quote_currency"].value_counts().to_dict(),
        "cap_currency": m.loc[m["included"], "cap_currency"].value_counts().to_dict(),
        "profile_currency_not_quote": sorted(m.loc[m["included"] & m["currency"].notna()
                                                    & ~m["currency"].isin(list(PENCE | {"GBP"}))
                                                    & (m["quote_currency"] == "GBp"), "symbol"].tolist()),
        "aim_file": aim_file, "aim_marked": int(m["is_aim"].fillna(False).sum()),
        "aim_caveat": "The AIM file lists companies on AIM today; dead AIM companies are not marked.",
    }
    (data / "master_report.json").write_text(json.dumps(summary, indent=1, default=str))
    log.info("master: %d kept of %d; %s", summary["kept"], summary["symbols"], counts)
    return m
