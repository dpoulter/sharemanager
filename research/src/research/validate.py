"""Reproduce the N100's backtest run 9, which must pass before anything else is trusted.

Run 9: trend following, monthly, hold_rank 30, 10 bp spread, 1 Nov 2022 to
13 Nov 2026, on the N100's universe (today's FTSE 350 list). Pass: total
return within 3 percentage points and fill count within 10%.

Jarvis exports from the N100:
  - the symbol list: stock_symbols (enabled, XLON), one column `symbol`
  - run 9's orders: date, symbol, side, quantity, price
  - run 9's values: date, total

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
    # Paper account rules on the N100: commission GBP 5.95, whole shares,
    # GBP 250 minimum order, 0.5% stamp, and run 9's 10 bp spread on every line.
    "costs": {"commission": 5.95, "decimals": 0, "minimum_order": 250.0,
              "spread_large_bp": 10.0, "spread_mid_bp": 10.0, "ptm_levy": 0.0},
}


def run9(ws: Workspace, universe_csv: Path, orders_csv: Path | None, values_csv: Path | None,
         costs: dict | None = None) -> dict:
    raw = dict(RUN9, universe=f"list:{universe_csv}")
    if costs:
        raw["costs"] = {**raw["costs"], **costs}
    spec = spec_from_dict(raw)
    out = ws.run(spec, period="2022-11-01:2026-11-13", kind="engine_validation",
                 start="2022-11-01", end="2026-11-13")
    rec = out["record"]
    report = {"ours_total_return": rec["total_return"], "ours_fills": rec["fills"],
              "report": str(out.get("report"))}
    if values_csv:
        v = pd.read_csv(values_csv, parse_dates=["date"]).sort_values("date")
        theirs = float(v["total"].iloc[-1] / v["total"].iloc[0] - 1)
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
    report["passed"] = bool(report.get("return_ok", False) and report.get("fills_ok", False))
    return report
