"""One self-contained HTML file per run: metrics, yearly table, value chart, caveats."""
from __future__ import annotations

import html
import json
from pathlib import Path

import numpy as np
import pandas as pd

from .config import Config


def _pct(x, places=1):
    try:
        return "-" if x is None or not np.isfinite(float(x)) else f"{float(x) * 100:.{places}f}%"
    except (TypeError, ValueError):
        return "-"


def _num(x, places=2):
    try:
        return "-" if x is None or not np.isfinite(float(x)) else f"{float(x):,.{places}f}"
    except (TypeError, ValueError):
        return "-"


def _chart(series: dict[str, pd.Series], width=860, height=320) -> str:
    """An inline SVG line chart, log scale, rebased to 100."""
    colours = {"strategy": "var(--a)", "equal_weight": "var(--b)", "cap_weight": "var(--c)"}
    frame = pd.concat(series, axis=1).ffill().dropna()
    frame = frame / frame.iloc[0] * 100
    lo, hi = np.log(frame.min().min()), np.log(frame.max().max())
    if hi - lo < 1e-9:
        hi = lo + 1
    pad = 40
    n = len(frame)
    xs = np.linspace(pad, width - 10, n)

    def y(v):
        return height - pad - (np.log(v) - lo) / (hi - lo) * (height - 2 * pad)

    paths = []
    for name in frame.columns:
        pts = " ".join(f"{x:.1f},{y(v):.1f}" for x, v in zip(xs, frame[name].to_numpy()))
        paths.append(f'<polyline fill="none" stroke="{colours.get(name, "gray")}" stroke-width="1.6" points="{pts}"/>')
    ticks = []
    for v in sorted({50, 100, 200, 400, 800, 1600} | {int(round(frame.iloc[-1, 0]))}):
        if np.exp(lo) <= v <= np.exp(hi):
            ticks.append(f'<line x1="{pad}" x2="{width-10}" y1="{y(v):.1f}" y2="{y(v):.1f}" class="grid"/>'
                         f'<text x="4" y="{y(v)+4:.1f}" class="tick">{v}</text>')
    years = frame.index.year
    for i in np.where(np.r_[True, years[1:] != years[:-1]])[0]:
        ticks.append(f'<text x="{xs[i]:.1f}" y="{height-12}" class="tick">{years[i]}</text>')
    legend = "".join(f'<span><i style="background:{colours.get(c, "gray")}"></i>{html.escape(c.replace("_", " "))}</span>'
                     for c in frame.columns)
    return (f'<div class="legend">{legend}</div><svg viewBox="0 0 {width} {height}" role="img" '
            f'aria-label="Value against the benchmarks">{"".join(ticks)}{"".join(paths)}</svg>')


