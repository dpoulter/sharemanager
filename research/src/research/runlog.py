"""The test log: every run, good or bad, appended and never rewritten.

`log/runs.parquet` is a Parquet dataset (a folder). Each run is written as its
own part file, opened with exclusive create, so an existing run can never be
overwritten; nothing in this package deletes or rewrites a part. Reading the
folder with pandas gives the whole log.

Also here: the freeze. A strategy that has sat its final exam (--final) is
recorded in log/frozen/, and any later run of that strategy name with a
different file hash, or a second --final, is refused.
"""
from __future__ import annotations

import datetime as dt
import json
import subprocess
import uuid
from pathlib import Path

import pandas as pd

from .config import RESEARCH_DIR


class Frozen(Exception):
    pass


def git_commit() -> str:
    try:
        out = subprocess.run(["git", "rev-parse", "--short", "HEAD"], cwd=RESEARCH_DIR,
                             capture_output=True, text=True, timeout=10)
        commit = out.stdout.strip() or "unknown"
        dirty = subprocess.run(["git", "status", "--porcelain", "--", "."], cwd=RESEARCH_DIR,
                               capture_output=True, text=True, timeout=10).stdout.strip()
        return commit + ("+dirty" if dirty else "")
    except Exception:
        return "unknown"


class RunLog:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.path.mkdir(parents=True, exist_ok=True)
        self.frozen_dir = self.path.parent / "frozen"
        self.frozen_dir.mkdir(parents=True, exist_ok=True)

    def new_id(self) -> str:
        return dt.datetime.now().strftime("%Y%m%d-%H%M%S-%f-") + uuid.uuid4().hex[:6]

    def append(self, record: dict) -> Path:
        flat = {}
        for k, v in record.items():
            flat[k] = json.dumps(v, default=str, sort_keys=True) if isinstance(v, (dict, list)) else v
        part = self.path / f"part-{record['run_id']}.parquet"
        with open(part, "xb") as fh:      # exclusive: an existing run is never replaced
            pd.DataFrame([flat]).to_parquet(fh)
        return part

    def read(self) -> pd.DataFrame:
        parts = sorted(self.path.glob("part-*.parquet"))
        if not parts:
            return pd.DataFrame()
        return pd.concat([pd.read_parquet(p) for p in parts], ignore_index=True)

    def trials(self, family: str) -> int:
        df = self.read()
        if df.empty or "family" not in df:
            return 0
        return int((df["family"] == family).sum())

    # ---- the freeze
    def frozen(self, strategy: str) -> dict | None:
        f = self.frozen_dir / f"{strategy}.json"
        return json.loads(f.read_text()) if f.exists() else None

    def check_frozen(self, strategy: str, strategy_hash: str, final: bool) -> None:
        fz = self.frozen(strategy)
        if fz is None:
            return
        if final:
            raise Frozen(f"{strategy} already sat its final exam in run {fz['run_id']} on {fz['at']}; "
                         "there is only one.")
        if fz["strategy_hash"] != strategy_hash:
            raise Frozen(f"{strategy} was frozen by its final run {fz['run_id']}; its file has changed "
                         "since. Give a changed strategy a new name: it is a new trial.")

    def freeze(self, strategy: str, strategy_hash: str, run_id: str) -> None:
        f = self.frozen_dir / f"{strategy}.json"
        with open(f, "x") as fh:
            json.dump({"strategy": strategy, "strategy_hash": strategy_hash, "run_id": run_id,
                       "at": dt.datetime.now().isoformat(timespec="seconds")}, fh)
