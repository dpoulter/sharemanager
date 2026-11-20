"""Settings, paths and the API key.

The key is read from a file Dale writes (mode 600) and never logged. Anything
that may end up in a log or an exception goes through mask() first.
"""
from __future__ import annotations

import hashlib
import os
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

RESEARCH_DIR = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG = RESEARCH_DIR / "config.yaml"

_SECRETS: list[str] = []


def mask(text: str) -> str:
    """Remove any known secret, and anything that looks like apikey=..., from text."""
    text = str(text)
    for secret in _SECRETS:
        if secret:
            text = text.replace(secret, "***")
    return re.sub(r"(apikey=)[^&\s]+", r"\1***", text, flags=re.I)


def file_hash(path: Path) -> str:
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()[:16]


@dataclass
class Config:
    raw: dict
    path: Path
    hash: str = field(default="")

    def __getitem__(self, key):
        return self.raw[key]

    def get(self, key, default=None):
        return self.raw.get(key, default)

    def p(self, name: str) -> Path:
        value = Path(os.path.expanduser(self.raw["paths"][name]))
        return value if value.is_absolute() else RESEARCH_DIR / value

    @property
    def data_dir(self) -> Path:
        return self.p("data")


def load_config(path: str | Path | None = None, overrides: dict | None = None) -> Config:
    path = Path(path or os.environ.get("RESEARCH_CONFIG") or DEFAULT_CONFIG)
    raw = yaml.safe_load(path.read_text())
    if overrides:
        _merge(raw, overrides)
    return Config(raw=raw, path=path, hash=file_hash(path))


def _merge(base: dict, extra: dict) -> None:
    for k, v in extra.items():
        if isinstance(v, dict) and isinstance(base.get(k), dict):
            _merge(base[k], v)
        else:
            base[k] = v


def api_key(cfg: Config) -> str:
    """FMP_API_KEY from the environment, else from the env file."""
    key = os.environ.get("FMP_API_KEY", "").strip()
    if not key:
        env_file = cfg.p("env_file")
        if env_file.exists():
            for line in env_file.read_text().splitlines():
                line = line.strip()
                if line.startswith("FMP_API_KEY="):
                    key = line.split("=", 1)[1].strip().strip('"').strip("'")
    if not key:
        raise RuntimeError(f"FMP_API_KEY not found in the environment or {cfg.p('env_file')}")
    _SECRETS.append(key)
    return key
