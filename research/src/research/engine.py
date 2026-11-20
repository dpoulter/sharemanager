"""The portfolio simulation.

The same rules as the N100 engine (includes/strategy_functions.php):

- decide on the close of the rebalance date, fill at the next trading day's
  close;
- equal weight at one over the number of HOLDINGS, floored to six decimals,
  so a thin selection holds cash rather than concentrating;
- sized against the account value less the costs of buying it, rounded down;
- an optional buffer: a holding is kept while it ranks inside `hold_rank`;
- a 2% drift band: a holding near its target weight is left alone;
- orders under the minimum are skipped;
- an optional turnover cap that refuses a whole rebalance rather than
  trimming it (min(buys, sells) / value, the first build exempt);
- an optional exit between rebalances: closed below the 200-day average on
  two consecutive closes, sold at the next close, cash held to the next
  rebalance;
- a company that stops trading leaves at its last price on its last day
  (taken over or failed alike), and the cash is held until the next
  rebalance.

Returns use the dividend-adjusted close; share counts, minimum orders and
costs use the raw close in pounds. A position is held as "units" of the
adjusted series, so its value tracks total return, and is converted back to
shares at the raw close whenever it is traded.
"""
from __future__ import annotations

import math
from dataclasses import dataclass, field

import numpy as np
import pandas as pd

from .costs import Costs
from .store import Market

WEIGHT_DECIMALS = 1_000_000


def n100_symbol_key(symbol: str) -> str:
    """The order the N100 handles shares in: its own symbol, as MySQL sorts it.

    strategy_plan_orders reads strategy_targets (primary key strategy, as_of_date, symbol) with no
    ORDER BY, so targets come back in SYMBOL order, not rank order; held shares that are no longer
    targets (strategy_positions, primary key strategy, symbol) follow in symbol order. The executor
    then puts sells before buys with a usort that returns 0 for equal sides, which PHP 8 keeps stable.
    So cash runs out on the buy that is last alphabetically, not the lowest ranked. FMP's BT-A.L is
    the N100's BT.A.
    """
    s = str(symbol)
    s = s[:-2] if s.endswith(".L") else s
    return s.replace("-", ".").upper()


@dataclass
class Rules:
    holdings: int | str = 15          # an int, or "quintile" / "decile" of the universe
    rebalance: str = "monthly"        # monthly | weekly | quarterly
    hold_rank: int | None = None
    exit_ma200: bool = False
    drift_band: float = 0.02
    max_turnover: float | None = None
    start_cash: float = 100_000.0
    # The N100 accepts a share with a price on or before the date, traded that day or not
    # (strategy_price_asof), and refuses a rebalance of more than 50 orders
    # (STRATEGY_MAX_ORDERS_PER_RUN). Research defaults are stricter on the first and unlimited on the second.
    require_traded_today: bool = True
    max_orders: int | None = None
    # The N100 rebalances on the first date of its own statistics calendar on or after the 1st, which
    # includes some holidays and Sundays. Its data is then the last close on or before that date and it
    # fills the next trading day. With these dates (monthly only) the decision is taken on the last
    # trading day on or before each one, so the fill is the next trading day, as the N100's is.
    rebalance_asof: tuple | None = None
    # Engine check only: decision day -> the N100's own target symbols for that rebalance. With these the
    # selection is skipped and the N100's list is held (exits, sizing, costs and fills are still ours), so
    # a gap left over is the engine's, not the data's or the ranking's.
    fixed_targets: dict | None = None

    def n_holdings(self, universe_size: int) -> int:
        if isinstance(self.holdings, str):
            frac = {"quintile": 5, "decile": 10, "tercile": 3}[self.holdings]
            return max(1, math.ceil(universe_size / frac))
        return int(self.holdings)


@dataclass
class Result:
    values: pd.DataFrame                 # date: cash, holdings_value, total, holdings
    fills: pd.DataFrame                  # one row per fill
    pnl: pd.Series                       # profit per symbol, pounds
    refusals: int = 0
    skipped: int = 0
    notes: list[str] = field(default_factory=list)
    # What the engine decided and why, per rebalance: skipped for the drift band, under the minimum
    # order, rounded to nothing, trimmed to fit cash, refused. validate prints these for a month.
    events: list[dict] = field(default_factory=list)


