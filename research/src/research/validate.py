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
  - n100_run9_asof.csv: the as_of_date of each rebalance's targets, one column (select distinct as_of_date
    from strategy_targets where strategy = 'bt_trend_following_10'). Used first when it is there
  - explain.csv (optional): date, symbol; the report then says which screen kept or dropped each share on
    each decision day (explain in run9_check.json)
  - n100_stats_dates.csv: date (select distinct date from statistics, Oct 2022 to Nov 2026). The N100
    rebalances on the first of those dates on or after the 1st, which includes some holidays and
    Sundays, and fills the next trading day; without it the engine rebalances on the first trading day

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


def write_ours_orders(fills: pd.DataFrame, path: Path) -> Path:
    """The engine's fills in the layout of n100_run9_orders.csv: date, symbol, side, quantity, price (pounds)."""
    f = fills.copy()
    f["symbol"] = f["symbol"].str.replace(r"\.L$", "", regex=True).str.replace("-", ".", regex=False)
    path.parent.mkdir(parents=True, exist_ok=True)
    f[["date", "symbol", "side", "quantity", "price"]].to_csv(path, index=False)
    return path


def n100_style_first_value(out: dict, d0, start_cash: float) -> dict:
    """The first value the way the N100 writes it.

    strategy_write_values runs after the night's fills, which in a backtest happen at once at the next
    price: cash AFTER the first build (its costs and the price move to the next close taken out) plus
    the holdings marked at the decision date's close. Our own series is the other way round: the
    first date is still 100,000 and the build fills the next day. Neither is wrong; they are just not
    the same number on the same date.
    """
    fills = out["result"].fills
    first = fills[fills["date"] == fills["date"].min()] if len(fills) else fills
    buys = first[first["side"] == "BUY"]
    cost = float((buys["consideration"] + buys["stamp_duty"] + buys["spread"] + buys["commission"] + buys["ptm"]).sum())
    close0 = out["market"].close.loc[d0]
    held = float(sum(r.quantity * close0[r.symbol] for r in buys.itertuples()))
    cash = start_cash - cost
    return {"decision_date": str(d0.date()), "fill_date": str(first["date"].min().date()) if len(first) else None,
            "cash_after_first_build": round(cash, 2), "holdings_at_decision_close": round(held, 2),
            "value_as_the_n100_writes_it": round(cash + held, 2), "first_build_cost": round(cost, 2),
            "fills": int(len(buys))}


def day_one_analysis(out: dict, n100: pd.DataFrame, ours: pd.DataFrame, d0, start_cash: float) -> dict:
    """The first build, share by share, to separate price differences from sizing differences.

    For each share bought on day one by both: fill date, quantity and price on each side, and our
    fill price over theirs (1.000 if the price basis and the fill day agree). Then, from the N100's
    quantity and OUR decision-date price, the range of investable amounts that would produce it
    (quantity = floor(weight x investable / price)). If one range fits all fifteen, the sizing base
    differs by a single number and the implied cost multiplier says which (the N100's SLIPPAGE and
    commission constants); if not, the prices or the weights differ and the ratios show where.
    """
    ev = next((e for e in out["result"].events if e["event"] == "sizing" and e["date"] == d0), None)
    if ev is None:
        return {"problem": "no sizing event on the first rebalance date"}
    close0 = out["market"].close.loc[d0]
    w = ev["weight"]

    def first_buys(df):
        d = df.copy()
        d["date"] = pd.to_datetime(d["date"])
        d = d[d["side"].astype(str).str.upper() == "BUY"]
        return d[d["date"] == d["date"].min()].set_index("symbol")
    a, b = first_buys(n100), first_buys(ours)
    rows, lo, hi = [], 0.0, float("inf")
    for s in sorted(set(a.index) & set(b.index)):
        q = float(a.at[s, "quantity"])
        p = float(close0.get(s, np.nan))
        lo_s, hi_s = q * p / w, (q + 1) * p / w
        if np.isfinite(p):
            lo, hi = max(lo, lo_s), min(hi, hi_s)
        rows.append({"symbol": s, "n100_date": str(a.at[s, "date"].date()), "ours_date": str(b.at[s, "date"].date()),
                     "n100_qty": q, "ours_qty": float(b.at[s, "quantity"]),
                     "n100_fill_price": round(float(a.at[s, "price"]), 4), "ours_fill_price": round(float(b.at[s, "price"]), 4),
                     "our_price_over_theirs": round(float(b.at[s, "price"]) / float(a.at[s, "price"]), 4),
                     "our_decision_price": None if not np.isfinite(p) else round(p, 4),
                     "n100_implied_investable": None if not np.isfinite(p) else round((q + 0.5) * p / w, 2)})
    consistent = lo <= hi
    ratios = [r["our_price_over_theirs"] for r in rows]
    res = {"decision_date": str(d0.date()), "weight": w, "our_investable": round(ev["investable"], 2),
           "our_multiplier": round(ev["multiplier"], 5), "start_cash": start_cash,
           "only_n100": sorted(set(a.index) - set(b.index)), "only_ours": sorted(set(b.index) - set(a.index)),
           "fill_price_ratio_min_max": [min(ratios), max(ratios)] if ratios else None,
           "shares": rows}
    if consistent and np.isfinite(hi):
        res["one_investable_fits_all"] = True
        res["n100_investable_between"] = [round(lo, 2), round(hi, 2)]
        res["n100_implied_multiplier_between"] = [round(start_cash / hi, 5), round(start_cash / lo, 5)]
        res["reading"] = ("sizing base differs by one number: compare the implied multiplier with ours "
                          f"({ev['multiplier']:.5f}); 1.0075 would be the 25 bp constant STRATEGY_SLIPPAGE_BPS, "
                          "1.006 the 10 bp spread, and a commission shows as a lower investable")
    else:
        res["one_investable_fits_all"] = False
        res["reading"] = ("no single investable reproduces all the N100's quantities from our decision prices: "
                          "the decision prices or the weights differ. See the fill price ratios and the shares "
                          "whose implied investable is far from the others")
    return res


