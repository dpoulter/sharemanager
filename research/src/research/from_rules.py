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

Indicators: a ranking or screen indicator is translated only if the
indicator store holds it on the run's price basis (raw: `<name>@raw`, or
`<name>` for one that reads no prices). The N100-style indicators are stored
under their sharemanager names (close_price, ma50, ma200, 1mnth, 3mnth,
6mnth, 12mnth; indicators/live.py). A ranking indicator may also be any other
stored indicator, written `research:<name>` (indicators/); value and quality
indicators read accounts through the publication lag. Sharemanager's own
value and quality figures (pe, value_score and the rest) are refused, naming
them, unless the store holds an indicator of that name.

Screens: comparison criteria (> >= < <= = !=) against a constant or another
indicator, intersected, then top_n criteria in criteria order, each keeping the
best N of what is left, as build_screen() applies them. Selection 'score' is
every share with a value for the score indicator. rank_by (or the score
indicator) orders the result. The screen is handed to the engine as data
(strategy.screen_scores evaluates it over stored values); nothing is worked
out here.

A screen that is exactly an engine strategy already validated against the N100
(CANONICAL) is given that stored indicator by name when the store holds it, so
the trend following rules become the strategy the run 9 replay check passed
with.

The engine runs a sharemanager rule the N100's way: raw closes, a price on or
before the day is enough, and at most 50 orders a rebalance (validate.RUN9).
Unlike the N100 engine, cash dividends are credited (the strategy's default,
dividends: on), as a real account receives them.
"""
from __future__ import annotations

import math

BASIS = "raw"         # N100_MECHANICS


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
COMPARE = {">", ">=", "<", "<=", "=", "!="}
MONTHS = {"1mnth": 1, "3mnth": 3, "6mnth": 6, "12mnth": 12}

# Screens that are an engine signal already, keyed by (criteria, rank_by, direction).
CANONICAL = {
    ((("close_price", ">", None, "ma200"),), "6mnth", "desc"): "trend_following",
}


# ---- indicators
def _stored(store, name: str) -> bool:
    return store.has(f"{name}@{BASIS}") or store.has(name)


def _known(name: str, rule: str, ranking: bool, store) -> str:
    name = (name or "").strip()
    if name.startswith("research:"):
        sig = name.split(":", 1)[1]
        if not _stored(store, sig):
            raise Untranslatable(rule, f"research indicator {sig!r} is not in the indicator store")
        if not ranking:
            raise Untranslatable(rule, f"a research indicator ({sig}) can rank, not be compared in a screen")
        return sig
    if not name or not _stored(store, name):
        raise Untranslatable(rule, f"indicator {name!r} is not in the indicator store")
    return name


def _direction(rank_order) -> str:
    text = str(rank_order or "").strip().lower()
    return "asc" if text == "value asc" else "desc"


# ---- the translation

def _criterion(c, indicators: dict, k: int, store) -> tuple:
    rule = f"criteria[{k}]"
    if not isinstance(c, (list, tuple)) or len(c) != 4:
        raise Untranslatable(rule, "a criterion is [indicator_id, operator, first_operand, second_operand]")
    ind_id, oper, first, second = c
    info = indicators.get(str(ind_id))
    if not info:
        raise Untranslatable(rule, f"indicator id {ind_id} has no name in the claim")
    name = _known(info.get("name"), f"{rule} ({info.get('name')})", ranking=False, store=store)
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
    return (name, oper, None, _known(second, f"{rule} ({second})", ranking=False, store=store))


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


def translate(claim: dict, store) -> dict:
    """A claim from backtest_api -> the engine's strategy (a dict for strategy.spec_from_dict).

    `store` is the indicator store (indicator_store.IndicatorStore; only has() is used). Raises
    Untranslatable naming the rule it cannot translate.
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
        criteria = tuple(_criterion(c, indicators, k, store) for k, c in enumerate(crits))
        if rank_raw in (None, ""):
            raise Untranslatable("rank_by", "a screen with no rank_by has no order the engine can reproduce")
    elif selection == "score":
        score = r.get("score_indicator")
        if score in (None, ""):
            raise Untranslatable("score_indicator", "selection is 'score' but there is no score indicator")
        _known(str(score), "score_indicator", ranking=True, store=store)
        if snap.get("criteria"):
            raise Untranslatable("criteria", "a score selection carries screen criteria")
        criteria = ()
    else:
        raise Untranslatable("selection", f"{selection!r} is not screen or score")

    research = str(rank_raw).strip().startswith("research:")
    rank = _known(str(rank_raw), "rank_by" if r.get("rank_by") not in (None, "") else "score_indicator",
                  ranking=True, store=store)
    direction = "desc" if research else _direction(rank_orders.get(rank))

    sig = CANONICAL.get((tuple(c for c in criteria), rank, direction))
    require = None
    if selection == "score":
        require = _known(str(r.get("score_indicator")), "score_indicator", ranking=True, store=store)
    if sig is not None and _stored(store, sig):
        chosen = {"signals": {sig: 1}}
    else:
        chosen = {"signals": {}, "screen": {"criteria": [list(c) for c in criteria], "rank_by": rank,
                                            "direction": direction, "require": require}}

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
        **chosen,
        "holdings": holdings,
        "rebalance": rebalance,
        "hold_rank": hold_rank,
        "exit": exit_rule,
        "universe": "ftse350",
        "start_cash": cash,
        "costs": costs,
        **N100_MECHANICS,
    }