def rebalance_flags(dates: pd.DatetimeIndex, how: str, as_of=None) -> np.ndarray:
    if as_of is not None and how == "monthly":
        flags = np.zeros(len(dates), dtype=bool)
        for a in pd.DatetimeIndex(pd.to_datetime(list(as_of))):
            i = int(dates.searchsorted(a, side="right")) - 1       # the last trading day on or before
            if i >= 0:
                flags[i] = True
        return flags
    s = pd.Series(np.arange(len(dates)), index=dates)
    if how == "monthly":
        key = [dates.year, dates.month]
    elif how == "weekly":
        iso = dates.isocalendar()
        key = [iso.year.to_numpy(), iso.week.to_numpy()]
    elif how == "quarterly":
        key = [dates.year, dates.quarter]
    elif how == "daily":
        return np.ones(len(dates), dtype=bool)
    else:
        raise ValueError(f"unknown rebalance {how!r}")
    first = s.groupby(key).transform("min").to_numpy()
    return first == np.arange(len(dates))


def ma200_below_twice(adj: pd.DataFrame, ma: pd.DataFrame | None = None) -> pd.DataFrame:
    """True where the close is below its 200-day average today and the previous close."""
    if ma is None:
        from .store import moving_average
        ma = moving_average(adj, 200)
    # The N100 reads two rows of statistics (close_price, ma200), written for every trading date
    # with the last price carried over a day the share did not trade: so a no-trade day is still
    # "below" if the carried close is, and does not break the two in a row.
    filled = adj.ffill(limit=10)
    below = (filled < ma) & ma.notna()
    return below & below.shift(1, fill_value=False)


def ma200_below_twice_calendar(adj: pd.DataFrame, ma: pd.DataFrame, calendar) -> pd.DataFrame:
    """The same exit as ma200_below_twice, counted on the N100's statistics calendar.

    The N100 writes a statistics row on some Sundays and holidays, carrying the last close and average
    over, so a Friday below the average followed by a Sunday row is "two days below" and the share is
    sold (AO, 26 Feb 2023). Each calendar row is below if the last price on or before it is below the
    average on that day; two rows in a row are the signal; the flag lands on the last trading day on or
    before the row, and the order fills the trading day after. Trading days missing from the calendar
    are added, so with no weekend rows this is the same as the plain rule.
    """
    filled = adj.ffill(limit=10)
    below = ((filled < ma) & ma.notna()).to_numpy()
    dates = adj.index
    cal = pd.DatetimeIndex(sorted(set(pd.to_datetime(list(calendar))) | set(dates)))
    cal = cal[cal >= dates[0]]
    pos = dates.searchsorted(cal, side="right") - 1
    b = below[pos]
    prev = np.vstack([np.zeros((1, b.shape[1]), dtype=bool), b[:-1]])
    out = np.zeros(below.shape, dtype=bool)
    np.logical_or.at(out, pos, b & prev)
    return pd.DataFrame(out, index=dates, columns=adj.columns)