def watch_shares(out: dict, n100: pd.DataFrame, ours: pd.DataFrame, shares: list[str], rebalances: int = 8) -> dict:
    """Each share's fills on both sides and, at the first rebalances, our rank and whether either side held it."""
    mk, scores = out["market"], out["scores"]
    n, o = n100.copy(), ours.copy()
    for df in (n, o):
        df["date"] = pd.to_datetime(df["date"])
        df["side"] = df["side"].astype(str).str.upper()
        df["signed"] = np.where(df["side"] == "BUY", 1.0, -1.0) * df["quantity"].astype(float)
    res = {}
    for s in shares:
        rows = []
        for d in sorted(scores)[:rebalances]:
            sc = scores[d].dropna().sort_values(ascending=False)
            rank = int(list(sc.index).index(s)) + 1 if s in sc.index else None
            rows.append({"date": str(d.date()), "our_rank": rank, "candidates": int(len(sc)),
                         "we_hold": float(o[(o["symbol"] == s) & (o["date"] <= d)]["signed"].sum()),
                         "n100_holds": float(n[(n["symbol"] == s) & (n["date"] <= d)]["signed"].sum())})
        res[s] = {"our_fills": [(str(r.date.date()), r.side, float(r.quantity), round(float(r.price), 4))
                                for r in o[o["symbol"] == s].itertuples()][:12],
                  "n100_fills": [(str(r.date.date()), r.side, float(r.quantity), round(float(r.price), 4))
                                 for r in n[n["symbol"] == s].itertuples()][:12],
                  "at_first_rebalances": rows}
    return res


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
    ours_6m = ctx.n100_return(d, 6) * 100                 # the N100 stores per cent
    common = [s for s in n.index if s in ours_close.index and pd.notna(ours_close[s]) and ours_close[s] > 0
              and pd.notna(n.at[s, "close_price"]) and n.at[s, "close_price"] > 0]
    ratio = pd.Series({s: n.at[s, "close_price"] / ours_close[s] for s in common})
    # The N100 stores London prices in pence and ours are in pounds, so the ratio is about 100; the
    # baseline is whatever the bulk of the shares agree on, and only departures from it are gaps.
    base = float(ratio.median()) if len(ratio) else 100.0
    rows, screen_diff, unit_mismatch = [], [], []
    for s in n.index:
        if s not in ours_close.index:
            rows.append({"symbol": s, "problem": "no prices in our data"})
            continue
        nc, nm = n.at[s, "close_price"], n.at[s, "ma200"]
        n_above = bool(pd.notna(nc) and pd.notna(nm) and nc > nm)
        o_above = bool(pd.notna(ours_close[s]) and pd.notna(ours_ma[s]) and ours_close[s] > ours_ma[s])
        if s in ratio.index and not 0.999 < ratio[s] / base < 1.001:
            k = ratio[s] / base
            unit_mismatch.append({"symbol": s, "n100_close": float(nc), "our_close_pounds": round(float(ours_close[s]), 4),
                                  "ratio_to_baseline": round(float(k), 4),
                                  "reading": ("a x100 unit difference between the N100's series and ours" if 90 < k < 110 or 0.009 < k < 0.011
                                              else "values differ")})
        if n_above != o_above:
            screen_diff.append({"symbol": s, "n100_close": float(nc), "n100_ma200": None if pd.isna(nm) else float(nm),
                                "our_close_pounds": None if pd.isna(ours_close[s]) else round(float(ours_close[s]), 4),
                                "our_ma200_pounds": None if pd.isna(ours_ma[s]) else round(float(ours_ma[s]), 4),
                                "n100_above": n_above, "our_above": o_above, "n100_has_ma200": bool(pd.notna(nm)),
                                "n100_close_over_ma200": None if pd.isna(nm) or nm == 0 else round(float(nc / nm), 4),
                                "our_close_over_ma200": None if pd.isna(ours_ma[s]) or ours_ma[s] == 0 else round(float(ours_close[s] / ours_ma[s]), 4),
                                "our_price_unit_steps": price_unit_steps(mk.close[s])[:6] if s in mk.close.columns else []})
        # Only real gaps: the N100's value, scaled to our units by the baseline, against ours.
        for name, ours_v, theirs, scale in (("close_price", ours_close[s], nc, base), ("ma200", ours_ma[s], nm, base),
                                            ("6mnth", ours_6m.get(s, np.nan), n.at[s, "6mnth"] if "6mnth" in n else np.nan, 1.0)):
            if pd.notna(ours_v) and pd.notna(theirs) and theirs != 0 and ours_v != 0:
                k = (theirs / scale) / ours_v
                if abs(k - 1) > 0.001 and not any(u["symbol"] == s for u in unit_mismatch):
                    rows.append({"symbol": s, "indicator": name, "n100": float(theirs), "ours": round(float(ours_v), 4),
                                 "n100_over_ours_after_units": round(float(k), 4)})
    only_n100 = sorted(set(n.index) - set(ours_close.dropna().index))
    return {"file": p.name, "shares_in_file": int(len(n)), "unit_baseline_n100_over_ours": round(base, 4),
            "screen_membership_differs": screen_diff, "unit_mismatches": unit_mismatch[:40],
            "real_gaps_over_0.1pct (units normalised)": rows[:60], "n100_only_symbols": only_n100[:30]}


