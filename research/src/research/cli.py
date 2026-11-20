"""uv run research <command>

  fetch [--full]                  download from FMP (full first pull, else top-up), then the data report
  universe [--live CSV]           rebuild the monthly universe; compare with the N100's list
  run STRATEGY.yaml... [--period dev|val|FROM:TO] [--final]
  signals [--period dev|val]      each first signal alone, top quintile monthly; one comparison table
  compare FAMILY                  every logged run of a family, with the trial count
  report RUN_ID                   print a logged run (the HTML is written when the run is)
  validate --universe CSV [--orders CSV] [--values CSV]
                                  reproduce the N100's run 9; nothing else is trusted until it passes
  worker [--once]                 run sharemanager's queued backtests through backtest_api (worker.py)
"""
from __future__ import annotations

import argparse
import json
import logging
import sys
import time
from pathlib import Path

import pandas as pd

from .config import RESEARCH_DIR, load_config, mask

log = logging.getLogger("research")

FIRST_SIGNALS = ["momentum_12_1", "reversal_1m", "high_52w", "low_volatility", "gross_profitability",
                 "asset_growth", "accruals", "net_share_issuance", "book_to_market", "earnings_yield"]


def _setup_logging(cfg) -> None:
    # Under nohup stdout is a file and Python buffers it: line-buffer so a long
    # run can be watched with tail -f.
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(line_buffering=True)
        except (AttributeError, ValueError):
            pass
    logs = cfg.p("logs")
    logs.mkdir(parents=True, exist_ok=True)
    fmt = logging.Formatter("%(asctime)s %(levelname)s %(name)s %(message)s")

    class Masking(logging.Filter):
        def filter(self, record):
            record.msg = mask(record.getMessage())
            record.args = ()
            return True

    root = logging.getLogger()
    root.setLevel(logging.INFO)
    for h in (logging.StreamHandler(sys.stdout),
              logging.FileHandler(logs / f"research-{time.strftime('%Y%m%d')}.log")):
        h.setFormatter(fmt)
        h.terminator = "\n"
        h.addFilter(Masking())
        root.addHandler(h)


def cmd_fetch(cfg, a):
    from .checks import write_data_report
    from .fetch import Fetcher
    from .store import load_market
    if a.profile_country:
        f = Fetcher(cfg)
        held = pd.read_parquet(cfg.data_dir / "raw" / "profiles.parquet")
        n = f.profiles(held["symbol"].tolist())
        log.info("profiles with a country: %d of %d", int(n["country"].notna().sum()) if "country" in n else 0, len(n))
        summary = f.run(merge_only=True)
    elif a.trust_caps:
        import pandas as _pd
        f = Fetcher(cfg)
        master = _pd.read_parquet(cfg.data_dir / "symbols.parquet")
        f.trust_caps(master, False, time.strftime("%Y-%m-%d"))
        summary = f.run(merge_only=True)
    else:
        summary = Fetcher(cfg).run(full=a.full, merge_only=a.merge_only)
    log.info("fetch done: %s", summary)
    path = write_data_report(load_market(cfg.data_dir), cfg.data_dir)
    log.info("data report: %s", path)


