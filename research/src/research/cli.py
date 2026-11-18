"""uv run research <command>

  fetch [--full]                  download from FMP (full first pull, else top-up), then the data report
  universe [--live CSV]           rebuild the monthly universe; compare with the N100's list
  run STRATEGY.yaml... [--period dev|val|FROM:TO] [--final]
  signals [--period dev|val]      each first signal alone, top quintile monthly; one comparison table
  compare FAMILY                  every logged run of a family, with the trial count
  report RUN_ID                   print a logged run (the HTML is written when the run is)
  validate --universe CSV [--orders CSV] [--values CSV]
                                  reproduce the N100's run 9; nothing else is trusted until it passes
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
    summary = Fetcher(cfg).run(full=a.full, merge_only=a.merge_only)
    log.info("fetch done: %s", summary)
    path = write_data_report(load_market(cfg.data_dir), cfg.data_dir)
    log.info("data report: %s", path)


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
    uni = build_universe(m, u["size"], u["large_size"], u["start"], include_aim, eligible=elig)
    uni.to_parquet(cfg.data_dir / "universe.parquet")
    last = uni[uni["date"] == uni["date"].max()]
    rep["universe_basis_latest"] = last["basis"].value_counts().to_dict()
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
    rep = run9(Workspace(cfg), uni, orders if orders.exists() else None, values if values.exists() else None)
    print(json.dumps(rep, indent=1, default=str))
    sys.exit(0 if rep.get("passed") else 1)


def main(argv=None):
    p = argparse.ArgumentParser(prog="research", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--config", default=None)
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("fetch"); s.add_argument("--full", action="store_true")
    s.add_argument("--merge-only", action="store_true",
                   help="no downloads: merge the per-symbol files, refine the master, clean prices, report")
    s = sub.add_parser("universe"); s.add_argument("--live"); s.add_argument("--aim", action="store_true")
    s = sub.add_parser("run"); s.add_argument("strategy", nargs="+")
    s.add_argument("--period", default="dev"); s.add_argument("--final", action="store_true")
    s = sub.add_parser("signals"); s.add_argument("--period", choices=["dev", "val"])
    s = sub.add_parser("compare"); s.add_argument("family")
    s = sub.add_parser("report"); s.add_argument("run_id")
    s = sub.add_parser("validate")
    s.add_argument("--universe", help="default data/validation/n100_universe_2026-11-18.tsv")
    s.add_argument("--orders", help="default data/validation/n100_run9_orders.csv")
    s.add_argument("--values", help="default data/validation/n100_run9_values.csv")
    a = p.parse_args(argv)
    cfg = load_config(a.config)
    _setup_logging(cfg)
    try:
        {"fetch": cmd_fetch, "universe": cmd_universe, "run": cmd_run, "signals": cmd_signals,
         "compare": cmd_compare, "report": cmd_report, "validate": cmd_validate}[a.cmd](cfg, a)
    except Exception as e:     # never let a key reach the terminal in a traceback
        log.error("%s failed: %s", a.cmd, mask(e))
        if __import__("os").environ.get("RESEARCH_DEBUG"):
            raise
        sys.exit(2)


if __name__ == "__main__":
    main()