def simulate(m: Market, member: pd.DataFrame, large: pd.DataFrame, scores: dict,
             rules: Rules, costs: Costs, start, end, exit_flags: pd.DataFrame | None = None) -> Result:
    """Run one portfolio.

    `scores` maps each rebalance date (Timestamp) to a Series of scores per
    symbol, higher is better, NaN meaning not eligible. Only symbols that
    are universe members and priced on the date are ranked.
    """
    dates = m.dates[(m.dates >= pd.Timestamp(start)) & (m.dates <= pd.Timestamp(end))]
    if len(dates) < 2:
        raise ValueError("period has fewer than two trading days")
    syms = list(m.close.columns)
    col = {s: i for i, s in enumerate(syms)}
    adj = m.adj.loc[dates].ffill(limit=10).to_numpy()
    close = m.close.loc[dates].ffill(limit=10).to_numpy()
    traded_today = m.close.loc[dates].notna().to_numpy()
    lg = large.reindex(index=dates, columns=syms).fillna(False).to_numpy()
    aim = m.master.reindex(syms).get("is_aim", pd.Series(False, index=syms)).fillna(False).astype(bool).to_numpy()
    last_day = np.full(len(syms), -1)
    pos_of = {d: i for i, d in enumerate(dates)}
    for s, d in m.last_price.items():
        if s in col and m.gone.get(s, False) and d in pos_of:
            last_day[col[s]] = pos_of[d]
    ex = exit_flags.reindex(index=dates, columns=syms).fillna(False).to_numpy() if exit_flags is not None else None
    rebal = rebalance_flags(dates, rules.rebalance, rules.rebalance_asof)
    rebal[0] = True     # the first build is always due

    cash = float(rules.start_cash)
    units = np.zeros(len(syms))
    flows = np.zeros(len(syms))      # cash paid out (negative) and received per symbol
    pending: list[tuple[int, str, float, str]] = []   # (col, side, shares, reason)
    fills = []
    values = []
    events: list[dict] = []
    refusals = skipped = 0
    built = False

    def value_at(i):
        held = units > 0
        return cash + float(np.nansum(units[held] * adj[i, held]))

    def shares_held(j, i):
        return units[j] * adj[i, j] / close[i, j] if units[j] > 0 and close[i, j] > 0 else 0.0

    def round_down(q):
        if costs.decimals <= 0:
            return float(math.floor(q + 1e-9))
        f = 10 ** costs.decimals
        return math.floor(q * f + 1e-9) / f

    for i, d in enumerate(dates):
        # 1. Yesterday's orders fill at today's close: sells first, so a
        #    rebalance's buys can use their cash.
        if pending:
            for j, side, q, reason in sorted(pending, key=lambda o: o[1] != "SELL"):
                p = close[i, j]
                if not (p > 0) or not np.isfinite(p):
                    continue
                if side == "SELL":
                    have = shares_held(j, i)
                    q = have if reason != "rebalance" else min(q, have)
                    if q <= 0:
                        continue
                    c = costs.sell(q, p, lg[i, j], aim[j])
                    units[j] -= units[j] * (q / have) if have > 0 else units[j]
                    if units[j] < 1e-12 or q >= have - 1e-9:
                        units[j] = 0.0
                else:
                    c = costs.buy(q, p, lg[i, j], aim[j])
                    if -c["cash"] > cash:
                        # Not enough cash at the fill price: buy what it pays for.
                        unit_cost = p * (1 + costs.stamp(aim[j]) + costs.spread(lg[i, j]))
                        q0 = q
                        q = round_down(max(0.0, (cash - costs.commission - costs.ptm(cash)) / unit_cost))
                        # strategy_fill_order trims to what the cash covers and rejects only when that
                        # is nothing: there is no minimum order at the fill.
                        if q <= 0:
                            skipped += 1
                            events.append({"date": d, "symbol": syms[j], "event": "buy dropped: not enough cash",
                                           "detail": f"wanted {q0:g}, cash {cash:,.2f}"})
                            continue
                        events.append({"date": d, "symbol": syms[j], "event": "buy trimmed to fit cash",
                                       "detail": f"{q0:g} -> {q:g}"})
                        c = costs.buy(q, p, lg[i, j], aim[j])
                    units[j] += q * p / adj[i, j]
                cash += c["cash"]
                flows[j] += c["cash"]
                fills.append((d, syms[j], side, q, p, c["consideration"], c["stamp_duty"],
                              c["spread"], c["commission"], c["ptm"], reason))
            pending = []
            built = True

        # 2. A company on its last day leaves at today's close.
        gone_today = np.where((last_day == i) & (units > 0))[0]
        for j in gone_today:
            p = close[i, j]
            q = shares_held(j, i)
            c = costs.sell(q, p, lg[i, j], aim[j])
            cash += c["cash"]
            flows[j] += c["cash"]
            units[j] = 0.0
            fills.append((d, syms[j], "SELL", q, p, c["consideration"], 0.0, c["spread"],
                          c["commission"], c["ptm"], "delisted"))

        total = value_at(i)
        values.append((d, cash, total - cash, total, int((units > 0).sum())))
        if i == len(dates) - 1:
            break

        # 3. Rebalance, decided on today's close.
        if rebal[i]:
            plan, refused, n_skip = _plan(i, d, scores.get(d), member, syms, col, units, adj, close,
                                          traded_today, lg, aim, total, rules, costs, round_down,
                                          shares_held, first_build=not built and not (units > 0).any(),
                                          events=events)
            refusals += refused
            skipped += n_skip
            pending = plan
            continue

        # 4. Exits between rebalances.
        if ex is not None:
            for j in sorted(np.where((units > 0) & ex[i])[0], key=lambda j: n100_symbol_key(syms[j])):
                pending.append((j, "SELL", shares_held(j, i), "below_ma200_2d"))

    vals = pd.DataFrame(values, columns=["date", "cash", "holdings_value", "total", "holdings"]).set_index("date")
    f = pd.DataFrame(fills, columns=["date", "symbol", "side", "quantity", "price", "consideration",
                                     "stamp_duty", "spread", "commission", "ptm", "reason"])
    last = len(dates) - 1
    final_value = np.where(units > 0, units * np.nan_to_num(adj[last]), 0.0)
    pnl = pd.Series(flows + final_value, index=syms)
    pnl = pnl[(flows != 0) | (final_value != 0)]
    return Result(values=vals, fills=f, pnl=pnl, refusals=refusals, skipped=skipped, events=events)