def cmd_cap_trace(cfg, a):
    """For each symbol: the master row, FMP's raw cap, the cap held now, price, shares and the ratio, over a window."""
    from .clean import _cap_over_price_shares, _unit
    d = cfg.data_dir
    master = pd.read_parquet(d / "symbols.parquet").set_index("symbol")
    caps = pd.read_parquet(d / "market_cap.parquet")
    caps["date"] = pd.to_datetime(caps["date"])
    px = pd.read_parquet(d / "prices.parquet", columns=["symbol", "date", "close"])
    px["date"] = pd.to_datetime(px["date"])
    st = pd.read_parquet(d / "statements.parquet", columns=["symbol", "period_end", "weightedAverageShsOut"])
    st = st.dropna(subset=["weightedAverageShsOut"])
    lo, hi = pd.Timestamp(a.start), pd.Timestamp(a.end)
    for sym in a.symbols:
        print(f"\n== {sym}")
        if sym in master.index:
            r = master.loc[sym]
            print({k: (None if pd.isna(r.get(k)) else r.get(k)) for k in
                   ("name", "isin", "country", "currency", "quote_currency", "cap_currency", "included", "exclude_reason")})
        raw_p = d / "raw" / "market_cap" / f"{sym}.parquet"
        raw = pd.read_parquet(raw_p).assign(date=lambda x: pd.to_datetime(x["date"])).set_index("date")["market_cap"] \
            if raw_p.exists() else pd.Series(dtype=float)
        c = caps[caps["symbol"] == sym][["date", "market_cap"]]
        j = _cap_over_price_shares(c, px[px["symbol"] == sym], st[st["symbol"] == sym], _unit(master["quote_currency"].get(sym))
                                   if "quote_currency" in master else 0.01)
        j = j[(j["date"] >= lo) & (j["date"] <= hi)]
        j["fmp_raw_cap"] = j["date"].map(raw)
        print(f"statements held: {int((st['symbol'] == sym).sum())}, shares: "
              f"{st[st['symbol'] == sym].sort_values('period_end')['weightedAverageShsOut'].iloc[[0, -1]].tolist() if (st['symbol'] == sym).any() else 'none'}")
        print(j.iloc[::max(1, len(j) // 12)][["date", "fmp_raw_cap", "market_cap", "close", "implied", "ratio"]].to_string(index=False)
              if len(j) else "no cap and price rows in the window")
        for n in ("cap_rescale_refused", "cap_rescued_by_shares", "cap_rescaled", "cap_spikes"):
            f = d / f"{n}.parquet"
            if f.exists():
                t = pd.read_parquet(f)
                t = t[t["symbol"] == sym]
                if len(t):
                    print(f"-- {n}:\n{t.to_string(index=False)}")


def cmd_universe(cfg, a):
    from .lse import build_status, cap_check, write_report
    from .store import load_market
    from .universe import build_universe, compare_with_live, rebuild_dates
    u = cfg["universe"]
    include_aim = bool(u["include_aim"] or a.aim)
    m = load_market(cfg.data_dir)
    months = pd.DatetimeIndex([pd.Timestamp(d.year, d.month, 1) for d in rebuild_dates(m.dates, u["start"])])
    elig, rep = build_status(cfg.data_dir, m.master.reset_index().rename(columns={"index": "symbol"}),
                             m.market_cap, months, include_aim)
    trust_caps = None
    if u.get("rank_with_trusts", True):
        from .universe import load_trust_caps
        trust_caps = load_trust_caps(cfg.data_dir, m)
        log.info("ranking with %d investment trusts and funds in the count",
                 0 if trust_caps is None else trust_caps.shape[1])
    uni = build_universe(m, u["size"], u["large_size"], u["start"], include_aim, eligible=elig,
                         trust_caps=trust_caps)
    counted, top_trusts = dict(uni.attrs.get("trusts_counted", {})), dict(uni.attrs.get("trusts_top", {}))
    uni.attrs = {}
    uni.to_parquet(cfg.data_dir / "universe.parquet")
    last = uni[uni["date"] == uni["date"].max()]
    if counted:
        latest_d = max(counted)
        n_tr = counted[latest_d]
        names = m.master.get("name", pd.Series(dtype=object))
        rep["trusts_counted_in_top_latest"] = n_tr
        rep["trusts_counted_by_rebuild"] = {str(k.date()): v for k, v in counted.items()}
        rep["trusts_top_20_latest"] = {s: str(names.get(s)) for s in top_trusts.get(latest_d, [])}
        print(f"trusts counted inside the top {u['size']} on {latest_d.date()}: {n_tr} (live index: 92)")
        if not 80 <= n_tr <= 100:
            for s, nm in rep["trusts_top_20_latest"].items():
                print(f"  trust counted: {s} {nm}")
    rep["universe_basis_latest"] = last["basis"].value_counts().to_dict()
    stp = cfg.data_dir / "lse_status.parquet"
    if stp.exists():
        st = pd.read_parquet(stp)
        st_last = st[st["month"] == st["month"].max()].set_index("symbol")
        flagged = [s for s in last["symbol"] if s in st_last.index and bool(st_last.at[s, "inflated_cap"])]
        rep["universe_members_latest_fmp_cap_over_3x_lse"] = flagged
        ovr = st_last["override"].dropna() if "override" in st_last else pd.Series(dtype=object)
        rep["overrides_applied_latest"] = {s: v for s, v in ovr.items()}
        m_names = m.master.get("name", pd.Series(dtype=object))
        aimish = [s for s in last["symbol"] if s in st_last.index and str(st_last.at[s, "market"]) == "AIM"]
        rep["universe_members_latest_on_aim (should be none)"] = aimish
        import difflib
        from .lse import norm_name
        lm = cfg.data_dir / "lse_matched.parquet"
        latest_names = []
        if lm.exists():
            mt = pd.read_parquet(lm)
            latest_names = sorted(set(mt.loc[mt["month"] == mt["month"].max(), "norm"].dropna()))
        rep["universe_members_latest_without_lse_match"] = {
            s: {"fmp_name": str(m_names.get(s)), "normalised": norm_name(m_names.get(s)),
                "closest_lse_names": difflib.get_close_matches(norm_name(m_names.get(s)), latest_names, n=3, cutoff=0.6)}
            for s in last.loc[last["basis"] != "LSE list", "symbol"]}
    rep["universe_members_latest_not_in_lse_lists"] = sorted(last.loc[last["basis"] == "ISIN fallback", "symbol"])
    gaps = cap_check(uni, cfg.data_dir)
    if len(gaps):
        gaps.to_csv(cfg.data_dir / "lse_cap_gaps.csv", index=False)
    rep["cap_gaps_over_25pct"] = {"rows": int(len(gaps)),
                                  "latest": gaps[gaps["date"] == gaps["date"].max()].to_dict("records") if len(gaps) else []}
    log.info("LSE report: %s", write_report(cfg.data_dir, rep))
    from .checks import write_data_report
    log.info("data report (with universe-only counts): %s", write_data_report(m, cfg.data_dir))
    per = uni.groupby("date").size()
    print(f"{per.size} monthly rebuilds from {per.index.min().date()} to {per.index.max().date()}; "
          f"members per rebuild {per.min()} to {per.max()}")
    if a.live:
        c = compare_with_live(uni, Path(a.live))
        c["why_missing"] = explain_missing(cfg, m, c["only_live"], uni)
        (cfg.data_dir / "universe_overlap.json").write_text(json.dumps(c, indent=1, default=str))
        print(f"latest rebuild {c['date']}: {c['both']} of the live {c['live']} non-fund shares are in it "
              f"({c['overlap_pct']}%); only rebuilt {len(c['only_rebuilt'])}, only live {len(c['only_live'])}")
        for s, why in c["why_missing"].items():
            print(f"  missing {s}: {why}")


def explain_missing(cfg, m, symbols, uni) -> dict:
    """Why each live share is not in the latest rebuild."""
    st_path = cfg.data_dir / "lse_status.parquet"
    st = pd.read_parquet(st_path) if st_path.exists() else pd.DataFrame()
    d = uni["date"].max()
    month = pd.Timestamp(d.year, d.month, 1)
    out = {}
    for s in symbols:
        if s not in m.master.index:
            out[s] = "not in the FMP master"
            continue
        r = m.master.loc[s]
        if not bool(r.get("included", True)):
            out[s] = f"excluded: {r.get('exclude_reason')}"
            continue
        if s not in m.close.columns or pd.isna(m.close[s].loc[:d].iloc[-5:]).all():
            out[s] = "no price on the rebuild date"
            continue
        row = st[(st["symbol"] == s) & (st["month"] == month)] if len(st) else st
        if len(row):
            x = row.iloc[0]
            if not bool(x["eligible"]):
                out[s] = (f"LSE status: market {x['market']}, country {x['country']}, "
                          f"{x['overseas_rule']}{', FMP cap over 3x LSE' if x.get('inflated_cap') else ''}")
                continue
        cap = m.market_cap[s].loc[:d].dropna()
        out[s] = f"ranked below 350 (market cap {cap.iloc[-1]:,.0f})" if len(cap) else "no market cap"
    return out


def cmd_run(cfg, a):
    from .strategy import Workspace, load_spec
    ws = Workspace(cfg)
    for path in a.strategy:
        spec = load_spec(path)
        t = time.time()
        out = ws.run(spec, a.period, a.final)
        r = out["record"]
        print(f"{spec.name} [{a.period}] {r['start']}..{r['end']}: {r['annual_return']*100:.1f}% a year, "
              f"EW {r['bench_equal_weight_annual']*100:.1f}%, fall {r['max_drawdown']*100:.1f}%, "
              f"Sharpe {r['sharpe']:.2f}, deflated {r['deflated_sharpe']:.2f}, run {r['run_id']} "
              f"({time.time()-t:.1f}s) -> {out['report']}")


def cmd_signals(cfg, a):
    from .strategy import Workspace, spec_from_dict
    ws = Workspace(cfg)
    names = FIRST_SIGNALS + ["trend_following", "trending_value"]
    periods = [a.period] if a.period else ["dev", "val"]
    rows = []
    for name in names:
        for p in periods:
            spec = spec_from_dict({"name": f"{name}_q5_monthly", "family": name, "signals": {name: 1},
                                   "holdings": "quintile", "rebalance": "monthly", "universe": "ftse350"})
            r = ws.run(spec, p)["record"]
            rows.append({"signal": name, "period": p, "return_a_year": r["annual_return"],
                         "vs_equal_weight": r["excess_equal_weight_annual"], "max_fall": r["max_drawdown"],
                         "turnover": r["turnover_a_year"], "costs_a_year": r["costs_pct_a_year"],
                         "deflated_sharpe": r["deflated_sharpe"], "run_id": r["run_id"]})
    df = pd.DataFrame(rows)
    out = cfg.p("reports") / f"signals-{time.strftime('%Y%m%d-%H%M%S')}.csv"
    df.to_csv(out, index=False)
    with pd.option_context("display.width", 200, "display.float_format", "{:.3f}".format):
        print(df.drop(columns="run_id").to_string(index=False))
    print(f"\nwritten to {out}\nDevelopment rows are survivorship-biased.")


def cmd_lse_rows(cfg, a):
    """Every row of the LSE issuer lists for a name, before any de-duplication, with how it matched."""
    from .lse import norm_name, read_issuer_lists
    folder = cfg.data_dir / "reference" / "lse_issuer_lists"
    df, _ = read_issuer_lists(folder, dedupe=False)
    key = norm_name(a.text) or a.text.upper()
    hit = df[df["company"].str.upper().str.contains(a.text.upper(), regex=False) | df["norm"].str.contains(key, regex=False)]
    if a.year:
        hit = hit[hit["month"].dt.year == a.year]
    mp = cfg.data_dir / "lse_matched.parquet"
    matched = pd.read_parquet(mp) if mp.exists() else pd.DataFrame()
    cols = [c for c in ("month", "company", "norm", "market", "country", "category", "cap_m", "source") if c in hit]
    with pd.option_context("display.width", 250, "display.max_columns", 20, "display.max_colwidth", 60):
        print(hit.sort_values(["month", "company"])[cols].to_string(index=False) if len(hit) else "no rows")
        if len(matched) and len(hit):
            m = matched[matched["norm"].isin(hit["norm"]) | matched["company"].isin(hit["company"])]
            last = m[m["month"] == m["month"].max()]
            print("\nmatched to FMP lines in the latest month:")
            print(last[["company", "norm", "market", "symbol"]].to_string(index=False) if len(last) else "none")
        sp = cfg.data_dir / "lse_status.parquet"
        if sp.exists() and len(matched):
            st = pd.read_parquet(sp)
            syms = set(matched.loc[matched["company"].isin(hit["company"]), "symbol"].dropna())
            s = st[st["symbol"].isin(syms) & (st["month"] == st["month"].max())]
            print("\nstatus in the latest month:")
            print(s[["symbol", "market", "country", "overseas_rule", "eligible"]].to_string(index=False) if len(s) else "none")


def cmd_compare(cfg, a):
    from .runlog import RunLog
    df = RunLog(cfg.p("run_log")).read()
    if df.empty:
        print("the log is empty")
        return
    f = df[df["family"] == a.family]
    cols = ["run_id", "strategy", "period", "final", "annual_return", "excess_equal_weight_annual",
            "max_drawdown", "sharpe", "deflated_sharpe", "turnover_a_year", "costs_pct_a_year"]
    print(f"{a.family}: {len(f)} runs logged (every variant counts as a trial)")
    with pd.option_context("display.width", 220, "display.float_format", "{:.3f}".format):
        print(f[[c for c in cols if c in f]].to_string(index=False))


def cmd_report(cfg, a):
    from .runlog import RunLog
    df = RunLog(cfg.p("run_log")).read()
    r = df[df["run_id"] == a.run_id]
    if r.empty:
        sys.exit(f"no run {a.run_id}")
    for k, v in r.iloc[0].items():
        print(f"{k:32} {v}")
    found = sorted(cfg.p("reports").glob(f"{a.run_id}-*.html"))
    if found:
        print(f"\nreport: {found[0]}")


def cmd_validate(cfg, a):
    from .strategy import Workspace
    from .validate import run9
    v = cfg.data_dir / "validation"
    uni = Path(a.universe) if a.universe else v / "n100_universe_2026-11-18.tsv"
    orders = Path(a.orders) if a.orders else v / "n100_run9_orders.csv"
    values = Path(a.values) if a.values else v / "n100_run9_values.csv"
    replay = None
    if a.replay_targets is not None:
        replay = Path(a.replay_targets) if a.replay_targets else v / "n100_run9_targets.csv"
    rep = run9(Workspace(cfg), uni, orders if orders.exists() else None, values if values.exists() else None,
               replay_targets=replay)
    pct = lambda x: "-" if x is None else f"{x * 100:+.2f}%"
    print(f"| | N100 run 9 | Research engine ({rep.get('price_basis')}) |\n|---|---|---|")
    print(f"| Total return | {pct(rep.get('n100_total_return'))} | {pct(rep.get('ours_total_return'))} |")
    print(f"| Fills | {rep.get('n100_fills', '-')} | {rep.get('ours_fills')} |")
    print(f"| First value | {rep.get('n100_first_value', '-')} | {rep.get('ours_first_value', '-')} |")
    print(f"| Months with identical holdings | {rep.get('months_with_identical_holdings', '-')} | |")
    print(f"return gap {rep.get('return_gap_pp', float('nan')):+.2f} pp, fill gap {rep.get('fill_gap_pct', float('nan')):+.1f}%: "
          f"{'PASSED' if rep.get('passed') else 'FAILED'}\n")
    out = cfg.data_dir / "validation" / ("run9_replay_check.json" if replay else "run9_check.json")
    out.write_text(json.dumps(rep, indent=1, default=str))
    print(json.dumps(rep, indent=1, default=str))
    sys.exit(0 if rep.get("passed") else 1)


def cmd_worker(cfg, a):
    from .worker import main as worker_main
    worker_main(cfg, once=a.once)


def main(argv=None):
    p = argparse.ArgumentParser(prog="research", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--config", default=None)
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("fetch"); s.add_argument("--full", action="store_true")
    s.add_argument("--merge-only", action="store_true",
                   help="no downloads: merge the per-symbol files, refine the master, clean prices, report")
    s.add_argument("--profile-country", action="store_true",
                   help="fetch the profile again, once, for held lines with no ISIN (their country decides "
                        "whether they are UK companies), then merge")
    s.add_argument("--trust-caps", action="store_true",
                   help="fetch market caps of investment trusts only (for ranking like the FTSE 350), then merge")
    s = sub.add_parser("cap-trace", help="one symbol's raw and held market cap against price x shares over a window")
    s.add_argument("symbols", nargs="+"); s.add_argument("--from", dest="start", default="2007-01-01")
    s.add_argument("--to", dest="end", default="2008-12-31")
    s = sub.add_parser("universe"); s.add_argument("--live"); s.add_argument("--aim", action="store_true")
    s = sub.add_parser("run"); s.add_argument("strategy", nargs="+")
    s.add_argument("--period", default="dev"); s.add_argument("--final", action="store_true")
    s = sub.add_parser("signals"); s.add_argument("--period", choices=["dev", "val"])
    s = sub.add_parser("lse-rows", help="print the LSE issuer-list rows whose company name contains TEXT")
    s.add_argument("text"); s.add_argument("--year", type=int, default=None)
    s = sub.add_parser("compare"); s.add_argument("family")
    s = sub.add_parser("report"); s.add_argument("run_id")
    s = sub.add_parser("validate")
    s.add_argument("--universe", help="default data/validation/n100_universe_2026-11-18.tsv")
    s.add_argument("--orders", help="default data/validation/n100_run9_orders.csv")
    s.add_argument("--replay-targets", nargs="?", const="", default=None,
                   help="hold the N100's own targets (default data/validation/n100_run9_targets.csv): an engine-only check")
    s.add_argument("--values", help="default data/validation/n100_run9_values.csv")
    s = sub.add_parser("worker", help="run sharemanager's queued backtests, one at a time (worker.py)")
    s.add_argument("--once", action="store_true", help="claim and run at most one, then stop")
    a = p.parse_args(argv)
    cfg = load_config(a.config)
    _setup_logging(cfg)
    try:
        {"fetch": cmd_fetch, "cap-trace": cmd_cap_trace, "universe": cmd_universe, "run": cmd_run, "signals": cmd_signals,
         "lse-rows": cmd_lse_rows, "compare": cmd_compare, "report": cmd_report, "validate": cmd_validate,
         "worker": cmd_worker}[a.cmd](cfg, a)
    except Exception as e:     # never let a key reach the terminal in a traceback
        log.error("%s failed: %s", a.cmd, mask(e))
        if __import__("os").environ.get("RESEARCH_DEBUG"):
            raise
        sys.exit(2)


if __name__ == "__main__":
    main()
