"""rules_translator and backtest_worker (brief of 20 Nov 2026: tests 6 and 7, and the worker's contract)."""
from __future__ import annotations

import json
import logging
import os

import numpy as np
import pandas as pd
import pytest

from conftest import make_market
from research import config as config_mod
from research import worker as worker_mod
from research.from_rules import Untranslatable, translate
from research.signals import REGISTRY, Context
from research.strategy import Workspace, spec_from_dict
from research.universe import build_universe, membership
from research.validate import RUN9

# The trend following rules as sharemanager hands them out (sql/strategy_rules.sql,
# sql/trend_screens.sql, sql/strategy_hold_rank.sql): screen 'Trend following (200-day)'.
TREND_CLAIM = {
    "run_id": 41, "strategy": "trend_following", "from": "2025-02-03", "to": "2025-05-16",
    "starting_cash": 100000.0, "cost_model": "t212",
    "cost_settings": {"commission": 0.0, "stamp_duty": 0.005, "spread_bp": 10.0, "minimum_order": 250.0,
                      "whole_shares": True},
    "fingerprint": "0123456789abcdef0123456789abcdef01234567",
    "rules_snapshot": {
        "rules": {"selection": "screen", "screen_id": 7, "score_indicator": None, "rank_by": "6mnth",
                  "holdings": 15, "hold_rank": 30, "rebalance": "monthly", "exit_rules": "below_ma200_2d",
                  "exclude_funds": "Y"},
        "criteria": [["212", ">", "", "ma200"]],
    },
    "indicators": {"212": {"name": "close_price", "rank_order": "value DESC"}},
    "rank_orders": {"6mnth": "value DESC"},
    "holdout_start": "2025-05-18",
}


def claim(**changes):
    c = json.loads(json.dumps(TREND_CLAIM))
    for k, v in changes.items():
        if k in c["rules_snapshot"]["rules"]:
            c["rules_snapshot"]["rules"][k] = v
        else:
            c[k] = v
    return c


# ---------------------------------------------------------------- test 6: the translator
def test_trend_following_snapshot_becomes_the_run9_strategy():
    s = translate(claim())
    from research.costs import from_dict
    # The engine's rules and costs are the ones run 9 passed with: same signal, holdings, rebalance,
    # hold rank, exit, drift band, turnover cap, N100 mechanics and Trading 212 costs.
    assert s["signals"] == RUN9["signals"]
    assert spec_from_dict(s).rules() == spec_from_dict(RUN9).rules()
    assert from_dict(s["costs"]) == from_dict(RUN9["costs"])
    assert s["price_basis"] == RUN9["price_basis"]
    assert s["universe"] == "ftse350"


def test_unknown_criterion_is_refused_and_named():
    c = claim(indicators={"212": {"name": "close_price"}, "99": {"name": "pe_ratio", "rank_order": "value ASC"}})
    c["rules_snapshot"]["criteria"].append(["99", "<", "15", None])
    with pytest.raises(Untranslatable) as e:
        translate(c)
    assert "pe_ratio" in str(e.value) and e.value.rule.startswith("criteria[1]")


@pytest.mark.parametrize("rule,value,named", [
    ("rebalance", "fortnightly", "rebalance"),
    ("exit_rules", "stop_loss_10", "exit_rules"),
    ("exclude_funds", "N", "exclude_funds"),
    ("rank_by", "value_score", "rank_by"),
    ("selection", "magic", "selection"),
])
def test_every_rule_it_cannot_run_is_refused_by_name(rule, value, named):
    with pytest.raises(Untranslatable) as e:
        translate(claim(**{rule: value}))
    assert e.value.rule == named


def test_an_operator_it_does_not_know_and_an_unknown_rule_field_are_refused():
    c = claim()
    c["rules_snapshot"]["criteria"][0][1] = "top_percent"
    with pytest.raises(Untranslatable, match="top_percent"):
        translate(c)
    c = claim()
    c["rules_snapshot"]["rules"]["trailing_stop"] = 0.1
    with pytest.raises(Untranslatable) as e:
        translate(c)
    assert e.value.rule == "trailing_stop"