def write_report(cfg: Config, result: dict, trials: int, period_label: str | None) -> Path:
    rec = result["record"]
    res = result["result"]
    out_dir = cfg.p("reports")
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / f"{rec['run_id']}-{rec['strategy']}.html"
    rows = [
        ("Total return", _pct(rec["total_return"])), ("Return a year", _pct(rec["annual_return"])),
        ("Equal-weight benchmark a year", _pct(rec.get("bench_equal_weight_annual"))),
        ("Cap-weighted benchmark a year", _pct(rec.get("bench_cap_weight_annual"))),
        ("Against equal weight, a year", _pct(rec.get("excess_equal_weight_annual"))),
        ("Maximum fall", f"{_pct(rec['max_drawdown'])} ({rec['drawdown_peak']} to {rec['drawdown_trough']}"
                         f"{', recovered ' + rec['drawdown_recovered'] if rec['drawdown_recovered'] else ', not recovered'})"),
        ("Volatility", _pct(rec["volatility"])), ("Sharpe", _num(rec["sharpe"])),
        ("Deflated Sharpe (SR - SR0)", _num(rec.get("deflated_sharpe"))),
        ("DSR probability", _pct(rec.get("dsr"))),
        ("Turnover a year", _pct(rec["turnover_a_year"], 0)), ("Costs a year", _pct(rec["costs_pct_a_year"], 2)),
        ("Return a year at zero cost", _pct(rec.get("zero_cost_annual"))),
        ("Fills", str(rec["fills"])), ("Holdings (mean, min, max)",
                                        f"{rec['holdings_mean']:.1f}, {rec['holdings_min']}, {rec['holdings_max']}"),
        ("Share of return from the top 5 holdings", _pct(rec["top5_share_of_return"], 0)),
        ("Rebalances refused by the turnover cap", str(rec["rebalance_refusals"])),
        ("Variants of this family tried", str(trials)),
    ]
    yearly = result["yearly"]
    yhead = "".join(f"<th>{html.escape(c.replace('_', ' '))}</th>" for c in yearly.columns)
    ybody = "".join(f"<tr><td>{y}</td>" + "".join(f"<td>{_pct(v)}</td>" for v in r) + "</tr>"
                    for y, r in zip(yearly.index, yearly.to_numpy()))
    caveats = list(cfg.get("caveats", []))
    if period_label:
        caveats.insert(0, period_label)
    if rec.get("touches_holdout"):
        caveats.insert(0, "This run covers holdout dates." + (" It is the final exam." if rec.get("final") else
                                                             " It is an engine check, not a research result."))
    chart = _chart({"strategy": res.values["total"], **{c: result["bench"][c] for c in result["bench"]}})
    params = html.escape(json.dumps({"signals": rec["signals"], **(json.loads(rec["parameters"]) if isinstance(rec["parameters"], str) else rec["parameters"])}, default=str))
    page = f"""<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(rec['strategy'])} {rec['start']} to {rec['end']}</title>
<style>
:root{{--bg:#fff;--fg:#1d1d1f;--mut:#666;--line:#ddd;--a:#1f5fbf;--b:#c2410c;--c:#6b7280;--warn:#fff7e6}}
@media (prefers-color-scheme:dark){{:root{{--bg:#141414;--fg:#eee;--mut:#aaa;--line:#333;--a:#6ea8ff;--b:#fb923c;--c:#9ca3af;--warn:#2a2210}}}}
body{{background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif;margin:0 auto;max-width:900px;padding:16px}}
table{{border-collapse:collapse;width:100%;margin:8px 0 20px}}td,th{{border-bottom:1px solid var(--line);padding:4px 8px;text-align:right}}
td:first-child,th:first-child{{text-align:left}}.caveats{{background:var(--warn);padding:8px 14px;border-radius:6px}}
svg{{width:100%;height:auto}}.grid{{stroke:var(--line)}}.tick{{fill:var(--mut);font-size:11px}}
.legend span{{margin-right:14px;font-size:13px}}.legend i{{display:inline-block;width:12px;height:3px;margin-right:5px;vertical-align:middle}}
code{{font-size:12px;word-break:break-all}}.mut{{color:var(--mut)}}
</style></head><body>
<h1>{html.escape(rec['strategy'])}</h1>
<p class="mut">Run {rec['run_id']} · {rec['start']} to {rec['end']} · universe {html.escape(str(rec['universe']))} ·
commit {rec['git_commit']} · strategy file {rec['strategy_hash']} · costs checked {rec['costs_checked_on']}</p>
<div class="caveats"><ul>{''.join(f'<li>{html.escape(c)}</li>' for c in caveats)}</ul></div>
<h2>Value against the benchmarks</h2>{chart}
<h2>Results</h2><table>{''.join(f'<tr><td>{k}</td><td>{v}</td></tr>' for k, v in rows)}</table>
<h2>Year by year</h2><table><tr><th>Year</th>{yhead}</tr>{ybody}</table>
<h2>Settings</h2><p><code>{params}</code></p>
</body></html>"""
    path.write_text(page)
    return path
