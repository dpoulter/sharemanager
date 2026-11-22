"""indicators (contracts/modules.yaml): how each indicator is defined.

An indicator's value for every company on a date, worked out from prices and
accounts known on that date (accounts only through pit.Fundamentals, the
publication lag). Paper indicators follow the published definition, adapted
to half-yearly UK reporting, and cite the paper (osap.py). The N100-style
indicators are worked out the N100's way (live.py).

Nothing here writes anything, or reads a portfolio, a run or the test log.
The indicator job (indicator_job.py) calls these and hands the values to the
store; the engine reads the store and imports nothing from this package.
"""
from __future__ import annotations

from .base import BASES, REGISTRY, Context, Definition, indicator, key, market_on_basis  # noqa: F401
from .pit import Fundamentals  # noqa: F401
from . import osap, live, piotroski  # noqa: F401  (registers the definitions)


def definitions_by_key() -> dict[str, Definition]:
    return {k: d for d in REGISTRY.values() for k in d.keys()}


def versions(cfg) -> dict[str, str]:
    """Every stored indicator's key -> the version its definition has now."""
    return {k: d.version(cfg, k) for k, d in definitions_by_key().items()}