def _plan(i, d, score, member, syms, col, units, adj, close, traded, lg, aim, total, rules, costs,
          round_down, shares_held, first_build, events=None):
    """Orders for one rebalance. Returns (orders, refused, skipped)."""
    events = events if events is not None else []
    orders, skipped = [], 0
    if score is None:
        score = pd.Series(dtype=float)
    mem = member.loc[d] if d in member.index else pd.Series(dtype=bool)
    universe = [s for s in mem.index[mem.to_numpy()] if s in col]
    n = rules.n_holdings(len(universe))
    s = score.reindex(universe)
    if rules.require_traded_today:
        ok = s.notna() & np.array([traded[i, col[x]] and close[i, col[x]] > 0 for x in s.index], dtype=bool)
    else:        # a price on or before the date is enough, as strategy_price_asof
        ok = s.notna() & np.array([close[i, col[x]] > 0 for x in s.index], dtype=bool)
    # Highest score first, ties broken by symbol, as the N100 orders by value then symbol.
    cand = s[ok]
    ranked = pd.Series(cand.values, index=cand.index).iloc[
        np.lexsort((np.array(cand.index, dtype=str), -cand.to_numpy(dtype=float)))]
    depth = n if rules.hold_rank is None else max(n, int(rules.hold_rank))
    top = list(ranked.index[:depth])
    if rules.hold_rank is None:
        chosen = top[:n]
    else:
        held = {syms[j] for j in np.where(units > 0)[0]}
        keeping = [x for x in top if x in held][:n]
        chosen = keeping + [x for x in top if x not in held][: n - len(keeping)]

    if rules.fixed_targets is not None:
        given = rules.fixed_targets.get(d, [])
        chosen = [x for x in given if x in col and close[i, col[x]] > 0]
        events.append({"date": d, "symbol": "(all)", "event": "targets replayed from the N100",
                       "detail": f"{len(chosen)} of {len(given)} given; not priced or not held in our data: "
                                 f"{sorted(set(given) - set(chosen))}"})
    weight = math.floor((1.0 / n) * WEIGHT_DECIMALS) / WEIGHT_DECIMALS if n else 0.0
    mult = 1 + costs.stamp_duty + max(costs.spread(True), costs.spread(False))
    investable = (total - costs.commission * len(chosen)) / mult
    buys = sells = 0.0
    want = set(chosen)
    events.append({"date": d, "symbol": "(all)", "event": "sizing", "investable": investable, "weight": weight,
                   "account_value": total, "multiplier": mult, "holdings_wanted": n,
                   "detail": f"account value {total:,.2f}, investable {investable:,.2f} "
                             f"(= value less commission, over {mult:.4f}), weight {weight}, {len(chosen)} chosen of {n}"})
    # Targets are planned in symbol order (see n100_symbol_key), which is also the order they are
    # filled in: the cash trim falls on the last buy alphabetically.
    for x in sorted(chosen, key=n100_symbol_key):
        j = col[x]
        p = close[i, j]
        target = round_down(weight * investable / p)
        held = shares_held(j, i)
        if held > 0 and abs(held * p / total - weight) <= rules.drift_band:
            events.append({"date": d, "symbol": x, "event": "held, inside the drift band: left alone",
                           "detail": f"held weight {held * p / total:.4f} target {weight:.4f} (band {rules.drift_band})"})
            continue
        delta = target - held
        value = abs(delta) * p
        if delta == 0:
            continue
        if value < costs.minimum_order:
            skipped += 1
            events.append({"date": d, "symbol": x, "event": "under the minimum order",
                           "detail": f"{delta:+g} shares = {value:,.2f}"})
            continue
        if delta > 0:
            orders.append((j, "BUY", delta, "rebalance"))
            buys += value
        else:
            orders.append((j, "SELL", -delta, "rebalance"))
            sells += value
        events.append({"date": d, "symbol": x, "event": "ordered " + ("top-up" if held > 0 and delta > 0
                                                                      else "trim" if delta < 0 else "new holding"),
                       "detail": f"held {held:g}, target {target:g}, delta {delta:+g}, "
                                 f"held weight {held * p / total:.4f}, target {weight:.4f}"})
    for j in sorted(np.where(units > 0)[0], key=lambda j: n100_symbol_key(syms[j])):
        if syms[j] not in want:
            q = shares_held(j, i)
            orders.append((j, "SELL", q, "no_longer_selected"))
            sells += q * close[i, j] if close[i, j] > 0 else 0
    if rules.max_orders is not None and len(orders) > rules.max_orders:
        events.append({"date": d, "symbol": "(all)", "event": "rebalance refused: too many orders",
                       "detail": f"{len(orders)} orders, limit {rules.max_orders}"})
        return [], 1, skipped
    if (rules.max_turnover is not None and not first_build and total > 0
            and min(buys, sells) / total > rules.max_turnover):
        events.append({"date": d, "symbol": "(all)", "event": "rebalance refused: turnover",
                       "detail": f"{min(buys, sells) / total:.3f} over {rules.max_turnover}"})
        return [], 1, skipped
    return orders, 0, skipped


