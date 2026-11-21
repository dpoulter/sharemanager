from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from research.config import load_config
from research.store import Market, finish_market


def make_market(prices: dict[str, list[float]], start="2020-01-01", caps: dict | None = None,
                master: dict | None = None, adj: dict | None = None, statements: pd.DataFrame | None = None,
                delisted: dict | None = None) -> Market:
    """Prices in pounds, one list per symbol (NaN for no trade), on consecutive business days."""
    n = max(len(v) for v in prices.values())
    dates = pd.bdate_range(start, periods=n)
    close = pd.DataFrame({k: pd.Series(v + [np.nan] * (n - len(v)), index=dates) for k, v in prices.items()})
    a = close.copy() if adj is None else pd.DataFrame({k: pd.Series(v, index=dates) for k, v in adj.items()})
    if caps is None:
        cap = close * 1e6
    else:
        cap = pd.DataFrame({k: pd.Series(v if isinstance(v, list) else [v] * n, index=dates) for k, v in caps.items()})
    m = pd.DataFrame(index=close.columns)
    m["included"] = True
    m["is_aim"] = False
    m["currency"] = "GBp"
    m["delisted_date"] = None
    for k, v in (master or {}).items():
        for f, x in v.items():
            m.loc[k, f] = x
    for k, d in (delisted or {}).items():
        m.loc[k, "delisted_date"] = d
    return finish_market(Market(close=close, adj=a, market_cap=cap, master=m,
                                statements=statements if statements is not None else pd.DataFrame()))


def built_workspace(cfg, m, u, names=None):
    """Build the indicators a test needs into the store, then the engine's workspace over them."""
    from research import indicator_job
    reports = indicator_job.run(cfg, names=names, market=m, universe=u)
    bad = [r for r in reports if not r["ok"]]
    assert not bad, bad
    return indicator_job.workspace(cfg, market=m, universe=u)


@pytest.fixture
def cfg(tmp_path):
    return load_config(overrides={"paths": {"data": str(tmp_path / "data"), "reports": str(tmp_path / "reports"),
                                            "logs": str(tmp_path / "logs"),
                                            "run_log": str(tmp_path / "log" / "runs.parquet")}})
