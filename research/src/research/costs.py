"""Trading 212 dealing costs for UK shares, in one place.

Checked against Trading 212's published fees and HMRC rates on 18 Nov 2026
(from the brief; re-check before relying on them and change CHECKED_ON).
"""
from __future__ import annotations

from dataclasses import dataclass, replace

CHECKED_ON = "2026-11-18"


@dataclass(frozen=True)
class Costs:
    commission: float = 0.0            # pounds per order; Trading 212 charges none
    stamp_duty: float = 0.005          # on buys of main-market shares
    stamp_duty_aim: float = 0.0        # AIM shares pay none
    spread_large_bp: float = 10.0      # FTSE 100-like, each way
    spread_mid_bp: float = 25.0        # FTSE 250-like, each way
    ptm_levy: float = 1.0              # pounds per trade over the threshold
    ptm_threshold: float = 10_000.0
    minimum_order: float = 10.0        # Trading 212's minimum order value
    decimals: int = 2                  # fractional shares to this many places; 0 = whole shares

    def spread(self, large: bool) -> float:
        return (self.spread_large_bp if large else self.spread_mid_bp) / 10_000

    def stamp(self, aim: bool) -> float:
        return self.stamp_duty_aim if aim else self.stamp_duty

    def ptm(self, consideration: float) -> float:
        return self.ptm_levy if consideration > self.ptm_threshold else 0.0

    def buy(self, quantity: float, price: float, large: bool, aim: bool = False) -> dict:
        """Costs of one buy fill. `price` is in pounds."""
        c = quantity * price
        out = {"consideration": c, "commission": self.commission, "stamp_duty": c * self.stamp(aim),
               "spread": c * self.spread(large), "ptm": self.ptm(c)}
        out["total"] = out["commission"] + out["stamp_duty"] + out["spread"] + out["ptm"]
        out["cash"] = -(c + out["total"])
        return out

    def sell(self, quantity: float, price: float, large: bool, aim: bool = False) -> dict:
        c = quantity * price
        out = {"consideration": c, "commission": self.commission, "stamp_duty": 0.0,
               "spread": c * self.spread(large), "ptm": self.ptm(c)}
        out["total"] = out["commission"] + out["spread"] + out["ptm"]
        out["cash"] = c - out["total"]
        return out

    def free(self) -> "Costs":
        """The same rules at zero cost, so the cost drag can be read off."""
        return replace(self, commission=0.0, stamp_duty=0.0, stamp_duty_aim=0.0,
                       spread_large_bp=0.0, spread_mid_bp=0.0, ptm_levy=0.0)


T212 = Costs()


def from_dict(d: dict | None) -> Costs:
    return replace(T212, **(d or {}))