def benchmarks(m: Market, member: pd.DataFrame, start, end) -> pd.DataFrame:
    """Equal-weight and cap-weighted universe, rebased to 1.

    Total return when `m` is the adjusted market (Workspace.run always passes it, whatever the
    strategy's price basis: a benchmark is a total-return index). Each day's return of each
    member is weighted equally, or by its market cap at the previous close, which is what holding
    the index does. Market caps before 2016 include some known bad values that are kept
    (Dale's decision), so a cap-weighted benchmark before 2016 rests on unchecked caps.
    """
    dates = m.dates[(m.dates >= pd.Timestamp(start)) & (m.dates <= pd.Timestamp(end))]
    adj = m.adj.reindex(columns=member.columns).loc[dates]
    ret = adj.ffill(limit=10).pct_change(fill_method=None)
    mem = member.reindex(dates).shift(1, fill_value=False).astype(bool)
    r = ret.where(mem)
    # A company that stopped trading contributes its last return and then nothing.
    ew = r.mean(axis=1).fillna(0.0)
    cap = m.market_cap.reindex(index=dates, columns=member.columns).ffill(limit=10).shift(1)
    w = cap.where(mem & r.notna())
    cw = (r * w).sum(axis=1) / w.sum(axis=1)
    cw = cw.fillna(0.0)
    ew.iloc[0] = cw.iloc[0] = 0.0
    return pd.DataFrame({"equal_weight": (1 + ew).cumprod(), "cap_weight": (1 + cw).cumprod()})
