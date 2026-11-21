"""The backtest_worker module (contracts/modules.yaml): runs sharemanager's
backtests on this engine, one at a time.

    uv run research worker            poll until stopped (launchd keeps it running)
    uv run research worker --once     claim and run at most one, then stop

It talks to sharemanager only through backtest_api (public/backtest_api.php):
claim the next run, post a result, post a failure. No SSH, no database, no
login on the N100.

Settings come from a file of KEY=value lines, mode 600 (default
~/.config/sharemanager-research/worker.env, or RESEARCH_WORKER_SETTINGS):

    SM_BACKTEST_API_URL=http://<n100 tailscale name>/backtest_api.php
    SM_BACKTEST_API_TOKEN=...
    SM_BACKTEST_POLL_SECONDS=60        optional

The token is never logged: it is registered with config.mask() before any
log line is written, and every line goes through mask().

The worker does not interpret rules: from_rules.translate() turns the claim
into the engine's strategy or refuses, naming the rule, and the refusal is
posted as the run's failure. Every run goes through Workspace.run() with
kind "research", so it is written to the test log as a hand-started run is,
counts as a trial of its family, and is refused by periods.check_holdout() if
it reaches the holdout.

Indicators come only from the indicator store (indicator_store.py). The worker
claims nothing while the indicator job runs (data/indicators/.job.lock), and a
run it has claimed waits for a job that started in the meantime; the job in
turn does not start while the worker holds a run (its state file).

It refuses to start unless the run 9 replay check (research validate
--replay-targets) passed at the current, clean git commit.

A run in progress is written to a state file first. If the worker stops - a
restart, a crash, launchd stopping it - the run is posted as failed: at once
when it is stopped with a signal, else the next time it starts. It is never run
again silently.
"""
from __future__ import annotations

import base64
import json
import logging
import math
import os
import signal as sig
import stat
import time
import urllib.error
import urllib.request
from pathlib import Path

import pandas as pd

from . import config as config_mod
from .config import mask

log = logging.getLogger("research.worker")

DEFAULT_SETTINGS = Path("~/.config/sharemanager-research/worker.env")
RESULT_FIELDS = ["trading_days", "fills", "start_value", "final_value", "total_return", "annual_return",
                 "bench_equal_weight_return", "bench_cap_weight_return", "max_drawdown", "costs_paid",
                 "sharpe", "deflated_sharpe", "trials", "dividends_received", "dsr", "deflation_meaningful",
                 "cap_weight_unchecked_caps"]


class WorkerError(RuntimeError):
    pass


class Stopped(BaseException):
    """Raised inside a run when the worker is told to stop."""


# ---- settings

def read_settings(path: Path | None = None) -> dict:
    path = Path(path or os.environ.get("RESEARCH_WORKER_SETTINGS") or DEFAULT_SETTINGS).expanduser()
    if not path.exists():
        raise WorkerError(f"no settings file at {path}")
    mode = stat.S_IMODE(path.stat().st_mode)
    if mode & 0o077:
        raise WorkerError(f"{path} is mode {mode:o}; it holds the token, so it has to be 600")
    out = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip().removeprefix("export ").strip()] = v.strip().strip('"').strip("'")
    token = out.get("SM_BACKTEST_API_TOKEN", "")
    if token:
        config_mod._SECRETS.append(token)
    for k in ("SM_BACKTEST_API_URL", "SM_BACKTEST_API_TOKEN"):
        if not out.get(k):
            raise WorkerError(f"{path} has no {k}")
    return out


# ---- the door

