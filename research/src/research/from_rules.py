"""The rules_translator module (contracts/modules.yaml): a sharemanager rules
snapshot in, the engine's strategy out, or a refusal that names the rule.

Nothing is dropped and nothing is guessed. Every field of the snapshot is read,
and a value this module has no exact translation for raises Untranslatable with
the rule's name. The translation rules live here and nowhere else: the worker
hands the claim over and runs what comes back.

What the snapshot looks like (sharemanager includes/rules_fingerprint.php):

    {"rules": {"selection": "screen" | "score", "screen_id", "score_indicator",
               "rank_by", "holdings", "hold_rank", "rebalance", "exit_rules",
               "exclude_funds"},
     "criteria": [[indicator_id, operator, first_operand, second_operand], ...]}

The claim adds `indicators` (indicator_id -> {name, rank_order}) and
`rank_orders` (name -> rank_order) so the ids can be read; sharemanager's
rank_order is 'value DESC' or 'value ASC', and anything else is read as DESC,
which is what sharemanager's screen_rank_order() does.

Indicators the engine computes, by sharemanager name:

    close_price     the latest close on the run's price basis
    ma50, ma200     the 50 and 200 day averages of it
    1mnth 3mnth 6mnth 12mnth
                    the N100's calendar-month return (Context.n100_return), in
                    per cent as sharemanager stores it, so a constant such as
                    "6mnth > 0" means the same thing on both sides

A ranking indicator may also be a research signal by name, written
`research:<signal>` (signals/): value and quality signals read accounts
through pit.Fundamentals, so they go through the engine's publication lag.
Sharemanager's own value and quality figures (pe, value_score and the rest)
are not recomputed here: the engine has no definition that is theirs, so a
snapshot that uses one is refused, naming it.

Screens: comparison criteria (> >= < <= = !=) against a constant or another
indicator, intersected, then top_n criteria in criteria order, each keeping the
best N of what is left, as build_screen() applies them. Selection 'score' is
every share with a value for the score indicator. rank_by (or the score
indicator) orders the result.

A screen that is exactly an engine strategy already validated against the N100
(CANONICAL) is given that engine signal by name, so the trend following rules
become the strategy the run 9 replay check passed with.

The engine runs a sharemanager rule the N100's way: raw closes with no
dividends credited, a price on or before the day is enough, and at most 50
orders a rebalance (validate.RUN9).
"""
from __future__ import annotations

import hashlib
import json
import math
import operator as op

import numpy as np
import pandas as pd

from .signals import REGISTRY, Context, signal
from .store import moving_average


class Untranslatable(ValueError):
    """A snapshot the engine cannot run exactly. `rule` names the rule."""

    def __init__(self, rule: str, why: str):
        super().__init__(f"{rule}: {why}")
        self.rule = rule
        self.why = why


# The N100's dealing mechanics, as the engine check reproduced them.
N100_MECHANICS = {"price_basis": "raw", "require_traded_today": False, "max_orders": 50,
                  "drift_band": 0.02, "max_turnover": 0.5}

REBALANCE = {"monthly": "monthly", "weekly": "weekly", "quarterly": "quarterly"}
EXITS = {"below_ma200_2d": "below_ma200_2d"}
COMPARE = {">": op.gt, ">=": op.ge, "<": op.lt, "<=": op.le, "=": op.eq, "!=": op.ne}
MONTHS = {"1mnth": 1, "3mnth": 3, "6mnth": 6, "12mnth": 12}

# Screens that are an engine signal already, keyed by (criteria, rank_by, direction).
CANONICAL = {
    ((("close_price", ">", None, "ma200"),), "6mnth", "desc"): "trend_following",
}


# ---- indicators

def _ma50(ctx: Context) -> pd.DataFrame:
    if getattr(ctx, "_ma50", None) is None:
        ctx._ma50 = moving_average(ctx.m.adj, 50)
    return ctx._ma50


def indicator_value(ctx: Context, name: str, date) -> pd.Series:
    """The value of one sharemanager indicator for every share on the date."""
    if name == "close_price":
        return ctx.adj_at(date)
    if name == "ma200":
        return ctx.ma200.iloc[ctx.pos(date)]
    if name == "ma50":
        return _ma50(ctx).iloc[ctx.pos(date)]
    if name in MONTHS:
        return ctx.n100_return(date, MONTHS[name]) * 100.0
    if name.startswith("research:"):
        return REGISTRY[name.split(":", 1)[1]](ctx, date)
    raise KeyError(name)


def _known(name: str, rule: str, ranking: bool) -> str:
    name = (name or "").strip()
    if name in ("close_price", "ma50", "ma200") or name in MONTHS:
        return name
    if name.startswith("research:"):
        sig = name.split(":", 1)[1]
        if sig not in REGISTRY:
            raise Untranslatable(rule, f"research signal {sig!r} is not one the engine has")
        if not ranking:
            raise Untranslatable(rule, f"a research signal ({sig}) can rank, not be compared in a screen")
        return name
    raise Untranslatable(rule, f"indicator {name!r} is not one the engine computes")


