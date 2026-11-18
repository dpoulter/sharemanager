"""A small FMP stable-API client: throttled, retries 429 and 5xx, masks the key."""
from __future__ import annotations

import json
import logging
import time
import urllib.error
import urllib.parse
import urllib.request

from .config import Config, api_key, mask

log = logging.getLogger(__name__)


class FmpError(Exception):
    pass


class Fmp:
    def __init__(self, cfg: Config):
        self.base = cfg["fmp"]["base_url"].rstrip("/")
        self.per_minute = int(cfg["fmp"]["calls_per_minute"])
        self.max_retries = int(cfg["fmp"]["max_retries"])
        self.key = api_key(cfg)
        self._stamps: list[float] = []
        self.calls = 0

    def _throttle(self) -> None:
        now = time.monotonic()
        self._stamps = [t for t in self._stamps if now - t < 60]
        if len(self._stamps) >= self.per_minute:
            time.sleep(60 - (now - self._stamps[0]) + 0.05)
        self._stamps.append(time.monotonic())

    def get(self, path: str, **params):
        """Parsed JSON, or None for 404 / empty. Raises FmpError otherwise."""
        params = {k: v for k, v in params.items() if v is not None}
        params["apikey"] = self.key
        url = f"{self.base}/{path}?{urllib.parse.urlencode(params)}"
        delay = 2.0
        for attempt in range(self.max_retries + 1):
            self._throttle()
            self.calls += 1
            try:
                with urllib.request.urlopen(url, timeout=90) as r:
                    # Honour a lower limit if FMP announces one.
                    limit = r.headers.get("X-RateLimit-Limit")
                    if limit and limit.isdigit() and 0 < int(limit) < self.per_minute:
                        self.per_minute = int(limit)
                    body = r.read().decode("utf-8", "replace")
                data = json.loads(body) if body.strip() else None
                if isinstance(data, dict) and "Error Message" in data:
                    raise FmpError(mask(data["Error Message"]))
                return data
            except urllib.error.HTTPError as e:
                if e.code == 404:
                    return None
                if e.code in (429, 500, 502, 503, 504) and attempt < self.max_retries:
                    wait = float(e.headers.get("Retry-After") or delay)
                    log.warning("FMP %s on %s, waiting %.0fs", e.code, path, wait)
                    time.sleep(wait)
                    delay = min(delay * 2, 120)
                    continue
                body = e.read().decode("utf-8", "replace")[:300]
                raise FmpError(mask(f"HTTP {e.code} on {path}: {body}")) from None
            except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
                if attempt < self.max_retries:
                    time.sleep(delay)
                    delay = min(delay * 2, 120)
                    continue
                raise FmpError(mask(f"network error on {path}: {e}")) from None
        raise FmpError(f"gave up on {path}")
