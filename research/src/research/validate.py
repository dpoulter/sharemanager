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

import numpy as np
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
    # The N100 prices everything on raw closes (historical_prices.price): no
    # dividends in returns, averages or valuations.
    "price_basis": "raw",
    # strategy_price_asof takes a price on or before the date, traded that day or not, and
    # STRATEGY_MAX_ORDERS_PER_RUN refuses a rebalance of more than 50 orders.
    "require_traded_today": False,
    "max_orders": 50,
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


def holdings_by_month(fills: pd.DataFrame) -> dict:
    """Period('M') -> the set of symbols held at that month's end, from a list of fills."""
    f = fills.copy()
    f["date"] = pd.to_datetime(f["date"])
    f["signed"] = np.where(f["side"].astype(str).str.upper() == "BUY", 1.0, -1.0) * f["quantity"].astype(float)
    f = f.sort_values("date")
    out, pos = {}, {}
    if f.empty:
        return out
    months = pd.period_range(f["date"].min().to_period("M"), f["date"].max().to_period("M"), freq="M")
    f["month"] = f["date"].dt.to_period("M")
    for mm in months:
        for r in f[f["month"] == mm].itertuples(index=False):
            pos[r.symbol] = pos.get(r.symbol, 0.0) + r.signed
        out[mm] = {s for s, q in pos.items() if q > 1e-6}
    return out


def positions_by_month(fills: pd.DataFrame) -> dict:
    """Period('M') -> {symbol: quantity} held at that month's end, from a list of fills."""
    f = fills.copy()
    f["date"] = pd.to_datetime(f["date"])
    f["signed"] = np.where(f["side"].astype(str).str.upper() == "BUY", 1.0, -1.0) * f["quantity"].astype(float)
    f = f.sort_values("date")
    out, pos = {}, {}
    if f.empty:
        return out
    f["month"] = f["date"].dt.to_period("M")
    for mm in pd.period_range(f["month"].min(), f["month"].max(), freq="M"):
        for r in f[f["month"] == mm].itertuples(index=False):
            pos[r.symbol] = pos.get(r.symbol, 0.0) + r.signed
        out[mm] = {s: q for s, q in pos.items() if q > 1e-6}
    return out


def same_positions(a: dict, b: dict) -> bool:
    return set(a) == set(b) and all(np.isclose(a[s], b[s], atol=0.5) for s in a)


def fills_side_by_side(n100: pd.DataFrame, ours: pd.DataFrame, month) -> list[dict]:
    """Every fill in a month, the N100's beside ours: date, quantity and price, by share and side."""
    def prep(df):
        d = df.copy()
        d["date"] = pd.to_datetime(d["date"])
        d = d[d["date"].dt.to_period("M") == month]
        d["side"] = d["side"].astype(str).str.upper()
        return d.sort_values(["symbol", "side", "date"])
    a, b = prep(n100), prep(ours)
    keys = sorted(set(zip(a["symbol"], a["side"])) | set(zip(b["symbol"], b["side"])))
    rows = []
    for s, side in keys:
        x = a[(a["symbol"] == s) & (a["side"] == side)]
        y = b[(b["symbol"] == s) & (b["side"] == side)]
        rows.append({"symbol": s, "side": side,
                     "n100": [(str(r.date.date()), float(r.quantity), round(float(r.price), 4)) for r in x.itertuples()],
                     "ours": [(str(r.date.date()), float(r.quantity), round(float(r.price), 4)) for r in y.itertuples()]})
    return rows


def engine_decisions(events: list[dict], month, symbols: set) -> list[dict]:
    """What the research engine decided for these shares at that month's rebalance, and why."""
    return [{"date": str(e["date"].date()), "symbol": e["symbol"], "event": e["event"], "detail": e["detail"]}
            for e in events if e["date"].to_period("M") == month and (e["symbol"] in symbols or e["symbol"] == "(all)")]


