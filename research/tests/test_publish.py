"""data_publish (brief of 21 Nov 2026, test 6 and the Mac mini half of tests 1 and 3)."""
from __future__ import annotations

import json

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import publish as pub
from research.universe import build_universe

N = 330
NAMES = ["close_price", "ma50", "ma200", "1mnth", "3mnth", "6mnth", "12mnth"]


class FakeImport(pub.DataApi):
    """data_import as the N100 answers it, in memory."""

    def __init__(self, symbols, version=None, latest=None, fail_on=None):
        super().__init__("http://n100.test/data_api.php", "tok-secret-123")
        self.symbols, self.version, self.latest, self.fail_on = symbols, version, latest, fail_on
        self.calls = []

    def call(self, payload):
        self.calls.append(payload)
        a = payload["action"]
        if a == self.fail_on:
            return {"ok": False, "why": f"{a} went wrong"}
        if a == "symbols":
            return {"ok": True, "symbols": self.symbols, "data_version": self.version, "latest_date": self.latest}
        if a == "close":
            return {"ok": True, "latest_date": max((r[1] for c in self.batches() for r in c["prices"]), default=None),
                    "prices_replaced": 0, "statistics_replaced": 0,
                    "differences": {"price": {"compared": 0, "differing": 0, "largest": []}}}
        return {"ok": True}

    def batches(self):
        return [c for c in self.calls if c["action"] == "batch"]

    def sent(self):
        return ([r for c in self.batches() for r in c["prices"]], [r for c in self.batches() for r in c["statistics"]])