class Api:
    """backtest_api: claim, result, failure. Nothing else."""

    def __init__(self, url: str, token: str, timeout: float = 60.0):
        self.url, self._token, self.timeout = url, token, timeout
        config_mod._SECRETS.append(token)

    def call(self, payload: dict) -> dict:
        req = urllib.request.Request(self.url, data=json.dumps(payload, allow_nan=False).encode(), method="POST",
                                     headers={"Content-Type": "application/json",
                                              "Authorization": f"Bearer {self._token}"})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            try:
                body = json.loads(e.read().decode())
            except Exception:
                body = {"ok": False, "why": f"HTTP {e.code}"}
            body.setdefault("ok", False)
            return body

    def claim(self) -> dict | None:
        out = self.call({"action": "claim"})
        if not out.get("ok"):
            raise WorkerError(f"the claim was refused: {out.get('why')}")
        return out.get("run")

    def result(self, run_id: int, fingerprint: str, result: dict, report: bytes) -> dict:
        return self.call({"action": "result", "run_id": run_id, "fingerprint": fingerprint, "result": result,
                          "report_base64": base64.b64encode(report).decode()})

    def failure(self, run_id: int, fingerprint: str, why: str) -> dict:
        return self.call({"action": "failure", "run_id": run_id, "fingerprint": fingerprint, "why": why[:900]})


# ---- the run 9 gate

def replay_check(cfg, commit: str | None = None) -> str | None:
    """None when the run 9 replay check passed at the current clean commit, else why not."""
    from .runlog import RunLog, git_commit
    commit = commit or git_commit()
    if commit == "unknown" or commit.endswith("+dirty"):
        return f"the research folder is at {commit}; the replay check counts only at a clean commit"
    path = cfg.data_dir / "validation" / "run9_replay_check.json"
    if not path.exists():
        return f"no run 9 replay check ({path}); run `research validate --replay-targets`"
    rep = json.loads(path.read_text())
    if not rep.get("replay_targets"):
        return "the last run 9 check was not the replay check; run `research validate --replay-targets`"
    if not rep.get("passed"):
        return "the run 9 replay check did not pass"
    if rep.get("git_commit") != commit:
        return f"the run 9 replay check passed at {rep.get('git_commit')}, not at the current commit {commit}"
    logged = RunLog(cfg.p("run_log")).read()
    if logged.empty or "run_id" not in logged or not (logged["run_id"] == rep.get("run_id")).any():
        return "the run 9 replay check is not in the test log"
    return None


# ---- one run

