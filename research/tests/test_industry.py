"""Industry screens on the Mac mini (brief of 22 Nov 2026, industry screens: test 8).

The claim carries the FMP half of sharemanager's industry map; the translator applies it to each
universe share's FMP sector and industry (symbols.parquet) and keeps no map of its own.
"""
from __future__ import annotations

import inspect
import json

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import from_rules
from research import worker as worker_mod
from research.from_rules import Untranslatable, translate
from research.strategy import screen_scores, spec_from_dict
from research.universe import build_universe
from test_worker import STORE, TREND_CLAIM, FakeApi

INDUSTRIES = ["Technology", "Telecommunications", "Health Care", "Financials", "Real Estate", "Consumer Discretionary",
              "Consumer Staples", "Industrials", "Basic Materials", "Energy", "Utilities"]
# What backtest_api hands out (includes/industry_map.php industry_map_fmp(), sql/industry_map.sql).
FMP_MAP = {
    "version": "0a1b2c3d4e5f",
    "industries": INDUSTRIES,
    "fmp": {"sectors": {"Basic Materials": "Basic Materials", "Communication Services": "Consumer Discretionary",
                        "Consumer Cyclical": "Consumer Discretionary", "Consumer Defensive": "Consumer Staples",
                        "Energy": "Energy", "Financial Services": "Financials", "Healthcare": "Health Care",
                        "Industrials": "Industrials", "Real Estate": "Real Estate", "Technology": "Technology",
                        "Utilities": "Utilities"},
            "exceptions": [["Communication Services", "Telecommunications Services", "Telecommunications"],
                           ["Industrials", "Airlines, Airports & Air Services", "Consumer Discretionary"],
                           ["Technology", "Communication Equipment", "Telecommunications"]]},
}
# symbol -> (FMP sector, FMP industry), and the industry sharemanager's map gives each.
SHARES = {
    "BANK.L": ("Financial Services", "Banks - Diversified", "Financials"),
    "TRUST.L": ("Financial Services", "Asset Management", "Financials"),
    "HOUSE.L": ("Consumer Cyclical", "Residential Construction", "Consumer Discretionary"),
    "PHONE.L": ("Communication Services", "Telecommunications Services", "Telecommunications"),
    "ADS.L": ("Communication Services", "Advertising Agencies", "Consumer Discretionary"),
    "FLY.L": ("Industrials", "Airlines, Airports & Air Services", "Consumer Discretionary"),
    "PIPE.L": ("Industrials", "Industrial - Machinery", "Industrials"),
    "OIL.L": ("Energy", "Oil & Gas Integrated", "Energy"),
}


def master(shares=SHARES, **extra):
    rows = {**{s: v[:2] for s, v in shares.items()}, **extra}
    return pd.DataFrame({"sector": {s: v[0] for s, v in rows.items()}, "industry": {s: v[1] for s, v in rows.items()}})


def claim(criterion=("ind_not_in", "Financials"), version=FMP_MAP["version"], **changes):
    c = json.loads(json.dumps(TREND_CLAIM))
    c["rules_snapshot"]["criteria"] = [["212", ">", "0", ""], ["", criterion[0], criterion[1], ""]]
    c["rules_snapshot"]["industry_map"] = version
    c["industry_map"] = json.loads(json.dumps(FMP_MAP))
    c.update(changes)
    return c


def kept(spec, symbols=tuple(SHARES)):
    """What the engine's screen keeps of `symbols`, every one of them priced and ranked."""
    v = pd.Series(np.arange(1.0, len(symbols) + 1), index=list(symbols))
    return sorted(screen_scores(spec["screen"], {"close_price": v, "6mnth": v}, list(symbols)).index)


def test_8_a_claim_with_an_industry_criterion_keeps_the_industries_the_screen_would():
    s = translate(claim(("ind_not_in", "Financials")), STORE, master=master(), universe=list(SHARES))
    assert s["signals"] == {}                          # a screen, not the canonical trend signal
    assert s["screen"]["criteria"][1] == ["industry", "ind_not_in", ["Financials"], None]
    assert s["screen"]["industries"] == {sym: v[2] for sym, v in SHARES.items()}       # as sharemanager's map has them
    assert s["screen"]["industry_map_version"] == FMP_MAP["version"]
    assert kept(s) == sorted(set(SHARES) - {"BANK.L", "TRUST.L"})                      # the bank and the trust go
    spec_from_dict(s)                                  # and it is a strategy the engine takes

    s = translate(claim(("ind_in", "Financials")), STORE, master=master(), universe=list(SHARES))
    assert kept(s) == ["BANK.L", "TRUST.L"]
    # an exception by FMP industry decides before the sector does
    s = translate(claim(("ind_in", "Telecommunications;Energy")), STORE, master=master(), universe=list(SHARES))
    assert kept(s) == ["OIL.L", "PHONE.L"]
    s = translate(claim(("ind_not_in", "Consumer Discretionary;Industrials")), STORE, master=master(), universe=list(SHARES))
    assert kept(s) == ["BANK.L", "OIL.L", "PHONE.L", "TRUST.L"]                        # the airline went with the retailers