def price_unit_steps(close: pd.Series) -> list[dict]:
    """One-day steps of 70 to 140 times (or the inverse) in a price series: the signature of a unit
    change (pence read as pounds, or the reverse), or a share consolidation that was not adjusted."""
    from .clean import _is_step
    s = close.dropna()
    if len(s) < 2:
        return []
    r = (s.to_numpy()[1:] / s.to_numpy()[:-1])
    hit = np.where(_is_step(r))[0]
    return [{"date": str(s.index[k + 1].date()), "ratio": round(float(r[k]), 3),
             "before": round(float(s.iloc[k]), 4), "after": round(float(s.iloc[k + 1]), 4)} for k in hit]


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


def n100_asof_dates(stats_dates_csv: Path, start: str, end: str) -> list[str]:
    """The N100's rebalance as-of dates: for each month, the first date in its statistics calendar
    on or after the 1st. stats_dates_csv has one column, date (select distinct date from statistics)."""
    d = pd.to_datetime(pd.read_csv(stats_dates_csv)["date"]).drop_duplicates().sort_values()
    out = []
    for m in pd.period_range(pd.Timestamp(start), pd.Timestamp(end), freq="M"):
        hit = d[d >= m.to_timestamp()]
        if len(hit) and hit.iloc[0].to_period("M") == m:
            out.append(str(hit.iloc[0].date()))
    return out