def test_research_signal_can_rank_and_paper_costs_are_carried():
    c = claim(rank_by="research:momentum_12_1", cost_model="paper",
              cost_settings={"commission": 5.95, "stamp_duty": 0.005, "spread_bp": 25.0, "minimum_order": 250.0,
                             "whole_shares": True})
    s = translate(c)
    assert s["signals"] != {"trend_following": 1} and list(s["signals"])[0] in REGISTRY
    assert s["costs"]["commission"] == 5.95 and s["costs"]["spread_mid_bp"] == 25.0


def _trend_market():
    rng = np.random.default_rng(3)
    n = 330
    prices = {f"S{i}.L": list(100 * np.cumprod(1 + rng.normal(0.0005 * (i - 4), 0.012, n))) for i in range(10)}
    return make_market(prices, start="2024-01-01")


def test_a_generic_screen_scores_as_the_engine_signal_it_matches():
    """The generic screen (close above ma200, rank 6mnth) gives the trend following signal's scores."""
    m = _trend_market()
    u = build_universe(m, size=10, large=5, start="2024-01-01")
    member, _ = membership(u, m.dates)
    ctx = Context(m, None, member.reindex(columns=m.close.columns, fill_value=False))
    from research.from_rules import _screen_signal
    generic = _screen_signal((("close_price", ">", None, "ma200"),), "6mnth", "desc", "t")
    d = m.dates[-1]
    want = REGISTRY["trend_following"](ctx, d) * 100.0
    got = generic(ctx, d)
    pd.testing.assert_series_equal(got.dropna().sort_index(), want.dropna().sort_index(), check_names=False)


def test_value_figures_ranked_through_a_research_signal_use_the_publication_lag():
    import inspect
    from research.signals import osap
    src = inspect.getsource(osap)
    assert "ctx.f." in src     # accounts reach a signal only through pit.Fundamentals


# ---------------------------------------------------------------- the worker
class FakeApi:
    def __init__(self, claims):
        self.claims = list(claims)
        self.calls = []

    def claim(self):
        self.calls.append(("claim",))
        return self.claims.pop(0) if self.claims else None

    def result(self, run_id, fp, result, report):
        self.calls.append(("result", run_id, fp, result, report))
        return {"ok": True}

    def failure(self, run_id, fp, why):
        self.calls.append(("failure", run_id, fp, why))
        return {"ok": True}


def _ws_factory():
    def make(cfg):
        rng = np.random.default_rng(1)
        n = 560
        prices = {f"S{i}.L": list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, n))) for i in range(20)}
        m = make_market(prices, start="2024-01-01")
        cfg.raw["universe"]["start"] = "2024-01-01"
        return Workspace(cfg, market=m, universe=build_universe(m, size=20, large=5, start="2024-01-01"))
    return make


def test_worker_runs_a_claim_logs_it_and_posts_the_result(cfg):
    api = FakeApi([claim(holdings=5, hold_rank=8)])
    w = worker_mod.Worker(cfg, api, workspace_factory=_ws_factory(), gate=lambda c: None)
    assert w.run_once() is True
    kind, run_id, fp, result, report = api.calls[-1]
    assert kind == "result" and run_id == 41 and fp == TREND_CLAIM["fingerprint"]
    assert isinstance(result["rebalances"], int) and result["rebalances"] >= 3     # Feb to May, monthly
    assert result["trading_days"] and result["fills"] is not None
    for k in ("total_return", "bench_equal_weight_return", "bench_cap_weight_return", "fills", "costs_paid",
              "trials", "deflated_sharpe", "research_run_id", "engine_commit"):
        assert k in result
    assert report.startswith(b"<") and json.dumps(result, allow_nan=False)
    log = w.workspace().log.read()
    assert (log["run_id"] == result["research_run_id"]).any()
    assert log.iloc[-1]["kind"] == "research" and log.iloc[-1]["family"] == "sm_trend_following"
    assert not w.state_path.exists()


def test_a_failure_is_posted_and_does_not_stop_the_next(cfg):
    bad = claim(run_id=1, rebalance="fortnightly")
    good = claim(run_id=2, holdings=5, hold_rank=8)
    api = FakeApi([bad, good])
    w = worker_mod.Worker(cfg, api, workspace_factory=_ws_factory(), gate=lambda c: None)
    w.run_once()
    w.run_once()
    kinds = [(c[0], c[1]) for c in api.calls if c[0] != "claim"]
    assert kinds == [("failure", 1), ("result", 2)]
    assert "rebalance" in [c for c in api.calls if c[0] == "failure"][0][3]