def _direction(rank_order) -> str:
    text = str(rank_order or "").strip().lower()
    return "asc" if text == "value asc" else "desc"


# ---- the translation

def _criterion(c, indicators: dict, k: int) -> tuple:
    rule = f"criteria[{k}]"
    if not isinstance(c, (list, tuple)) or len(c) != 4:
        raise Untranslatable(rule, "a criterion is [indicator_id, operator, first_operand, second_operand]")
    ind_id, oper, first, second = c
    info = indicators.get(str(ind_id))
    if not info:
        raise Untranslatable(rule, f"indicator id {ind_id} has no name in the claim")
    name = _known(info.get("name"), f"{rule} ({info.get('name')})", ranking=False)
    oper = str(oper or "").strip()
    first = None if first is None or str(first).strip() == "" else str(first).strip()
    second = None if second is None or str(second).strip() == "" else str(second).strip()
    if oper == "top_n":
        if first is None or not first.isdigit() or int(first) < 1:
            raise Untranslatable(rule, f"top_n needs a whole number, not {first!r}")
        return (name, "top_n", int(first), _direction(info.get("rank_order")))
    if oper not in COMPARE:
        raise Untranslatable(rule, f"operator {oper!r} is not one the engine translates")
    if first is not None:
        try:
            value = float(first)
        except ValueError:
            raise Untranslatable(rule, f"the constant {first!r} is not a number") from None
        if not math.isfinite(value):
            raise Untranslatable(rule, f"the constant {first!r} is not a number")
        return (name, oper, value, None)
    if second is None:
        raise Untranslatable(rule, "it compares with nothing (no constant and no indicator)")
    return (name, oper, None, _known(second, f"{rule} ({second})", ranking=False))


def _screen_signal(criteria: tuple, rank: str, direction: str, sig_name: str):
    """A signal for a screen: filters intersected, top_n in order, scored by the rank indicator."""
    filters = [c for c in criteria if c[1] != "top_n"]
    tops = [c for c in criteria if c[1] == "top_n"]

    def fn(ctx: Context, date) -> pd.Series:
        keep = None
        for name, oper, const, other in filters:
            left = indicator_value(ctx, name, date)
            right = const if other is None else indicator_value(ctx, other, date).reindex(left.index)
            ok = COMPARE[oper](left, right) & left.notna()
            if other is not None:
                ok &= pd.Series(right).notna()
            ok = ok.fillna(False).astype(bool)
            keep = ok if keep is None else (keep.reindex(ok.index, fill_value=False) & ok)
        index = keep.index[keep.to_numpy()] if keep is not None else None
        if ctx.member is not None and pd.Timestamp(date) in ctx.member.index:
            row = ctx.member.loc[pd.Timestamp(date)]
            members = row.index[row.to_numpy()]
            index = members if index is None else index.intersection(members)
        for name, _, n, d in tops:
            v = indicator_value(ctx, name, date).reindex(index).dropna()
            v = v.sort_index().sort_values(ascending=(d == "asc"), kind="mergesort")
            index = v.index[:n]
        score = indicator_value(ctx, rank, date)
        score = score if direction == "desc" else -score
        cols = ctx.m.adj.columns
        out = pd.Series(np.nan, index=cols)
        if index is not None and len(index):
            out.loc[index] = score.reindex(index).to_numpy()
        return out

    fn.signal_name = sig_name
    return fn


def _costs(model: str, settings) -> dict:
    if not isinstance(settings, dict):
        raise Untranslatable("cost_model", f"{model!r} came with no cost settings")
    need = ("commission", "stamp_duty", "spread_bp", "minimum_order", "whole_shares")
    missing = [k for k in need if k not in settings]
    if missing:
        raise Untranslatable("cost_model", f"{model!r} has no {', '.join(missing)}")
    return {"commission": float(settings["commission"]), "stamp_duty": float(settings["stamp_duty"]),
            "spread_large_bp": float(settings["spread_bp"]), "spread_mid_bp": float(settings["spread_bp"]),
            "minimum_order": float(settings["minimum_order"]),
            "decimals": 0 if settings["whole_shares"] else 2, "ptm_levy": 0.0}


