"""A small FMP stable-API client: throttled, retries 429 and 5xx, masks the key.

Safe to call from several threads at once (fetch.py runs a small pool). The
limit is kept across all of them: calls start no closer together than
60 / calls_per_minute seconds, and never more than calls_per_minute in any 60
seconds. A 429 makes every thread wait, not only the one that got it.
"""
from __future__ import annotations

import csv
import io
import json
import logging
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque

from .config import Config, api_key, mask

log = logging.getLogger(__name__)


class FmpError(Exception):
    pass


class FmpRestricted(FmpError):
    """FMP refused the endpoint as not on the plan (HTTP 402, "Restricted Endpoint"). Not a failure of
    one symbol and never fallen back from: the top-up stops and names the endpoint (fetch.py)."""

    def __init__(self, endpoint: str, detail: str):
        super().__init__(f"FMP refused `{endpoint}` as not on the plan (HTTP 402): {detail}")
        self.endpoint = endpoint


def _urlopen(url: str):
    with urllib.request.urlopen(url, timeout=90) as r:
        return r.headers, r.read().decode("utf-8", "replace")


def parse(body: str):
    """JSON, or (the bulk endpoints) CSV as a list of dicts. None for an empty body."""
    text = body.strip()
    if not text:
        return None
    if text[0] in "[{":
        return json.loads(text)
    return list(csv.DictReader(io.StringIO(text.lstrip("﻿"))))


class Fmp:
    def __init__(self, cfg: Config, opener=None, clock=time.monotonic, sleep=time.sleep):
        self.base = cfg["fmp"]["base_url"].rstrip("/")
        self.per_minute = int(cfg["fmp"]["calls_per_minute"])
        self.max_retries = int(cfg["fmp"]["max_retries"])
        # How many calls may be in flight at once. Each takes about a second, so the
        # configured calls a minute needs about per_minute / 60 of them; the throttle caps the rate.
        self.concurrency = max(1, int(cfg["fmp"].get("concurrency", 16)))
        self.key = api_key(cfg)
        self._open, self._clock, self._sleep = opener or _urlopen, clock, sleep
        self._lock = threading.Lock()
        self._stamps: deque[float] = deque()
        self._next_at = 0.0
        self._pause_until = 0.0
        self.calls = 0
        self.throttled = 0
        self._started = clock()

    def _throttle(self) -> None:
        """Wait for a slot, then take it."""
        while True:
            with self._lock:
                now = self._clock()
                while self._stamps and now - self._stamps[0] >= 60:
                    self._stamps.popleft()
                wait = max(self._pause_until - now, self._next_at - now)
                if len(self._stamps) >= self.per_minute:
                    wait = max(wait, 60 - (now - self._stamps[0]) + 0.05)
                if wait <= 0:
                    self._stamps.append(now)
                    self._next_at = now + 60.0 / self.per_minute
                    self.calls += 1
                    if self.calls % 300 == 0:
                        rate = self.calls / max(1e-9, (now - self._started) / 60)
                        log.info("FMP: %d calls (%.0f a minute), %d 429s", self.calls, rate, self.throttled)
                    return
            self._sleep(wait)

    def _back_off(self, seconds: float) -> None:
        with self._lock:
            self._pause_until = max(self._pause_until, self._clock() + seconds)

    def get(self, path: str, **params):
        """Parsed JSON (or CSV rows), or None for 404 / empty. Raises FmpError otherwise."""
        params = {k: v for k, v in params.items() if v is not None}
        params["apikey"] = self.key
        url = f"{self.base}/{path}?{urllib.parse.urlencode(params)}"
        delay = 2.0
        for attempt in range(self.max_retries + 1):
            self._throttle()
            try:
                headers, body = self._open(url)
                # Honour a lower limit if FMP announces one.
                limit = headers.get("X-RateLimit-Limit") if headers is not None else None
                if limit and str(limit).isdigit() and 0 < int(limit) < self.per_minute:
                    self.per_minute = int(limit)
                try:
                    data = parse(body)
                except ValueError:
                    raise FmpError(mask(f"unreadable answer on {path}: {body[:200]}")) from None
                if isinstance(data, dict) and "Error Message" in data:
                    raise FmpError(mask(data["Error Message"]))
                return data
            except urllib.error.HTTPError as e:
                if e.code == 404:
                    return None
                if e.code == 429:
                    with self._lock:
                        self.throttled += 1
                if e.code in (429, 500, 502, 503, 504) and attempt < self.max_retries:
                    wait = float(e.headers.get("Retry-After") or delay)
                    log.warning("FMP %s on %s, waiting %.0fs", e.code, path, wait)
                    if e.code == 429:
                        self._back_off(wait)          # every thread waits, then this one tries again
                    else:
                        self._sleep(wait)
                    delay = min(delay * 2, 120)
                    continue
                body = e.read().decode("utf-8", "replace")[:300] if e.fp is not None else ""
                # A 402 for the endpoint itself; a 402 about one symbol's value stays that symbol's failure.
                if e.code == 402 and ("restricted endpoint" in body.lower() or "symbol" not in params):
                    raise FmpRestricted(path, mask(body)) from None
                raise FmpError(mask(f"HTTP {e.code} on {path}: {body}")) from None
            except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
                if attempt < self.max_retries:
                    self._sleep(delay)
                    delay = min(delay * 2, 120)
                    continue
                raise FmpError(mask(f"network error on {path}: {e}")) from None
        raise FmpError(f"gave up on {path}")
