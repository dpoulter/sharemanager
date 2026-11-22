"""The N100's extra symbols (contracts data_refresh, indicators, data_publish; 21 Nov 2026).

The research master leaves investment trusts and funds out by design. The N100
lists them (stock_symbols.is_fund = 'Y': 3IN, CTY, FCIT, SMT, PSH, HICL and the
rest) and, since the Mac mini became its one source of prices, has no other
source for them. So the Mac mini also fetches them - for publishing only.

Kept apart from the research data, under data/n100_extra/:

    symbols.parquet    symbol (FMP's), n100_symbol, currency (FMP profile), included, read_at:
                       the N100's symbols (data_import `symbols`) minus the research master,
                       with the date the list was read. `research publish` refreshes it.
    raw/               per-symbol price, dividend and profile files, as fetch.py writes them
    prices.parquet     symbol, date, close (raw, quote unit), adj_close, volume: from 2021, then topped up
    dividends.parquet  as the research dividends, so the adjusted series exists
    bad_ticks.parquet  clean.find_bad_ticks, the research rule; load_market leaves them out
    fx.parquet         a copy of the research FX, for a line quoted in another currency

Pence and pounds: a line's quote unit is its FMP profile currency (GBp unless it
says otherwise), and prices go to pounds by store.to_pounds_factor as research
prices do. The publish turns pounds back into the quote unit with the same
factor, so the N100 gets FMP's raw close whatever the unit.

The research universe, the rankings and every backtest read data/ and the
research store; nothing of these files is in either. Changes to these prices
are recorded in the data version as their own panel (`extra_close`, read by
nothing in research), and the seven N100-style indicators are worked out for
them into a store of their own (IndicatorStore.for_scope, scope n100_extra; indicators()).
"""
from __future__ import annotations

import datetime as dt
import logging
import shutil
from pathlib import Path

import pandas as pd

log = logging.getLogger("research.extra")

DIRNAME = "n100_extra"
FIRST_FROM = "2021-01-01"
PUBLISHED = ["close_price", "ma50", "ma200", "1mnth", "3mnth", "6mnth", "12mnth"]
BASIS = "raw"
PANEL = "extra_close"
INPUT = "extra_prices"         # what an extra indicator reads, in data_version.PANEL_INPUT terms


def extra_dir(cfg) -> Path:
    return Path(cfg.data_dir) / DIRNAME


def store(cfg):
    from .indicator_store import IndicatorStore
    return IndicatorStore.for_scope(cfg, DIRNAME)


def fmp_symbol(n100: str) -> str:
    """The N100's BT.A is FMP's BT-A.L."""
    return f"{str(n100).replace('.', '-')}.L"


def research_n100_symbols(cfg) -> set[str]:
    """The N100 symbols the research master covers (its included lines)."""
    from .engine import n100_symbol_key
    p = Path(cfg.data_dir) / "symbols.parquet"
    if not p.exists():
        return set()
    m = pd.read_parquet(p)
    if "included" in m:
        m = m[m["included"].fillna(False).astype(bool)]
    return {n100_symbol_key(s) for s in m["symbol"]}


def write_list(cfg, n100_symbols: list[str], covered: set[str] | None = None) -> pd.DataFrame:
    """The N100's symbols minus the research master, with the date read. Returns the list."""
    covered = research_n100_symbols(cfg) if covered is None else set(covered)
    extras = sorted(set(map(str, n100_symbols)) - covered)
    d = extra_dir(cfg)
    d.mkdir(parents=True, exist_ok=True)
    old = read_list(cfg)
    cur = dict(zip(old["n100_symbol"], old["currency"])) if len(old) else {}
    df = pd.DataFrame({"symbol": [fmp_symbol(s) for s in extras], "n100_symbol": extras,
                       "currency": [cur.get(s) for s in extras], "included": True,
                       "read_at": dt.date.today().isoformat()})
    df.to_parquet(d / "symbols.parquet")
    log.info("N100 extra symbols (not in the research master): %d", len(df))
    return df


def read_list(cfg) -> pd.DataFrame:
    p = extra_dir(cfg) / "symbols.parquet"
    if not p.exists():
        return pd.DataFrame(columns=["symbol", "n100_symbol", "currency", "included", "read_at"])
    return pd.read_parquet(p)


def fetch(cfg, client=None, full: bool = False) -> dict:
    """Daily prices and dividends for the listed extras: from 2021 the first time, then topped up.
    Then merged, cleaned (bad ticks) and given the research FX. Returns {symbols, failures}."""
    from .clean import clean_prices
    from .fetch import Fetcher, consolidate
    lst = read_list(cfg)
    d = extra_dir(cfg)
    if not len(lst):
        return {"symbols": 0, "failures": 0}
    f = Fetcher(cfg, client=client)
    f.raw = d / "raw"
    f.raw.mkdir(parents=True, exist_ok=True)
    end = dt.date.today().isoformat()
    syms = lst["symbol"].tolist()
    prof = f.profiles(syms)
    cur = dict(zip(prof["symbol"], prof.get("currency", pd.Series(index=prof.index, dtype=object))))
    if full:
        def one(sym):
            f.prices(sym, full, end, start=FIRST_FROM)
            f.dividends(sym, full)
        f._each(one, syms, "extra")
    else:
        # as the research top-up: batch-quote for the new day, a symbol's own history only when new, behind or re-based
        st = f._load_state()
        f.top_up_dividends(syms, end, st)
        f.top_up_prices(syms, end, st, start=FIRST_FROM)
        f._save_state(st)
    consolidate(d)
    lst["currency"] = [cur.get(s) or c or "GBp" for s, c in zip(lst["symbol"], lst["currency"])]
    lst["quote_currency"] = lst["currency"]
    lst.to_parquet(d / "symbols.parquet")
    clean_prices(d)
    fx = Path(cfg.data_dir) / "fx.parquet"
    if fx.exists():
        shutil.copyfile(fx, d / "fx.parquet")
    if f.failures:
        pd.DataFrame(f.failures).to_parquet(d / "fetch_failures.parquet")
    return {"symbols": len(syms), "failures": len(f.failures)}


def load(cfg):
    """The extras as a Market (prices in pounds, bad ticks out), or None when there are none."""
    from .store import load_market
    d = extra_dir(cfg)
    if not (d / "prices.parquet").exists() or not (d / "symbols.parquet").exists():
        return None
    m = load_market(d)
    return m if len(m.close.columns) else None


def indicators(cfg, version_now: int, market=None) -> list[dict]:
    """The seven N100-style indicators for the extras, raw basis, into their own store. One report
    per key (prefixed n100_extra/), as the research job's."""
    import time
    from dataclasses import replace
    from .indicator_job import _one
    from .indicators import REGISTRY
    market = load(cfg) if market is None else market
    if market is None:
        return []
    st = store(cfg)
    st.root.mkdir(parents=True, exist_ok=True)
    last = market.dates[-1]
    reports = []
    for name in PUBLISHED:
        defn = replace(REGISTRY[name], uses=frozenset({"prices", INPUT, "fx"}))   # "prices" keeps the raw basis
        k = f"{name}@{BASIS}"
        t0 = time.time()
        r = {"indicator": f"{DIRNAME}/{k}", "time": dt.datetime.now().isoformat(timespec="seconds")}
        try:
            r.update(_one(cfg, st, defn, k, market, None, version_now, last, force=False))
            r["ok"] = True
        except Exception as e:
            r.update({"ok": False, "reason": f"{type(e).__name__}: {e}"})
            log.exception("extra indicator %s failed", k)
        r["seconds"] = round(time.time() - t0, 2)
        reports.append(r)
    return reports