@pytest.fixture
def built(cfg):
    rng = np.random.default_rng(7)
    syms = [f"S{i}.L" for i in range(5)] + ["BT-A.L"]
    prices = {s: list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, N))) for s in syms}
    m = make_market(prices, start="2024-01-01")
    u = build_universe(m, size=6, large=3, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    built_workspace(cfg, m, u, names=NAMES)
    mk = pub.fetch_marker(cfg)
    mk.parent.mkdir(parents=True, exist_ok=True)
    mk.write_text(json.dumps({"ok": True}))
    return cfg, m


N100 = ["S0", "S1", "S2", "S3", "S4", "BT.A", "NOPE"]


def test_full_publish_sends_trading_days_only_and_names_the_missing(built):
    cfg, m = built
    api = FakeImport(N100)
    s = pub.publish(cfg, full=True, api=api, market=m, batch_rows=5000)
    prices, stats = api.sent()
    days = {pd.Timestamp(r[1]) for r in prices + stats}
    assert days and all(d.day_of_week < 5 for d in days)
    # the store keeps weekend rows for these, and none of them is sent
    from research.indicator_store import IndicatorStore
    built_days = IndicatorStore.for_config(cfg).meta("ma200@raw")["dates"]
    assert any(d.day_of_week >= 5 for d in built_days)
    assert s["missing"] == ["NOPE"]
    close = api.calls[-1]
    assert close["action"] == "close" and close["missing"] == ["NOPE"] and close["full"] is True
    assert set(close["replace_from"]) == set(N100) - {"NOPE"}
    assert close["batches"] == len(api.batches()) > 1
    assert close["price_rows"] == len(prices) and close["statistic_rows"] == len(stats)
    assert {r[2] for r in stats} == set(NAMES)
    assert "BT.A" in {r[0] for r in prices}          # FMP's BT-A.L is the N100's BT.A


def test_prices_and_averages_go_in_pence_returns_in_per_cent(built):
    cfg, m = built
    api = FakeImport(N100)
    pub.publish(cfg, full=True, api=api, market=m)
    prices, stats = api.sent()
    d = m.dates[-1]
    p = {(r[0], r[1]): r[2] for r in prices}
    assert p[("S0", str(d.date()))] == pytest.approx(m.close.loc[d, "S0.L"] * 100, abs=1e-4)
    st = {(r[0], r[1], r[2]): r[3] for r in stats}
    assert st[("S0", str(d.date()), "close_price")] == pytest.approx(m.close.loc[d, "S0.L"] * 100, abs=1e-4)
    ma = m.close["S0.L"].dropna().iloc[-200:].mean() * 100
    assert st[("S0", str(d.date()), "ma200")] == pytest.approx(ma, rel=1e-6)
    assert abs(st[("S0", str(d.date()), "1mnth")]) < 100        # per cent, not a fraction times 100 again


def test_incremental_publish_sends_only_what_is_new(built):
    cfg, m = built
    from research import data_version as dv
    latest = m.dates[-4]
    api = FakeImport(N100, version=dv.current(cfg.data_dir), latest=str(latest.date()))
    pub.publish(cfg, api=api, market=m)
    prices, stats = api.sent()
    assert {r[1] for r in prices} == {str(d.date()) for d in m.dates[-3:]}
    assert all(pd.Timestamp(r[1]) > latest for r in stats)
    assert set(api.calls[-1]["replace_from"].values()) == {str((latest + pd.Timedelta(days=1)).date())}


def test_a_failure_part_way_aborts_and_the_close_is_never_sent(built):
    cfg, m = built
    api = FakeImport(N100, fail_on="batch")
    with pytest.raises(pub.PublishRefused):
        pub.publish(cfg, full=True, api=api, market=m)
    actions = [c["action"] for c in api.calls]
    assert actions[-1] == "abort" and "close" not in actions


def test_nothing_is_sent_after_a_failed_fetch_or_indicator_job(built):
    cfg, m = built
    pub.fetch_marker(cfg).write_text(json.dumps({"ok": False, "started": "00:30"}))
    api = FakeImport(N100)
    with pytest.raises(pub.PublishRefused, match="fetch did not finish"):
        pub.publish(cfg, full=True, api=api, market=m)
    pub.fetch_marker(cfg).write_text(json.dumps({"ok": True}))
    with (cfg.p("logs") / "indicator_job.jsonl").open("a") as fh:
        fh.write(json.dumps({"indicator": "ma200@raw", "ok": False, "reason": "boom"}) + "\n")
    with pytest.raises(pub.PublishRefused, match="ma200@raw"):
        pub.publish(cfg, full=True, api=api, market=m)
    assert api.calls == []


def test_the_token_is_never_logged(built, tmp_path, monkeypatch):
    cfg, m = built
    f = tmp_path / "worker.env"
    f.write_text("SM_DATA_API_URL=http://n100.test/data_api.php\nSM_BACKTEST_API_TOKEN=very-secret-token\n")
    f.chmod(0o644)
    with pytest.raises(pub.PublishRefused, match="600"):
        pub.read_settings(f)
    f.chmod(0o600)
    assert pub.read_settings(f)["SM_DATA_API_URL"].endswith("data_api.php")
    api = FakeImport(N100, fail_on="close")
    api._token = "very-secret-token"
    with pytest.raises(pub.PublishRefused):
        pub.publish(cfg, full=True, api=api, market=m)
    assert "very-secret-token" not in (cfg.p("logs") / "publish.jsonl").read_text()


def test_publish_talks_only_to_data_import():
    import inspect
    src = inspect.getsource(pub)
    assert '"action": "claim"' not in src and '"action": "result"' not in src
    actions = {a for a in ("symbols", "batch", "close", "abort") if f'"action": "{a}"' in src}
    assert actions == {"symbols", "batch", "close", "abort"}


def test_a_close_whose_answer_is_lost_is_found_closed(built):
    cfg, m = built

    class Lost(FakeImport):
        def call(self, payload):
            if payload["action"] == "close":
                self.calls.append(payload)
                self.closed = payload["publish_id"]
                raise TimeoutError("timed out")
            out = super().call(payload)
            if payload["action"] == "symbols" and getattr(self, "closed", None):
                out["publish_id"] = self.closed
            return out

    api = Lost(N100)
    s = pub.publish(cfg, full=True, api=api, market=m)
    assert s["ok"] and "abort" not in [c["action"] for c in api.calls]