def _clean(v):
    if isinstance(v, float) and not math.isfinite(v):
        return None
    if isinstance(v, (pd.Timestamp,)):
        return str(v)
    if hasattr(v, "item"):
        return _clean(v.item())
    if isinstance(v, dict):
        return {k: _clean(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [_clean(x) for x in v]
    return v


def result_payload(record: dict, rebalances: int) -> dict:
    """What is posted: the record's figures, and the engine's rebalance count (the decision days it
    scored, one row each in Workspace.run's coverage table)."""
    out = {k: _clean(record.get(k)) for k in RESULT_FIELDS}
    out["rebalances"] = int(rebalances)
    if record.get("dividends") != "credited as cash":
        out["dividends_received"] = None      # none credited: the page says so rather than showing 0
    if not record.get("deflation_meaningful"):
        # Too few distinct trials: no number that looks deflated is posted.
        out["dsr"] = out["deflated_sharpe"] = None
    out["research_run_id"] = str(record["run_id"])
    out["engine_commit"] = str(record.get("git_commit") or "unknown")[:40]
    out["record"] = _clean({k: v for k, v in record.items()})
    return out


class Worker:
    def __init__(self, cfg, api, workspace_factory=None, state_path: Path | None = None,
                 poll_seconds: float = 60.0, gate=replay_check):
        self.cfg, self.api = cfg, api
        self._factory = workspace_factory
        self._ws = None
        self.state_path = Path(state_path or cfg.p("logs") / "worker_current_run.json")
        self.poll_seconds = poll_seconds
        self.gate = gate
        self.stop = False
        self.job_wait_seconds = 10.0

    # state
    def _write_state(self, claim: dict) -> None:
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        self.state_path.write_text(json.dumps({"run_id": claim["run_id"], "fingerprint": claim["fingerprint"],
                                               "started": time.strftime("%Y-%m-%dT%H:%M:%S")}))

    def _clear_state(self) -> None:
        self.state_path.unlink(missing_ok=True)

    def recover(self) -> int | None:
        """A run left in the state file was interrupted: post it as failed, never run it again."""
        if not self.state_path.exists():
            return None
        st = json.loads(self.state_path.read_text())
        why = (f"interrupted: the worker stopped during the run (started {st.get('started')}) "
               "and does not run it again; queue it again")
        out = self.api.failure(int(st["run_id"]), str(st["fingerprint"]), why)
        log.info("run %s was interrupted; posted as failed (%s)", st["run_id"], out.get("why") or "accepted")
        self._clear_state()
        return int(st["run_id"])

    def workspace(self):
        if self._ws is None:
            if self._factory is None:
                from .indicator_job import workspace
                self._ws = workspace(self.cfg)
            else:
                self._ws = self._factory(self.cfg)
        return self._ws

    def run_claim(self, claim: dict) -> dict:
        """Translate and run one claimed run. Returns the engine's result dict."""
        from .from_rules import translate
        from .indicator_job import job_running
        from .strategy import spec_from_dict
        holdout = pd.Timestamp(self.cfg["periods"]["holdout_start"])
        if pd.Timestamp(claim["to"]) >= holdout:
            raise WorkerError(f"the run ends on {claim['to']}, inside the holdout (from {holdout.date()})")
        while job_running(self.cfg):        # the job started between the check and the claim
            log.info("run %s waits for the indicator job", claim.get("run_id"))
            time.sleep(self.job_wait_seconds)
        ws = self.workspace()
        spec = spec_from_dict(translate(claim, ws.store))
        return ws.run(spec, period=f"{claim['from']}:{claim['to']}", kind="research",
                                    start=claim["from"], end=claim["to"])

    def run_once(self) -> bool:
        """Claim and run at most one. False when nothing was queued (or the indicator job is running)."""
        from .indicator_job import job_running
        if job_running(self.cfg):
            log.info("the indicator job is running; nothing claimed")
            return False
        claim = self.api.claim()
        if not claim:
            return False
        run_id, fp = int(claim["run_id"]), str(claim["fingerprint"])
        self._write_state(claim)
        log.info("claimed run %s (%s, %s to %s)", run_id, claim.get("strategy"), claim.get("from"), claim.get("to"))
        try:
            out = self.run_claim(claim)
            report = Path(out["report"]).read_bytes()
            ans = self.api.result(run_id, fp, result_payload(out["record"], len(out["coverage"])), report)
            if ans.get("ok"):
                log.info("run %s done: engine run %s", run_id, out["record"]["run_id"])
            else:
                log.error("run %s: the result was refused: %s", run_id, ans.get("why"))
                self.api.failure(run_id, fp, f"the result was refused: {ans.get('why')}")
        except Stopped:
            self.api.failure(run_id, fp, "interrupted: the worker was stopped during the run; queue it again")
            self._clear_state()
            raise
        except Exception as e:      # a failure is posted and does not stop the next run
            why = mask(f"{type(e).__name__}: {e}")
            log.error("run %s failed: %s", run_id, why)
            self.api.failure(run_id, fp, why)
        self._clear_state()
        return True

    def loop(self, once: bool = False) -> None:
        self.recover()
        why = self.gate(self.cfg)
        if why:
            raise WorkerError(f"refusing to start: {why}")

        def on_signal(signum, frame):
            self.stop = True
            raise Stopped()
        for s in (sig.SIGTERM, sig.SIGINT):
            sig.signal(s, on_signal)
        try:
            while not self.stop:
                try:
                    worked = self.run_once()
                except WorkerError as e:
                    log.error("%s", mask(e))
                    worked = False
                except (urllib.error.URLError, OSError) as e:
                    log.error("cannot reach backtest_api: %s", mask(e))
                    worked = False
                if once:
                    return
                if not worked:
                    time.sleep(self.poll_seconds)
        except Stopped:
            log.info("stopped")


def main(cfg, once: bool = False) -> None:
    s = read_settings()
    api = Api(s["SM_BACKTEST_API_URL"], s["SM_BACKTEST_API_TOKEN"])
    Worker(cfg, api, poll_seconds=float(s.get("SM_BACKTEST_POLL_SECONDS") or 60)).loop(once=once)