def statistics_check(ws: Workspace, out: dict, rebalance_date: str | None) -> dict | str:
    """Our close, 200-day average and 6-month return against the N100's own statistics for the
    rebalance date, and every share whose place in the trend screen differs.

    Needs data/validation/n100_stats_YYYY-MM-DD.csv with columns symbol, close_price, ma200, 6mnth
    (the N100's `statistics` rows for that date, one row per symbol). Without it, says what to export:
    this is the check that shows whether the N100's screen dropped a share (HFD) that ours kept.
    """
    from .universe import to_fmp
    if not rebalance_date:
        return "no rebalance date"
    p = ws.cfg.data_dir / "validation" / f"n100_stats_{rebalance_date}.csv"
    if not p.exists():
        return (f"not available: export the N100's statistics for {rebalance_date} to {p.name} "
                f"(select symbol, indicator, value from statistics where date='{rebalance_date}' and indicator in "
                f"('close_price','ma200','6mnth'), pivoted to symbol,close_price,ma200,6mnth)")
    n = pd.read_csv(p)
    n.columns = [c.strip().lower() for c in n.columns]
    n["symbol"] = n["symbol"].map(to_fmp)
    n = n.set_index("symbol")
    mk, d = out["market"], pd.Timestamp(rebalance_date)
    i = int(mk.dates.searchsorted(d, side="right") - 1)
    ours_close = mk.adj.ffill(limit=10).iloc[i]
    ours_ma = mk.ma200.iloc[i]
    from .signals.base import Context
    ctx = Context(mk, ws.f)
    ours_6m = ctx.n100_return(d, 6) * 100
    rows, screen_diff = [], []
    for s in n.index:
        if s not in ours_close.index:
            rows.append({"symbol": s, "problem": "no prices in our data"})
            continue
        nc, nm = n.at[s, "close_price"], n.at[s, "ma200"]
        n_above = bool(pd.notna(nc) and pd.notna(nm) and nc > nm)
        o_above = bool(pd.notna(ours_close[s]) and pd.notna(ours_ma[s]) and ours_close[s] > ours_ma[s])
        if n_above != o_above:
            screen_diff.append({"symbol": s, "n100_close": nc, "n100_ma200": nm, "our_close": None if pd.isna(ours_close[s]) else round(float(ours_close[s]), 4),
                                "our_ma200": None if pd.isna(ours_ma[s]) else round(float(ours_ma[s]), 4),
                                "n100_above": n_above, "our_above": o_above,
                                "n100_has_ma200": bool(pd.notna(nm))})
        for name, ours_v, theirs in (("close_price", ours_close[s], nc), ("ma200", ours_ma[s], nm),
                                     ("6mnth", ours_6m.get(s, np.nan), n.at[s, "6mnth"] if "6mnth" in n else np.nan)):
            if pd.notna(ours_v) and pd.notna(theirs) and abs(ours_v / theirs - 1) > 0.001 and theirs != 0:
                rows.append({"symbol": s, "indicator": name, "n100": float(theirs), "ours": round(float(ours_v), 4)})
    only_n100 = sorted(set(n.index) - set(ours_close.dropna().index))
    return {"file": p.name, "shares_in_file": int(len(n)), "screen_membership_differs": screen_diff,
            "values_over_0.1pct_apart": rows[:60], "n100_only_symbols": only_n100[:30]}