def test_8_the_industry_criterion_comes_before_a_top_n_as_the_screen_builder_applies_it():
    c = claim(("ind_not_in", "Financials"))
    c["rules_snapshot"]["criteria"].append(["212", "top_n", "2", ""])
    s = translate(c, STORE, master=master(), universe=list(SHARES))
    # ranked highest first by close_price: OIL (8) and PIPE (7), not the two the bank and trust would have displaced
    assert kept(s) == ["OIL.L", "PIPE.L"]
    order = list(SHARES)[::-1]                          # BANK and TRUST would be the best two
    v = pd.Series(np.arange(1.0, 9.0), index=order)
    got = screen_scores(s["screen"], {"close_price": v, "6mnth": v}, order)
    assert sorted(got.index) == ["HOUSE.L", "PHONE.L"]


def test_8_a_universe_share_the_map_does_not_cover_refuses_the_run_and_names_it():
    m = master(**{"ODD.L": ("Conglomerates", "Conglomerates"), "BARE.L": (None, None), "NEW.L": ("Financial Services", float("nan"))})
    with pytest.raises(Untranslatable) as e:
        translate(claim(), STORE, master=m, universe=list(SHARES) + ["ODD.L", "BARE.L", "GONE.L", "NEW.L"])
    text = str(e.value)
    assert e.value.rule == "industry_map"
    assert "ODD.L" in text and "Conglomerates" in text and "BARE.L" in text and "GONE.L" in text
    assert "3 universe shares" in text and FMP_MAP["version"] in text
    assert "NEW.L" not in text and "BANK.L" not in text          # covered by its sector: no industry needed
    # a share outside the universe is nobody's business
    translate(claim(), STORE, master=m, universe=list(SHARES))


def test_8_the_map_is_the_claims_and_nothing_else():
    # no map, another version, an industry the map does not know, no master: each refused by name
    c = claim()
    del c["industry_map"]
    with pytest.raises(Untranslatable, match="carries no industry map"):
        translate(c, STORE, master=master(), universe=list(SHARES))
    with pytest.raises(Untranslatable, match="fingerprinted with"):
        translate(claim(version="ffffffffffff"), STORE, master=master(), universe=list(SHARES))
    with pytest.raises(Untranslatable, match="Banks is not an industry the map knows"):
        translate(claim(("ind_in", "Financials;Banks")), STORE, master=master(), universe=list(SHARES))
    with pytest.raises(Untranslatable, match="no industry"):
        translate(claim(("ind_in", "")), STORE, master=master(), universe=list(SHARES))
    with pytest.raises(Untranslatable, match="symbol master"):
        translate(claim(), STORE)
    # a changed map changes the answer: the translator holds no copy of its own
    c = claim(("ind_in", "Financials"))
    c["industry_map"]["fmp"]["sectors"]["Energy"] = "Financials"
    assert kept(translate(c, STORE, master=master(), universe=list(SHARES))) == ["BANK.L", "OIL.L", "TRUST.L"]
    src = inspect.getsource(from_rules)
    for name in ("Financial Services", "Consumer Cyclical", "Financials", "Telecommunications"):
        assert name not in src.split('"""', 2)[2], name            # no sector or industry name in the code
    # a screen with no industry criterion is translated exactly as before, map or no map in the claim
    plain = json.loads(json.dumps(TREND_CLAIM))
    before = translate(plain, STORE)
    plain["industry_map"] = FMP_MAP
    assert translate(plain, STORE) == before and before["signals"] == {"trend_following": 1}


def test_8_the_worker_runs_it_and_the_report_says_industries_are_todays(cfg):
    rng = np.random.default_rng(1)
    syms = list(SHARES) + [f"S{i}.L" for i in range(12)]
    prices = {s: list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, 560))) for s in syms}
    fields = {s: {"sector": v[0], "industry": v[1]} for s, v in SHARES.items()}
    fields.update({f"S{i}.L": {"sector": "Technology", "industry": "Software - Application"} for i in range(12)})
    m = make_market(prices, start="2024-01-01", master=fields)
    cfg.raw["universe"]["start"] = "2024-01-01"
    ws = built_workspace(cfg, m, build_universe(m, size=20, large=5, start="2024-01-01"),
                         names=["trend_following", "close_price", "ma200", "6mnth"])
    api = FakeApi([claim(("ind_not_in", "Financials"))])
    w = worker_mod.Worker(cfg, api, workspace_factory=lambda cfg: ws, gate=lambda c: None)
    assert w.run_once() is True
    kind, run_id, fp, result, report = api.calls[-1]
    assert kind == "result", api.calls[-1]
    html = report.decode()
    assert "Industries are today" in html and "applied to every date" in html and FMP_MAP["version"] in html
    rec = ws.log.read().iloc[-1]
    assert rec["industry_map_version"] == FMP_MAP["version"]
    # nothing held was ever a bank or a trust
    out = ws.run(spec_from_dict(translate(claim(("ind_not_in", "Financials")), ws.store, master=m.master,
                                          universe=syms)), start="2025-02-03", end="2025-05-16", kind="engine_check",
                 write_report=False)
    held = set(out["result"].fills["symbol"])
    assert held and not held & {"BANK.L", "TRUST.L"}

    # the same run with a universe share the map does not cover is posted as a failure that names it
    m.master.loc["S3.L", "sector"] = "Conglomerates"
    api = FakeApi([claim(("ind_not_in", "Financials"))])
    w = worker_mod.Worker(cfg, api, workspace_factory=lambda cfg: ws, gate=lambda c: None)
    w.run_once()
    kind, run_id, fp, why = api.calls[-1]
    assert kind == "failure" and "S3.L" in why and "Conglomerates" in why