def explain_screens(out: dict, ws: Workspace, pairs: list[tuple[str, str]], asof=None) -> list[dict]:
    """For each (date, symbol): which screen kept or dropped the share on that decision day.

    Universe membership, a price, the 200-day average (and how many prices stand behind it), above it,
    the six-month return with its two base dates, and the score and rank among the scored shares.
    """
    from .signals.base import Context
    from .universe import to_fmp
    mk, member, scores = out["market"], out["member"], out["scores"]
    ctx = Context(mk, ws.f)
    for a in pd.DatetimeIndex(pd.to_datetime(list(asof or []))):
        k = ctx.pos(a)
        if k >= 0:
            ctx.asof[mk.dates[k]] = a
    rows = []
    for date, sym in pairs:
        s = to_fmp(sym)
        # The decision day the engine used: the last scored day on or before the date, so the scores are the
        # ones it ranked on (a holiday or weekend row in the price calendar must not hide them).
        keys = sorted(scores)
        k = int(np.searchsorted(np.array(keys, dtype="datetime64[ns]"), np.datetime64(pd.Timestamp(date)), side="right")) - 1
        d = keys[k] if k >= 0 else None
        i = ctx.pos(d) if d is not None else -1
        r = {"date": str(date), "decision_day": None if d is None else f"{d.date()} ({d.day_name()})", "symbol": s}
        if d is not None:
            r["prices_in_calendar_that_day"] = int(mk.close.loc[d].notna().sum())
        if d is None or s not in mk.adj.columns:
            rows.append({**r, "dropped_by": "no price column for the symbol" if s not in mk.adj.columns else "no decision day"})
            continue
        det = ctx.n100_return_detail(d, 6).loc[s]
        cl, ma = mk.adj[s].iloc[i], mk.ma200[s].iloc[i]
        sc = scores.get(d, pd.Series(dtype=float)).dropna().sort_values(ascending=False)
        in_u = bool(member.at[d, s]) if (d in member.index and s in member.columns) else False
        r.update({"in_universe": in_u, "adj_close": None if pd.isna(cl) else round(float(cl), 4),
                  "ma200": None if pd.isna(ma) else round(float(ma), 4),
                  "prices_to_date": int(mk.adj[s].iloc[: i + 1].notna().sum()),
                  "return_pct": None if pd.isna(det["ret"]) else round(float(det["ret"]) * 100, 3),
                  "base_date": det["start"], "end_date": det["end"],
                  "score_pct": None if s not in sc.index else round(float(sc[s]) * 100, 3),
                  "rank": None if s not in sc.index else int(sc.index.get_loc(s)) + 1, "scored": int(len(sc))})
        if not in_u:
            r["dropped_by"] = "not in the run 9 universe on that date"
        elif pd.isna(cl):
            r["dropped_by"] = "no price on or before the date"
        elif pd.isna(ma):
            r["dropped_by"] = "no 200-day average (fewer than 200 prices)"
        elif not cl > ma:
            r["dropped_by"] = "at or below its 200-day average"
        elif pd.isna(det["ret"]):
            r["dropped_by"] = "no six-month return"
        else:
            r["dropped_by"] = None
        rows.append(r)
    return rows


