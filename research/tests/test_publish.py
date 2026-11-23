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
ACCOUNTS = pub.ACCOUNTS


def _accounts(symbols):
    """Annual accounts for 2020-2023, so book_to_market and the F-score have values from 2024."""
    rows = []
    for i, s in enumerate(symbols):
        for k, y in enumerate((2020, 2021, 2022, 2023)):
            rows.append({"symbol": s, "period_end": f"{y}-12-31", "freq": "annual", "reportedCurrency": "GBP",
                         "totalAssets": 3e8 + 1e7 * k, "totalStockholdersEquity": 1e8 * (i + 1),
                         "netIncome": 1e7 * (k + 1), "operatingCashFlow": 1.5e7 * (k + 1), "longTermDebt": 5e7,
                         "totalCurrentAssets": 1e8 + 1e6 * k, "totalCurrentLiabilities": 8e7,
                         "weightedAverageShsOut": 1e6, "revenue": 2e8 + 1e7 * k, "grossProfit": 6e7 + 4e6 * k,
                         "commonDividendsPaid": -4e6, "commonStockRepurchased": -1e6 * k,
                         "commonStockIssuance": 5e5})
    return pd.DataFrame(rows)


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
    m = make_market(prices, start="2024-01-01", statements=_accounts(syms))
    u = build_universe(m, size=6, large=3, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    built_workspace(cfg, m, u, names=NAMES + ACCOUNTS)
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
    assert {r[2] for r in stats} == set(NAMES + ACCOUNTS)
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


def _sent_accounts_before(cfg):
    """A successful publish in the log that sent the accounts indicators at their stored versions."""
    from research.indicator_store import IndicatorStore
    store = IndicatorStore.for_config(cfg)
    p = cfg.p("logs") / "publish.jsonl"
    p.parent.mkdir(parents=True, exist_ok=True)
    with p.open("a") as fh:
        fh.write(json.dumps({"ok": True, "accounts_versions": {n: store.meta(n)["version"] for n in ACCOUNTS}}) + "\n")


def test_incremental_publish_sends_only_what_is_new(built):
    cfg, m = built
    from research import data_version as dv
    latest = m.dates[-4]
    _sent_accounts_before(cfg)
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


# ---- the close whose answer is lost (brief of 22 Nov 2026, test 4)

class ClosesButNeverAnswers(FakeImport):
    """The N100 on 21 Nov 2026: the close lands, its answer never comes, and for the first `busy`
    times it is asked the N100 is still inside the closing transaction and holds the old version."""

    def __init__(self, *a, busy=3, lands=True, **k):
        super().__init__(*a, **k)
        self.busy, self.lands, self.closing = busy, lands, None

    def call(self, payload):
        a = payload["action"]
        if a == "close":
            self.calls.append(payload)
            self.closing = payload
            raise TimeoutError("The read operation timed out")
        if a == "symbols" and self.closing is not None:
            self.calls.append(payload)
            self.busy -= 1
            if self.busy == 1:
                raise TimeoutError("busy")              # a question lost too: asked again
            if self.busy < 0 and self.lands:
                self.version = self.closing["data_version"]
                self.latest = max(r[1] for c in self.batches() for r in c["prices"])
                return {"ok": True, "symbols": self.symbols, "data_version": self.version,
                        "latest_date": self.latest, "publish_id": self.closing["publish_id"]}
            return {"ok": True, "symbols": self.symbols, "data_version": self.version, "latest_date": self.latest,
                    "publish_id": "an-older-publish"}
        return super().call(payload)


def test_4_a_close_that_lands_with_no_answer_is_a_success_with_its_report(built):
    cfg, m = built
    api = ClosesButNeverAnswers(N100)
    s = pub.publish(cfg, full=True, api=api, market=m, close_poll_seconds=0)
    actions = [c["action"] for c in api.calls]
    assert s["ok"] and "abort" not in actions
    assert actions.count("symbols") >= 5                 # asked until the N100 held it, not once
    assert s["latest_date"] == str(m.dates[-1].date())
    last = json.loads((cfg.p("logs") / "publish.jsonl").read_text().splitlines()[-1])
    assert last["ok"] is True and last["publish_id"] == s["publish_id"] and "answer was lost" in last["note"]
    report = (cfg.p("reports") / f"publish-{s['publish_id']}.md").read_text()
    assert str(s["report"]).endswith(f"publish-{s['publish_id']}.md")
    assert "answer was lost" in report and f"Prices sent: {s['prices']:,}" in report
    assert f"publish_id='{s['publish_id']}'" in report   # where the N100 keeps the comparison
    assert not (cfg.p("logs") / "publish_pending.json").exists()


def test_4_a_data_import_that_does_not_name_the_publish_is_settled_by_the_data_version_it_holds(built):
    cfg, m = built

    class NoPublishId(ClosesButNeverAnswers):
        def call(self, payload):
            out = super().call(payload)
            out.pop("publish_id", None)
            return out

    api = NoPublishId(N100, version=0, latest="2024-06-03")
    s = pub.publish(cfg, full=True, api=api, market=m, close_poll_seconds=0)
    assert s["ok"] and "abort" not in [c["action"] for c in api.calls]


def test_4_a_close_not_seen_to_land_is_never_aborted_and_the_next_publish_records_it(built):
    cfg, m = built
    api = ClosesButNeverAnswers(N100, lands=False)
    with pytest.raises(pub.PublishRefused, match="may still land"):
        pub.publish(cfg, full=True, api=api, market=m, close_poll_seconds=0, close_wait_minutes=0)
    assert "abort" not in [c["action"] for c in api.calls]
    lines = (cfg.p("logs") / "publish.jsonl").read_text().splitlines()
    first = json.loads(lines[-1])
    assert first["ok"] is False
    # it landed after all: the next night's publish finds the N100 holding it
    from research import data_version as dv
    nxt = FakeImport(N100, version=dv.current(cfg.data_dir), latest=str(m.dates[-1].date()))
    held = nxt.call
    nxt.call = lambda p: {**held(p), "publish_id": first["publish_id"]} if p["action"] == "symbols" else held(p)
    pub.publish(cfg, api=nxt, market=m)
    logged = [json.loads(x) for x in (cfg.p("logs") / "publish.jsonl").read_text().splitlines()[len(lines):]]
    settled = [x for x in logged if x["publish_id"] == first["publish_id"]]
    assert len(settled) == 1 and settled[0]["ok"] is True and "answer was lost" in settled[0]["note"]
    assert (cfg.p("reports") / f"publish-{first['publish_id']}.md").exists()
    assert not (cfg.p("logs") / "publish_pending.json").exists()


def test_4_a_refused_batch_closes_nothing_and_a_refused_close_is_a_failure(built):
    cfg, m = built
    api = FakeImport(N100, fail_on="batch")
    with pytest.raises(pub.PublishRefused, match="refused batch"):
        pub.publish(cfg, full=True, api=api, market=m)
    actions = [c["action"] for c in api.calls]
    assert "close" not in actions and actions[-1] == "abort"
    assert json.loads((cfg.p("logs") / "publish.jsonl").read_text().splitlines()[-1])["ok"] is False
    api = FakeImport(N100, fail_on="close")              # the N100 answered, and said no
    with pytest.raises(pub.PublishRefused, match="refused close"):
        pub.publish(cfg, full=True, api=api, market=m)
    assert [c["action"] for c in api.calls][-1] == "abort"
    assert not list(cfg.p("reports").glob("publish-*.md"))


# ---------------------------------------------------------------- brief of 22 Nov 2026, test 6
def test_6_the_publish_carries_piotroski_score_and_book_to_market_with_their_whole_history(built):
    cfg, m = built
    from research import data_version as dv
    from research.indicator_store import IndicatorStore
    store = IndicatorStore.for_config(cfg)
    latest = m.dates[-4]
    # the N100 already holds a publish, but these two have never been sent: their whole history goes
    api = FakeImport(N100, version=dv.current(cfg.data_dir), latest=str(latest.date()))
    s = pub.publish(cfg, api=api, market=m)
    _, stats = api.sent()
    close = api.calls[-1]
    for name in ACCOUNTS:
        mine = [r for r in stats if r[2] == name]
        frame = store.frame(name)
        frame = frame[frame["date"].dt.day_of_week < 5]
        want = {(r.symbol.removesuffix(".L"), str(r.date.date())) for r in frame.itertuples() if r.symbol != "BT-A.L"}
        assert len(want) > 100
        assert {(r[0], r[1]) for r in mine if r[0] != "BT.A"} == want      # every stored weekday row
        first = str(store.meta(name)["dates"][0].date())
        assert set(close["statistic_from"][name].values()) == {first}
        assert first < str(latest.date())
    assert sorted(s["accounts_whole"]) == sorted(ACCOUNTS)
    assert set(r[2] for r in stats) >= set(ACCOUNTS)
    # the scores are scores
    assert {r[3] for r in stats if r[2] == "piotroski_score"} <= set(map(float, range(10)))

    # the next night: only what is new, as for the prices
    api2 = FakeImport(N100, version=dv.current(cfg.data_dir), latest=str(m.dates[-2].date()))
    pub.publish(cfg, api=api2, market=m)
    _, stats2 = api2.sent()
    assert all(pd.Timestamp(r[1]) == m.dates[-1] for r in stats2)
    assert api2.calls[-1]["statistic_from"] == {n: {} for n in ACCOUNTS}


def test_6_a_restated_account_resends_that_company_from_the_change(built):
    cfg, m = built
    from research import data_version as dv
    _sent_accounts_before(cfg)
    held = dv.current(cfg.data_dir)
    ch = pd.DataFrame([{"version": held + 1, "time": "t", "reason": "test", "panel": "statements", "symbol": "S2.L",
                        "from": pd.Timestamp("2024-03-01"), "to": None}])
    import research.data_version as dvm
    orig = dvm.changes_since
    try:
        dvm.changes_since = lambda d, v: ch
        from research.indicator_store import IndicatorStore
        accounts, whole = pub.accounts_from(cfg, IndicatorStore.for_config(cfg), {"S2": "S2.L", "S3": "S3.L"},
                                            {"S2": m.dates[-1], "S3": m.dates[-1]}, held, str(m.dates[-2].date()), False)
    finally:
        dvm.changes_since = orig
    assert whole == []
    for name in ACCOUNTS:
        assert accounts[name]["S2"] == pd.Timestamp("2024-03-01")
        assert accounts[name]["S3"] == m.dates[-1]


@pytest.mark.parametrize("name", ACCOUNTS)
def test_6_a_failed_accounts_indicator_stops_the_publish(built, name):
    cfg, m = built
    jl = cfg.p("logs") / "indicator_job.jsonl"
    with jl.open("a") as fh:
        fh.write(json.dumps({"indicator": name, "ok": False, "reason": "ValueError: boom"}) + "\n")
    api = FakeImport(N100)
    with pytest.raises(pub.PublishRefused, match=f"{name}: the last indicator job failed"):
        pub.publish(cfg, api=api, market=m)
    assert api.calls == []
