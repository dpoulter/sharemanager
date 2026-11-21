"""indicator_store (contracts/modules.yaml): where indicator values are kept.

One Parquet file per indicator under data/indicators/: `<key>.parquet`, with
columns date, symbol, value (a row only where the company has a value). The
file's metadata holds the definition version, the data version the values
were built from, the calendar, and every date that was built (a date can be
built and still have no company with a value).

- A new version replaces the indicator whole; an update with another version
  is refused, so versions are never mixed in one file.
- A read asks for dates; if any of them was not built it is refused, naming
  the missing dates, rather than return part of what was asked.
- coverage() lists each indicator's first and last date and the companies
  with a value in each year.
- It never works out a value, and never holds a date later than the data the
  values came from (`data_last_date` on every write).

Nothing else knows this layout: the job writes through write/update/mark, the
engine and the translator read through has/meta/read.
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

META_KEY = b"indicator"


class StoreError(LookupError):
    pass


class NotInStore(StoreError):
    def __init__(self, key: str):
        super().__init__(f"indicator {key} is not in the store; run `research indicators`")
        self.key = key


class MissingDates(StoreError):
    def __init__(self, key: str, dates):
        ds = [str(pd.Timestamp(d).date()) for d in dates]
        shown = ", ".join(ds[:20]) + (f" and {len(ds) - 20} more" if len(ds) > 20 else "")
        super().__init__(f"indicator {key} has no values built for {shown}; nothing was read")
        self.key = key
        self.dates = ds


class VersionMismatch(StoreError):
    pass


@dataclass
class Values:
    key: str
    frame: pd.DataFrame        # date x symbol, exactly the dates asked for
    version: str
    data_version: int
    calendar: str


def _ts(dates) -> pd.DatetimeIndex:
    return pd.DatetimeIndex(pd.to_datetime(list(dates))).normalize().unique().sort_values()


class IndicatorStore:
    def __init__(self, root: Path):
        self.root = Path(root)

    @classmethod
    def for_config(cls, cfg) -> "IndicatorStore":
        return cls(cfg.data_dir / "indicators")

    # ---- reading
    def path(self, key: str) -> Path:
        if "/" in key or key.startswith("."):
            raise ValueError(f"not an indicator key: {key!r}")
        return self.root / f"{key}.parquet"

    def keys(self) -> list[str]:
        if not self.root.exists():
            return []
        return sorted(p.stem for p in self.root.glob("*.parquet"))

    def has(self, key: str) -> bool:
        return self.path(key).exists()

    def meta(self, key: str) -> dict:
        p = self.path(key)
        if not p.exists():
            raise NotInStore(key)
        md = pq.read_schema(p).metadata or {}
        m = json.loads(md[META_KEY])
        m["dates"] = pd.DatetimeIndex(pd.to_datetime(m["dates"]))
        return m

    def read(self, key: str, dates) -> Values:
        """The values on exactly these dates, date x symbol. Refused, naming the dates, if any was not built."""
        m = self.meta(key)
        want = _ts(dates)
        missing = want.difference(m["dates"])
        if len(missing):
            raise MissingDates(key, missing)
        if len(want):
            t = pq.read_table(self.path(key), filters=[("date", ">=", want[0].to_pydatetime()),
                                                       ("date", "<=", want[-1].to_pydatetime())])
            df = t.to_pandas()
        else:
            df = pd.DataFrame(columns=["date", "symbol", "value"])
        df["date"] = pd.to_datetime(df["date"])
        df = df[df["date"].isin(want)]
        wide = df.pivot(index="date", columns="symbol", values="value") if len(df) else pd.DataFrame()
        wide = wide.reindex(want).astype(float)
        wide.index.name = "date"
        wide.columns.name = None
        return Values(key=key, frame=wide, version=m["version"], data_version=int(m["data_version"]),
                      calendar=m["calendar"])

    def read_range(self, key: str, start, end, calendar=None) -> Values:
        """Every date from start to end. `calendar` is the dates the caller expects in that range (its trading
        days, say); without it the range is the built dates in it, and the range must lie inside them."""
        start, end = pd.Timestamp(start), pd.Timestamp(end)
        if calendar is None:
            m = self.meta(key)
            built = m["dates"]
            if len(built) == 0 or start < built[0] or end > built[-1]:
                lo = built[0] if len(built) else None
                hi = built[-1] if len(built) else None
                outside = [d for d in (start, end) if lo is None or d < lo or d > hi]
                raise MissingDates(key, outside)
            calendar = built[(built >= start) & (built <= end)]
        cal = _ts(calendar)
        return self.read(key, cal[(cal >= start) & (cal <= end)])

    def frame(self, key: str) -> pd.DataFrame:
        """All rows, long (date, symbol, value)."""
        df = pq.read_table(self.path(key)).to_pandas()
        df["date"] = pd.to_datetime(df["date"])
        return df

    def coverage(self) -> pd.DataFrame:
        rows = []
        for k in self.keys():
            m = self.meta(k)
            df = pq.read_table(self.path(k), columns=["date", "symbol"]).to_pandas()
            per_year = df.groupby(pd.to_datetime(df["date"]).dt.year)["symbol"].nunique() if len(df) else pd.Series(dtype=int)
            rows.append({"indicator": k, "first": str(m["dates"][0].date()) if len(m["dates"]) else None,
                         "last": str(m["dates"][-1].date()) if len(m["dates"]) else None,
                         "dates": len(m["dates"]), "rows": len(df), "version": m["version"],
                         "data_version": m["data_version"], "calendar": m["calendar"],
                         "companies_per_year": {int(y): int(n) for y, n in per_year.items()}})
        return pd.DataFrame(rows)

    # ---- writing (the indicator job only)
    def write(self, key: str, rows: pd.DataFrame, *, version: str, data_version: int, calendar: str, dates,
              data_last_date, info: dict | None = None) -> None:
        """Replace the indicator whole."""
        dates = _ts(dates)
        self._check_dates(key, dates, rows, data_last_date)
        self._save(key, self._clean(rows, dates), {"key": key, "version": version, "data_version": int(data_version),
                                                    "calendar": calendar, "dates": dates, "info": info or {}})

    def update(self, key: str, rows: pd.DataFrame, *, version: str, data_version: int | None, dates, symbols=None,
               data_last_date) -> None:
        """Replace the values on `dates` (for `symbols` only, or every symbol) and add any new dates.

        Refused if the stored version is not `version`: a new version is written whole, never mixed in.
        """
        m = self.meta(key)
        if m["version"] != version:
            raise VersionMismatch(f"{key} is stored at version {m['version']}, not {version}: "
                                  "a new version replaces the indicator whole")
        dates = _ts(dates)
        self._check_dates(key, dates, rows, data_last_date)
        old = self.frame(key)
        hit = old["date"].isin(dates)
        if symbols is not None:
            hit &= old["symbol"].isin(list(symbols))
        new = self._clean(rows, dates)
        if symbols is not None:
            new = new[new["symbol"].isin(list(symbols))]
        all_dates = m["dates"].union(dates)
        out = pd.concat([old[~hit], new], ignore_index=True)
        self._save(key, out, {"key": key, "version": version,
                              "data_version": int(m["data_version"] if data_version is None else data_version),
                              "calendar": m["calendar"], "dates": all_dates, "info": m.get("info", {})})

    def mark(self, key: str, data_version: int) -> None:
        """Say the stored values are those the data at `data_version` gives (the job has applied its changes)."""
        m = self.meta(key)
        m["data_version"] = int(data_version)
        self._save(key, self.frame(key), m)

    # ---- internals
    @staticmethod
    def _check_dates(key, dates, rows, data_last_date):
        last = pd.Timestamp(data_last_date)
        late = dates[dates > last]
        if len(rows):
            late = late.union(_ts(pd.to_datetime(rows["date"])[pd.to_datetime(rows["date"]) > last].unique()))
        if len(late):
            raise StoreError(f"{key}: dates after the data's last date {last.date()} "
                             f"({', '.join(str(d.date()) for d in late[:5])}) are never stored")

    @staticmethod
    def _clean(rows: pd.DataFrame, dates) -> pd.DataFrame:
        if rows is None or len(rows) == 0:
            return pd.DataFrame({"date": pd.Series(dtype="datetime64[ns]"), "symbol": pd.Series(dtype=str),
                                 "value": pd.Series(dtype=float)})
        r = rows[["date", "symbol", "value"]].copy()
        r["date"] = pd.to_datetime(r["date"]).dt.normalize()
        r["value"] = pd.to_numeric(r["value"], errors="coerce").astype(float)
        r = r[r["date"].isin(dates) & np.isfinite(r["value"])]
        return r

    def _save(self, key: str, rows: pd.DataFrame, meta: dict) -> None:
        self.root.mkdir(parents=True, exist_ok=True)
        rows = rows.sort_values(["date", "symbol"]).reset_index(drop=True)
        meta = dict(meta)
        meta["dates"] = [str(pd.Timestamp(d).date()) for d in meta["dates"]]
        table = pa.Table.from_pandas(rows.astype({"symbol": str}), preserve_index=False)
        table = table.replace_schema_metadata({**(table.schema.metadata or {}),
                                               META_KEY: json.dumps(meta, default=str).encode()})
        p = self.path(key)
        tmp = p.with_suffix(".parquet.tmp")
        pq.write_table(table, tmp)
        os.replace(tmp, p)