def test_the_worker_never_runs_over_the_holdout(cfg):
    api = FakeApi([claim(to="2025-06-01")])
    w = worker_mod.Worker(cfg, api, workspace_factory=_ws_factory(), gate=lambda c: None)
    w.run_once()
    f = [c for c in api.calls if c[0] == "failure"]
    assert f and "holdout" in f[0][3]
    assert w._ws is None or w._ws.log.read().empty


# ---------------------------------------------------------------- test 7: a restart during a run
def test_a_restart_during_a_run_posts_it_as_failed_and_does_not_run_it_again(cfg):
    api = FakeApi([claim()])

    class Crash(Exception):
        pass

    def boom(cfg):
        raise SystemExit("the machine restarted")      # not an Exception: the process dies mid-run

    w = worker_mod.Worker(cfg, api, workspace_factory=boom, gate=lambda c: None)
    with pytest.raises(SystemExit):
        w.run_once()
    assert w.state_path.exists() and not [c for c in api.calls if c[0] in ("result", "failure")]

    api2 = FakeApi([])
    w2 = worker_mod.Worker(cfg, api2, workspace_factory=_ws_factory(), gate=lambda c: None)
    w2.loop(once=True)
    f = [c for c in api2.calls if c[0] == "failure"]
    assert f and f[0][1] == 41 and "interrupted" in f[0][3]
    assert not w2.state_path.exists()
    assert [c for c in api2.calls if c[0] == "claim"]       # it carries on with the queue, not the old run


def test_a_stop_signal_mid_run_posts_the_run_as_failed(cfg):
    api = FakeApi([claim()])

    def stopped(cfg):
        raise worker_mod.Stopped()

    w = worker_mod.Worker(cfg, api, workspace_factory=stopped, gate=lambda c: None)
    with pytest.raises(worker_mod.Stopped):
        w.run_once()
    f = [c for c in api.calls if c[0] == "failure"]
    assert f and "stopped" in f[0][3] and not w.state_path.exists()


def test_refuses_to_start_unless_the_run9_replay_check_passed_at_this_commit(cfg, tmp_path):
    api = FakeApi([claim()])
    w = worker_mod.Worker(cfg, api, workspace_factory=_ws_factory())
    with pytest.raises(worker_mod.WorkerError, match="refusing to start"):
        w.loop(once=True)
    assert not [c for c in api.calls if c[0] == "claim"]
    v = cfg.data_dir / "validation"
    v.mkdir(parents=True)
    (v / "run9_replay_check.json").write_text(json.dumps(
        {"passed": True, "replay_targets": True, "git_commit": "abc1234", "run_id": "r9"}))
    assert "not in the test log" in worker_mod.replay_check(cfg, commit="abc1234")
    from research.runlog import RunLog
    RunLog(cfg.p("run_log")).append({"run_id": "r9", "kind": "engine_validation"})
    assert worker_mod.replay_check(cfg, commit="abc1234") is None
    assert "not at the current commit" in worker_mod.replay_check(cfg, commit="def5678")
    assert "clean commit" in worker_mod.replay_check(cfg, commit="abc1234+dirty")
    (v / "run9_replay_check.json").write_text(json.dumps(
        {"passed": False, "replay_targets": True, "git_commit": "abc1234", "run_id": "r9"}))
    assert "did not pass" in worker_mod.replay_check(cfg, commit="abc1234")


def test_settings_must_be_mode_600_and_the_token_is_never_logged(tmp_path, caplog):
    f = tmp_path / "worker.env"
    f.write_text("SM_BACKTEST_API_URL=http://n100/backtest_api.php\nSM_BACKTEST_API_TOKEN=s3cr3t-token-xyz\n")
    os.chmod(f, 0o644)
    with pytest.raises(worker_mod.WorkerError, match="600"):
        worker_mod.read_settings(f)
    os.chmod(f, 0o600)
    s = worker_mod.read_settings(f)
    assert s["SM_BACKTEST_API_TOKEN"] == "s3cr3t-token-xyz"
    assert "s3cr3t-token-xyz" not in config_mod.mask("calling with Bearer s3cr3t-token-xyz")


def test_the_worker_reaches_nothing_but_the_api():
    import inspect
    src = inspect.getsource(worker_mod)
    for word in ("pymysql", "mysql", "paramiko", "ssh ", "subprocess", "sqlite"):
        assert word not in src.lower().replace("no ssh", "")
