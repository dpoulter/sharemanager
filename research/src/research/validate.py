"""Reproduce the N100's backtest run 9, which must pass before anything else is trusted.

Run 9: trend following, monthly, hold_rank 30, 10 bp spread, 1 Nov 2022 to
13 Nov 2026, on the N100's universe (today's FTSE 350 list). Pass: total
return within 3 percentage points and fill count within 10%.

Jarvis's exports from the N100 (rerun as run 10, identical to run 9:
+104.43%, 425 fills), in data/validation/ by default:
  - n100_universe_2026-11-18.tsv: symbol, is_fund, sector (350 rows). Funds
    (is_fund=Y) are dropped, as run 9 excluded them
  - n100_run9_orders.csv: date, symbol, side, quantity, price (pounds)
  - n100_run9_values.csv: date, total

This run covers holdout dates. It is an engine check, logged as kind
'engine_validation' and not as research, but it does show trend following's
holdout-period result on a survivorship-biased universe; treat that number as
seen.
"""
from __future__ import annotations

from pathlib import Path

import pandas as pd

from .strategy import Workspace, spec_from_dict

RUN9 = {
    "name": "n100_run9_reproduction",
    "family": "engine_validation",
    "signals": {"trend_following": 1},
    "holdings": 15,
    "rebalance": "monthly",
    "hold_rank": 30,
    "exit": "below_ma200_2d",
    "drift_band": 0.02,
    "max_turnover": 0.5,
    "start_cash": 100000,
    # Run 9 came from Jarvis's patched copy of the N100 app with Trading 212
    # costs (Dale, 18 Nov 2026): no commission, 10 bp spread each way on every
    # line, 0.5% stamp on buys, whole shares, GBP 250 minimum order, no PTM levy.
    "costs": {"commission": 0.0, "decimals": 0, "minimum_order": 250.0,
              "spread_large_bp": 10.0, "spread_mid_bp": 10.0, "ptm_levy": 0.0},
}


def universe_list(path: Path, out: Path) -> Path:
    """The N100 list without its funds, written as a one-column CSV the engine reads."""
    from .universe import read_symbol_list
    out.parent.mkdir(parents=True, exist_ok=True)
    read_symbol_list(path)[["symbol"]].to_csv(out, index=False)
    return out


def trace_first_month(ws: Workspace, orders: pd.DataFrame, listed: Path) -> list[dict]:
    """For each share the N100 bought in its first month: what the research engine saw on day one."""
    from .signals.live import six_month_return
    from .signals.base import Context
    m = ws.m
    start = m.dates[m.dates.searchsorted(pd.Timestamp("2022-11-01"))]
    first_month = orders["date"].dt.to_period("M").min()
    buys = orders[(orders["date"].dt.to_period("M") == first_month) & (orders["side"].str.upper() == "BUY")]
    syms = sorted({s if s.endswith(".L") else s + ".L" for s in buys["symbol"].astype(str)})
    uni = set(pd.read_csv(listed)["symbol"])
    ctx = Context(m, ws.f)
    six = six_month_return(ctx, start)
    i = ctx.pos(start)
    ma = m.ma200.iloc[i]
    close = ctx.adj_at(start)
    above = (close > ma)
    score = six.where(above).reindex(sorted(uni & set(six.index)))
    rank = score.rank(ascending=False)
    out = []
    for s in syms:
        out.append({"symbol": s, "in_list": s in uni, "has_prices": s in m.close.columns,
                    "adj_close": None if s not in close else round(float(close[s]), 4),
                    "ma200": None if s not in ma or pd.isna(ma[s]) else round(float(ma[s]), 4),
                    "closes_in_last_260": int(m.adj[s].iloc[max(0, i - 259): i + 1].notna().sum()) if s in m.adj else 0,
                    "six_month": None if s not in six or pd.isna(six[s]) else round(float(six[s]), 4),
                    "above_ma200": bool(above.get(s, False)),
                    "rank_on_day_one": None if s not in rank or pd.isna(rank[s]) else int(rank[s])})
    return out


def run9(ws: Workspace, universe_csv: Path, orders_csv: Path | None, values_csv: Path | None,
         costs: dict | None = None) -> dict:
    listed = universe_list(Path(universe_csv), ws.cfg.data_dir / "validation" / "run9_universe_no_funds.csv")
    raw = dict(RUN9, universe=f"list:{listed}")
    if costs:
        raw["costs"] = {**raw["costs"], **costs}
    spec = spec_from_dict(raw)
    out = ws.run(spec, period="2022-11-01:2026-11-13", kind="engine_validation",
                 start="2022-11-01", end="2026-11-13")
    rec = out["record"]
    report = {"universe_without_funds": int(len(pd.read_csv(listed))),
              "ours_total_return": rec["total_return"], "ours_fills": rec["fills"],
              "ours_costs": rec["costs_paid"],
              "report": str(out.get("report"))}
    if values_csv:
        v = pd.read_csv(values_csv, parse_dates=["date"]).sort_values("date")
        # From the starting cash, not the first row: the N100's first value
        # (98,723.75 on 1 Nov 2022) is already after the first build's costs,
        # and its stated +104.43% is 204,432.69 over 100,000.
        theirs = float(v["total"].iloc[-1] / float(raw["start_cash"]) - 1)
        report["n100_first_value"] = float(v["total"].iloc[0])
        report["ours_first_value"] = float(out["result"].values["total"].iloc[0])
        report["n100_total_return"] = theirs
        report["return_gap_pp"] = 100 * (rec["total_return"] - theirs)
        report["return_ok"] = abs(report["return_gap_pp"]) <= 3
    if orders_csv:
        o = pd.read_csv(orders_csv, parse_dates=["date"])
        report["n100_fills"] = int(len(o))
        report["fill_gap_pct"] = 100 * (rec["fills"] - len(o)) / max(1, len(o))
        report["fills_ok"] = abs(report["fill_gap_pct"]) <= 10
        ours = out["result"].fills.copy()
        ours["symbol"] = ours["symbol"].str.replace(r"\.L$", "", regex=True)
        o["symbol"] = o["symbol"].astype(str).str.replace(r"\.L$", "", regex=True)
        ours["month"] = ours["date"].dt.to_period("M")
        o["month"] = o["date"].dt.to_period("M")
        a = ours[ours["side"] == "BUY"].groupby("month")["symbol"].apply(set)
        b = o[o["side"].str.upper() == "BUY"].groupby("month")["symbol"].apply(set)
        months = sorted(set(a.index) | set(b.index))
        diff = []
        for mth in months:
            x, y = a.get(mth, set()), b.get(mth, set())
            if x != y:
                diff.append({"month": str(mth), "only_ours": sorted(x - y), "only_n100": sorted(y - x)})
        report["months_with_different_buys"] = diff
        report["months_compared"] = len(months)
        report["months_identical"] = len(months) - len(diff)
        report["first_fill_n100"] = str(o["date"].min().date())
        report["first_fill_ours"] = str(ours["date"].min().date()) if len(ours) else None
        report["first_month_trace"] = trace_first_month(ws, o, listed)
    report["passed"] = bool(report.get("return_ok", False) and report.get("fills_ok", False))
    return report