def run9(ws: Workspace, universe_csv: Path, orders_csv: Path | None, values_csv: Path | None,
         costs: dict | None = None, start: str = "2022-11-01", end: str = "2026-11-13") -> dict:
    listed = universe_list(Path(universe_csv), ws.cfg.data_dir / "validation" / "run9_universe_no_funds.csv")
    raw = dict(RUN9, universe=f"list:{listed}")
    vdir = ws.cfg.data_dir / "validation"
    actual = vdir / "n100_run9_asof.csv"
    asof_file = vdir / "n100_stats_dates.csv"
    if actual.exists():
        # The as_of_date of each rebalance's targets (strategy_targets), as the N100 used it.
        col = pd.read_csv(actual)
        raw["rebalance_asof"] = sorted(pd.to_datetime(col[col.columns[0]]).dt.strftime("%Y-%m-%d").unique())
        raw["_asof_source"] = "the N100's own targets (n100_run9_asof.csv)"
    elif asof_file.exists():
        raw["rebalance_asof"] = n100_asof_dates(asof_file, start, end)
        raw["_asof_source"] = "first date of the N100 statistics calendar on or after the 1st (a guess)"
    if costs:
        raw["costs"] = {**raw["costs"], **costs}
    spec = spec_from_dict(raw)
    out = ws.run(spec, period=f"{start}:{end}", kind="engine_validation", start=start, end=end)
    rec = out["record"]
    report = {"price_basis": rec.get("price_basis"),
              "rebalance_asof": "%s, %d dates" % (raw["_asof_source"], len(raw["rebalance_asof"]))
              if "rebalance_asof" in raw else "first trading day of the month (no as-of file in data/validation)",
              "universe_without_funds": int(len(pd.read_csv(listed))),
              "ours_total_return": rec["total_return"], "ours_fills": rec["fills"],
              "ours_costs": rec["costs_paid"],
              "report": str(out.get("report"))}
    ours_path = write_ours_orders(out["result"].fills, ws.cfg.data_dir / "validation" / "run9_ours_orders.csv")
    report["ours_orders_file"] = str(ours_path)
    report["our_top_scores_first_rebalances"] = {
        str(d.date()): {k.replace(".L", ""): round(float(v), 3)
                        for k, v in out["scores"][d].dropna().sort_values(ascending=False).head(25).items()}
        for d in sorted(out["scores"])[:4]}
    ex = vdir / "explain.csv"
    if ex.exists():
        pr = pd.read_csv(ex)
        report["explain"] = explain_screens(out, ws, list(zip(pr["date"], pr["symbol"])), raw.get("rebalance_asof"))
    if ex.exists():
        months = {str(x)[:7] for x in pr["date"]}
        report["engine_events"] = {
            mth: [{k: (str(v.date()) if isinstance(v, pd.Timestamp) else v) for k, v in e.items()}
                  for e in out["result"].events if str(e["date"])[:7] == mth][:400]
            for mth in sorted(months)}
    d0 = min(out["scores"])
    report["first_value_the_n100_way"] = n100_style_first_value(out, d0, float(raw["start_cash"]))
    if values_csv:
        v = pd.read_csv(values_csv, parse_dates=["date"]).sort_values("date")
        # From the starting cash, not the first row: the N100's first value
        # (98,723.75 on 1 Nov 2022) is already after the first build's costs,
        # and its stated +104.43% is 204,432.69 over 100,000.
        theirs = float(v["total"].iloc[-1] / float(raw["start_cash"]) - 1)
        report["n100_first_value"] = float(v["total"].iloc[0])
        report["first_value_gap_the_n100_way"] = round(
            report["first_value_the_n100_way"]["value_as_the_n100_writes_it"] - float(v["total"].iloc[0]), 2)
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
        report["first_value_check"] = ("matches" if abs(report.get("first_value_gap_the_n100_way", 1e9)) < 5
                                       else "differs: see first_month_quantities_differ.day_one")
        first = first_set if first_set is not None else first_pos
        report["first_month_positions_differ"] = None if first_pos is None else str(first_pos)
        if first_pos is not None:
            qd = [{"symbol": s, "n100": q_n100.get(first_pos, {}).get(s), "ours": q_ours.get(first_pos, {}).get(s)}
                  for s in sorted(set(q_n100.get(first_pos, {})) | set(q_ours.get(first_pos, {})))
                  if not np.isclose(q_n100.get(first_pos, {}).get(s, 0.0), q_ours.get(first_pos, {}).get(s, 0.0), atol=0.5)]
            report["first_month_quantities_differ"] = {
                "month": str(first_pos), "shares_that_differ": len(qd), "quantities": qd,
                "fills_side_by_side": fills_side_by_side(o_f, ours_f, first_pos),
                "engine_decisions": engine_decisions(out["result"].events, first_pos, {r["symbol"] for r in qd}),
                "day_one": day_one_analysis(out, o_f, ours_f, d0, float(raw["start_cash"]))
                if first_pos == min(h_ours) else "not the first month"}
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
        fm = report.get("first_month_holdings_differ", {})
        report["watch_shares"] = watch_shares(out, o_f, ours_f, sorted({"HFD.L", "KNOS.L", "IHP.L"}
                                                                       | set(fm.get("only_ours", [])) | set(fm.get("only_n100", []))))
    report["passed"] = bool(report.get("return_ok", False) and report.get("fills_ok", False))
    return report
