"""Signals: a function per signal taking the point-in-time context and a date,
returning a score per symbol where higher is better and NaN means not
eligible. Ranking happens within the universe on the date, in the engine.
"""
from __future__ import annotations

from .base import REGISTRY, Context, blend, signal  # noqa: F401
from . import osap, live  # noqa: F401  (registers the signals)