def explain_month(ws: Workspace, out: dict, month, ours: set, theirs: set, held_before: set, listed: set) -> dict:
    """Why each share differs in the first month the holdings differ: rank, screen or price."""
    mk, scores = out["market"], out["scores"]
    days = [d for d in scores if d.to_period("M") == month]
    d = days[0] if days else max([x for x in scores if x.to_period("M") <= month], default=None)
    s = scores.get(d, pd.Series(dtype=float)) if d is not None else pd.Series(dtype=float)
    rank = s.rank(ascending=False, method="first")
    i = mk.dates.searchsorted(d) if d is not None else 0
    rows = []
    for sym in sorted((ours - theirs) | (theirs - ours)):
        close = mk.close[sym].iloc[i] if sym in mk.close.columns else np.nan
        ma = mk.ma200[sym].iloc[i] if sym in mk.close.columns else np.nan
        if sym not in listed or sym not in mk.close.columns:
            why = "price: not in the list or no prices"
        elif pd.isna(close):
            why = "price: no close on the rebalance date"
        elif pd.isna(ma):
            why = "screen: no 200-day average"
        elif not close > ma:
            why = f"screen: close {close:.4f} not above the 200-day {ma:.4f}"
        elif sym in held_before and sym not in ours:
            why = f"rank {rank.get(sym)}: held, fell outside hold_rank 30 or sold by the exit rule"
        else:
            why = f"rank {int(rank.get(sym)) if pd.notna(rank.get(sym)) else '-'} of {int(s.notna().sum())}"
        rows.append({"symbol": sym, "held_by": "ours only" if sym in ours else "N100 only", "why": why,
                     "close": None if pd.isna(close) else round(float(close), 4),
                     "ma200": None if pd.isna(ma) else round(float(ma), 4),
                     "held_the_month_before": sym in held_before})
    return {"month": str(month), "rebalance_date": None if d is None else str(d.date()),
            "only_ours": sorted(ours - theirs), "only_n100": sorted(theirs - ours), "shares": rows,
            "rankings": rankings(ws, out, d, month, sorted((ours - theirs) | (theirs - ours)))}


def rankings(ws: Workspace, out: dict, d, month, differing: list[str]) -> dict:
    """Our top 20 on the rebalance date, with the 6-month return and its base dates, beside the
    N100's own 6mnth ranking where Jarvis has exported it (data/validation/n100_6mnth_YYYY-MM.csv:
    symbol,value). Without that file the N100 side is its buys, valued the same way as ours."""
    from .signals.base import Context
    from .universe import to_fmp
    if d is None:
        return {}
    ctx = Context(out["market"], ws.f)
    det = ctx.n100_return_detail(d, 6)
    s = out["scores"].get(d, pd.Series(dtype=float)).dropna().sort_values(ascending=False)

    def row(sym, rank=None):
        r = det.loc[sym] if sym in det.index else None
        ma = out["market"].ma200[sym].iloc[ctx.pos(d)] if sym in out["market"].ma200.columns else np.nan
        cl = out["market"].adj[sym].iloc[ctx.pos(d)] if sym in out["market"].adj.columns else np.nan
        return {"rank": rank, "symbol": sym,
                "return_pct": None if r is None or pd.isna(r["ret"]) else round(float(r["ret"]) * 100, 3),
                "base_date": None if r is None else r["start"], "end_date": None if r is None else r["end"],
                "above_ma200": bool(cl > ma) if pd.notna(cl) and pd.notna(ma) else None}

    res = {"ours_top20": [row(sym, k) for k, sym in enumerate(s.index[:20], 1)],
           "differing_shares": [row(sym, int(s.index.get_loc(sym)) + 1 if sym in s.index else None) for sym in differing]}
    p = ws.cfg.data_dir / "validation" / f"n100_6mnth_{month}.csv"
    if p.exists():
        n = pd.read_csv(p)
        n["symbol"] = n["symbol"].map(to_fmp)
        n = n.sort_values("value", ascending=False).head(20)
        res["n100_top20"] = [{"rank": k, "symbol": r.symbol, "return_pct": round(float(r.value), 3),
                              "our_return_pct": row(r.symbol)["return_pct"], "our_base_date": row(r.symbol)["base_date"]}
                             for k, r in enumerate(n.itertuples(index=False), 1)]
    else:
        res["n100_top20"] = f"not available: export the N100's 6mnth for {d.date()} to {p.name} (symbol,value)"
    return res