def translate(claim: dict) -> dict:
    """A claim from backtest_api -> the engine's strategy (a dict for strategy.spec_from_dict).

    Raises Untranslatable naming the rule it cannot translate.
    """
    snap = claim.get("rules_snapshot")
    if not isinstance(snap, dict) or not isinstance(snap.get("rules"), dict):
        raise Untranslatable("rules_snapshot", "there is no rules snapshot")
    r = snap["rules"]
    known = {"selection", "screen_id", "score_indicator", "rank_by", "holdings", "hold_rank", "rebalance",
             "exit_rules", "exclude_funds"}
    extra = sorted(set(r) - known)
    if extra:
        raise Untranslatable(extra[0], "this rule is not one the translator knows")
    extra = sorted(set(snap) - {"rules", "criteria"})
    if extra:
        raise Untranslatable(extra[0], "this part of the snapshot is not one the translator knows")

    indicators = claim.get("indicators") or {}
    rank_orders = claim.get("rank_orders") or {}
    selection = str(r.get("selection") or "")

    # holdings, hold_rank
    try:
        holdings = int(r.get("holdings"))
    except (TypeError, ValueError):
        raise Untranslatable("holdings", f"{r.get('holdings')!r} is not a number of holdings") from None
    if holdings < 1:
        raise Untranslatable("holdings", "there has to be at least one holding")
    hold_rank = r.get("hold_rank")
    if hold_rank is not None:
        try:
            hold_rank = int(hold_rank)
        except (TypeError, ValueError):
            raise Untranslatable("hold_rank", f"{hold_rank!r} is not a rank") from None
        if hold_rank < holdings:
            raise Untranslatable("hold_rank", f"{hold_rank} is inside the {holdings} holdings")

    rebalance = REBALANCE.get(str(r.get("rebalance") or "").strip().lower())
    if rebalance is None:
        raise Untranslatable("rebalance", f"{r.get('rebalance')!r} is not monthly, weekly or quarterly")

    exits = [x.strip().lower() for x in str(r.get("exit_rules") or "").split(",") if x.strip()]
    if len(exits) > 1:
        raise Untranslatable("exit_rules", f"more than one exit ({', '.join(exits)}) is not one the engine runs")
    exit_rule = None
    if exits:
        exit_rule = EXITS.get(exits[0])
        if exit_rule is None:
            raise Untranslatable("exit_rules", f"exit {exits[0]!r} is not one the engine runs")

    if str(r.get("exclude_funds") or "").upper() != "Y":
        raise Untranslatable("exclude_funds", "the engine's universe holds no funds, so 'N' cannot be run as asked")

    # selection and ranking
    rank_raw = r.get("rank_by") if r.get("rank_by") not in (None, "") else r.get("score_indicator")
    if selection == "screen":
        if r.get("screen_id") is None:
            raise Untranslatable("screen_id", "selection is 'screen' but there is no screen")
        crits = snap.get("criteria")
        if not isinstance(crits, list) or not crits:
            raise Untranslatable("criteria", "the screen has no criteria")
        criteria = tuple(_criterion(c, indicators, k) for k, c in enumerate(crits))
        if rank_raw in (None, ""):
            raise Untranslatable("rank_by", "a screen with no rank_by has no order the engine can reproduce")
    elif selection == "score":
        score = r.get("score_indicator")
        if score in (None, ""):
            raise Untranslatable("score_indicator", "selection is 'score' but there is no score indicator")
        _known(str(score), "score_indicator", ranking=True)
        if snap.get("criteria"):
            raise Untranslatable("criteria", "a score selection carries screen criteria")
        criteria = ()
    else:
        raise Untranslatable("selection", f"{selection!r} is not screen or score")

    rank = _known(str(rank_raw), "rank_by" if r.get("rank_by") not in (None, "") else "score_indicator",
                  ranking=True)
    direction = "desc" if rank.startswith("research:") else _direction(rank_orders.get(rank))

    sig = CANONICAL.get((tuple(c for c in criteria), rank, direction))
    if sig is None:
        key = json.dumps([selection, r.get("score_indicator"), criteria, rank, direction], default=str)
        sig = "rules_" + hashlib.sha256(key.encode()).hexdigest()[:12]
        if sig not in REGISTRY:
            if selection == "score":
                score_name = _known(str(r.get("score_indicator")), "score_indicator", ranking=True)
                base = _screen_signal((), rank, direction, sig)

                def fn(ctx, date, _base=base, _score=score_name):
                    has = indicator_value(ctx, _score, date).notna()
                    return _base(ctx, date).where(has.reindex(ctx.m.adj.columns, fill_value=False))
                fn.signal_name = sig
                signal(sig)(fn)
            else:
                signal(sig)(_screen_signal(criteria, rank, direction, sig))

    # cash and costs
    try:
        cash = float(claim.get("starting_cash"))
    except (TypeError, ValueError):
        raise Untranslatable("starting_cash", "the starting cash is not a number") from None
    if not cash > 0:
        raise Untranslatable("starting_cash", "the starting cash has to be more than nothing")
    costs = _costs(str(claim.get("cost_model")), claim.get("cost_settings"))

    strategy = str(claim.get("strategy") or "")
    fingerprint = str(claim.get("fingerprint") or "")
    return {
        "name": f"sm_{strategy}_{fingerprint[:10]}",
        "family": f"sm_{strategy}",
        "signals": {sig: 1},
        "holdings": holdings,
        "rebalance": rebalance,
        "hold_rank": hold_rank,
        "exit": exit_rule,
        "universe": "ftse350",
        "start_cash": cash,
        "costs": costs,
        **N100_MECHANICS,
    }
