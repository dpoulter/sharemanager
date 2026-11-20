"""Metrics per run, and the deflated Sharpe ratio."""
from __future__ import annotations

import math
from statistics import NormalDist

import numpy as np
import pandas as pd

TRADING_DAYS = 252
EULER = 0.5772156649015329
N = NormalDist()


def annual(total_return: float, days: int) -> float:
    years = days / TRADING_DAYS
    return (1 + total_return) ** (1 / years) - 1 if years > 0 and total_return > -1 else float("nan")


def drawdown(v: pd.Series) -> tuple[float, str, str, str | None]:
    """Largest fall, its peak date, trough date and recovery date (None if not recovered)."""
    peak = v.cummax()
    dd = v / peak - 1
    trough = dd.idxmin()
    peak_date = v.loc[:trough].idxmax()
    after = v.loc[trough:]
    rec = after[after >= v.loc[peak_date]]
    return (float(-dd.min()), str(peak_date)[:10], str(trough)[:10],
            str(rec.index[0])[:10] if len(rec) else None)


def sharpe(daily: pd.Series) -> float:
    sd = daily.std()
    return float(daily.mean() / sd * math.sqrt(TRADING_DAYS)) if sd > 0 else float("nan")


def summarise(values: pd.Series, bench: pd.DataFrame, fills: pd.DataFrame, holdings: pd.Series,
              pnl: pd.Series, zero_cost_values: pd.Series | None = None) -> dict:
    v = values.dropna()
    days = len(v) - 1
    tr = float(v.iloc[-1] / v.iloc[0] - 1)
    daily = v.pct_change().dropna()
    out = {
        "start": str(v.index[0])[:10], "end": str(v.index[-1])[:10], "trading_days": days,
        "start_value": float(v.iloc[0]), "final_value": float(v.iloc[-1]),
        "total_return": tr, "annual_return": annual(tr, days),
        "volatility": float(daily.std() * math.sqrt(TRADING_DAYS)), "sharpe": sharpe(daily),
        "skew": float(daily.skew()), "kurtosis": float(daily.kurt() + 3),
        "observations": int(len(daily)),
    }
    dd, p, t, r = drawdown(v)
    out.update(max_drawdown=dd, drawdown_peak=p, drawdown_trough=t, drawdown_recovered=r)
    for name in bench.columns:
        b = bench[name].reindex(v.index).ffill()
        btr = float(b.iloc[-1] / b.iloc[0] - 1)
        out[f"bench_{name}_return"] = btr
        out[f"bench_{name}_annual"] = annual(btr, days)
        out[f"excess_{name}_annual"] = out["annual_return"] - annual(btr, days)
    years = max(days / TRADING_DAYS, 1e-9)
    avg_value = float(v.mean())
    if len(fills):
        buys = fills.loc[fills["side"] == "BUY", "consideration"].sum()
        sells = fills.loc[fills["side"] == "SELL", "consideration"].sum()
        cost = fills[["stamp_duty", "spread", "commission", "ptm"]].sum()
    else:
        buys = sells = 0.0
        cost = pd.Series({"stamp_duty": 0.0, "spread": 0.0, "commission": 0.0, "ptm": 0.0})
    out["fills"] = int(len(fills))
    out["turnover_a_year"] = float(min(buys, sells) / avg_value / years) if avg_value else float("nan")
    out["traded_a_year"] = float((buys + sells) / avg_value / years) if avg_value else float("nan")
    out["costs_paid"] = float(cost.sum())
    out["costs_pct_a_year"] = float(cost.sum() / avg_value / years) if avg_value else float("nan")
    for k, x in cost.items():
        out[f"costs_{k}"] = float(x)
    if zero_cost_values is not None:
        z = zero_cost_values.dropna()
        ztr = float(z.iloc[-1] / z.iloc[0] - 1)
        out["zero_cost_annual"] = annual(ztr, days)
        out["cost_drag_annual"] = out["zero_cost_annual"] - out["annual_return"]
    out["holdings_mean"] = float(holdings.mean())
    out["holdings_min"] = int(holdings.min())
    out["holdings_max"] = int(holdings.max())
    gain = float(pnl.sum())
    top5 = float(pnl.sort_values(ascending=False).head(5).sum())
    out["top5_share_of_return"] = top5 / gain if gain > 0 else float("nan")
    return out


