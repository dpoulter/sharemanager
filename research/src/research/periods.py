"""Development, validation and the locked holdout."""
from __future__ import annotations

import pandas as pd

from .config import Config


class HoldoutLocked(Exception):
    pass


LABELS = {
    "dev": "DEVELOPMENT 2007-2021: survivorship-biased (few dead companies before 2021)",
    "val": "VALIDATION 2022 to 17 May 2025: dead companies included",
    "holdout": "HOLDOUT (final exam): strategy and settings frozen from this run",
}


def period_dates(cfg: Config, period: str, latest) -> tuple[pd.Timestamp, pd.Timestamp]:
    p = cfg["periods"]
    if period in ("dev", "val"):
        a, b = p[period]
        return pd.Timestamp(a), pd.Timestamp(b)
    if period == "holdout":
        return pd.Timestamp(p["holdout_start"]), pd.Timestamp(latest)
    if ":" in period:
        a, b = period.split(":", 1)
        return pd.Timestamp(a), pd.Timestamp(b or latest)
    raise ValueError(f"unknown period {period!r} (dev, val, holdout or FROM:TO)")


def check_holdout(cfg: Config, start, end, final: bool) -> bool:
    """Raise unless the period stays clear of the holdout or the run is --final.

    Returns True when the run touches the holdout (and so is a final exam).
    """
    h = pd.Timestamp(cfg["periods"]["holdout_start"])
    touches = pd.Timestamp(end) >= h
    if touches and not final:
        raise HoldoutLocked(
            f"the period {pd.Timestamp(start).date()} to {pd.Timestamp(end).date()} reaches the holdout "
            f"(from {h.date()}). It is locked: run with --final to sit the final exam, which freezes "
            f"the strategy and its settings.")
    return touches