def run9(ws: Workspace, universe_csv: Path, orders_csv: Path | None, values_csv: Path | None,
         costs: dict | None = None, start: str = "2022-11-01", end: str = "2026-11-13") -> dict:
    listed = universe_list(Path(universe_csv), ws.cfg.data_dir / "validation" / "run9_universe_no_funds.csv")
    raw = dict(RUN9, universe=f"list:{listed}")
    if costs:
        raw["costs"] = {**raw["costs"], **costs}
    spec = spec_from_dict(raw)
    out = ws.run(spec, period=f"{start}:{end}", kind="engine_validation", start=start, end=end)
    rec = out["record"]
    report = {"price_basis": rec.get("price_basis"),
              "universe_without_funds": int(len(pd.read_csv(listed))),
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
        # Holdings at each month end, the comparison that matters.
        from .universe import to_fmp
        o_f = o.assign(symbol=o["symbol"].map(to_fmp))
        ours_f = out["result"].fills.copy()
        h_n100, h_ours = holdings_by_month(o_f), holdings_by_month(ours_f)
        hm = sorted(set(h_n100) | set(h_ours))
        same = [mm for mm in hm if h_n100.get(mm, set()) == h_ours.get(mm, set())]
        report["months_with_identical_holdings"] = f"{len(same)} of {len(hm)}"
        q_n100, q_ours = positions_by_month(o_f), positions_by_month(ours_f)
        same_q = [mm for mm in hm if same_positions(q_n100.get(mm, {}), q_ours.get(mm, {}))]
        report["months_with_identical_positions (shares and quantities)"] = f"{len(same_q)} of {len(hm)}"

        # Sells month by month, as well as buys.
        s_ours = ours_f[ours_f["side"] == "SELL"].assign(month=ours_f["date"].dt.to_period("M")) \
            .groupby("month")["symbol"].apply(set)
        s_n100 = o_f[o_f["side"].astype(str).str.upper() == "SELL"].assign(month=o_f["date"].dt.to_period("M")) \
            .groupby("month")["symbol"].apply(set)
        sell_diff = [{"month": str(mm), "only_ours": sorted(s_ours.get(mm, set()) - s_n100.get(mm, set())),
                      "only_n100": sorted(s_n100.get(mm, set()) - s_ours.get(mm, set()))}
                     for mm in hm if s_ours.get(mm, set()) != s_n100.get(mm, set())]
        report["months_with_identical_sells"] = f"{len(hm) - len(sell_diff)} of {len(hm)}"
        report["first_month_sells_differ"] = sell_diff[0] if sell_diff else None

        first_set = next((mm for mm in hm if h_n100.get(mm, set()) != h_ours.get(mm, set())), None)
        first_pos = next((mm for mm in hm if mm not in same_q), None)
        first = first_set if first_set is not None else first_pos
        report["first_month_positions_differ"] = None if first_pos is None else str(first_pos)
        if first is not None:
            held_before = h_ours.get(first - 1, set())
            report["first_month_holdings_differ"] = explain_month(
                ws, out, first, h_ours.get(first, set()), h_n100.get(first, set()),
                held_before, set(pd.read_csv(listed)["symbol"]))
            report["first_month_holdings_differ"]["fills_side_by_side"] = fills_side_by_side(o_f, ours_f, first)
            report["first_month_holdings_differ"]["quantities"] = [
                {"symbol": s, "n100": q_n100.get(first, {}).get(s), "ours": q_ours.get(first, {}).get(s)}
                for s in sorted(set(q_n100.get(first, {})) | set(q_ours.get(first, {})))
                if not np.isclose(q_n100.get(first, {}).get(s, 0.0), q_ours.get(first, {}).get(s, 0.0))]
            report["first_month_holdings_differ"]["engine_decisions"] = engine_decisions(
                out["result"].events, first, (set(report["first_month_holdings_differ"]["only_ours"])
                                              | set(report["first_month_holdings_differ"]["only_n100"])
                                              | {r["symbol"] for r in report["first_month_holdings_differ"]["quantities"]}))
            report["first_month_holdings_differ"]["n100_statistics_check"] = statistics_check(
                ws, out, report["first_month_holdings_differ"]["rebalance_date"])
    report["passed"] = bool(report.get("return_ok", False) and report.get("fills_ok", False))
    return report