def yearly(values: pd.Series, bench: pd.DataFrame) -> pd.DataFrame:
    frame = pd.concat([values.rename("strategy"), bench], axis=1).ffill()
    ends = frame.groupby(frame.index.year).last()
    starts = frame.iloc[[0]].copy()
    starts.index = [ends.index[0] - 1]
    seq = pd.concat([starts, ends])
    return (seq / seq.shift(1) - 1).iloc[1:]


def expected_max_sharpe(n_trials: int, var_sharpe: float) -> float:
    """SR0: the best Sharpe expected from n_trials of luck alone (Bailey & López de Prado 2014)."""
    if n_trials <= 1 or var_sharpe <= 0:
        return 0.0
    return math.sqrt(var_sharpe) * ((1 - EULER) * N.inv_cdf(1 - 1 / n_trials)
                                    + EULER * N.inv_cdf(1 - 1 / (n_trials * math.e)))


# Below this many DISTINCT trial Sharpes in a family the deflation means nothing. SR0 rests on
# the variance of the Sharpe across trials; two or three trials cannot estimate a variance, and
# re-running the same rules (same result) gives a variance of zero, so SR0 comes out as zero and
# the "deflated" Sharpe equals the plain one (run 19: trial count 2, both 0.338). Five is the
# smallest family where the expected maximum of the trials (Bailey and Lopez de Prado's SR0) is a
# number worth reading rather than an artefact.
MIN_TRIALS_FOR_DEFLATION = 5


def deflated_sharpe(sr_annual: float, observations: int, skew: float, kurtosis: float,
                    n_trials: int, trial_sharpes_annual: list[float] | None = None) -> dict:
    """The deflated Sharpe ratio.

    Bailey, D. and López de Prado, M. (2014), "The Deflated Sharpe Ratio:
    Correcting for Selection Bias, Backtest Overfitting and Non-Normality",
    Journal of Portfolio Management 40(5).

    Works in per-observation (daily) Sharpe. The variance of the Sharpe
    across trials comes from the logged trials of the same family; with
    fewer than two it falls back to the variance of a single Sharpe estimate.
    Returns the probability (DSR) and the deflated excess Sharpe
    (SR - SR0, annualised): the strategy passes when that is positive.
    Also psr, the same probability with SR0 = 0 (no deflation), and
    deflation_meaningful: False while the family has fewer than
    MIN_TRIALS_FOR_DEFLATION distinct trial Sharpes, when dsr and the
    deflated Sharpe are not to be read as deflated.
    """
    distinct = len({round(float(s), 6) for s in (trial_sharpes_annual or []) if np.isfinite(s)})
    meaningful = distinct >= MIN_TRIALS_FOR_DEFLATION
    if not np.isfinite(sr_annual) or observations < 3:
        return {"dsr": float("nan"), "psr": float("nan"), "sr0_annual": float("nan"),
                "deflated_sharpe": float("nan"), "trials": int(n_trials), "distinct_trials": distinct,
                "deflation_meaningful": meaningful}
    k = math.sqrt(TRADING_DAYS)
    sr = sr_annual / k
    if trial_sharpes_annual and len([s for s in trial_sharpes_annual if np.isfinite(s)]) >= 2:
        arr = np.array([s for s in trial_sharpes_annual if np.isfinite(s)]) / k
        var = float(arr.var(ddof=1))
    else:
        var = (1 - skew * sr + (kurtosis - 1) / 4 * sr ** 2) / (observations - 1)
    sr0 = expected_max_sharpe(max(1, n_trials), var)
    denom = math.sqrt(max(1e-12, 1 - skew * sr + (kurtosis - 1) / 4 * sr ** 2))
    z = (sr - sr0) * math.sqrt(observations - 1) / denom
    z0 = sr * math.sqrt(observations - 1) / denom
    return {"dsr": N.cdf(z), "psr": N.cdf(z0), "sr0_annual": sr0 * k, "deflated_sharpe": (sr - sr0) * k,
            "trials": int(n_trials), "distinct_trials": distinct, "deflation_meaningful": meaningful}
