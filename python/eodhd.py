"""
Minimal EODHD API client.

Covers the two endpoints this application needs: fundamentals (the ratios that
feed screen_indicators) and end of day prices. The API key comes from the
EODHD_API_KEY environment variable so it is never committed; see README.

EODHD addresses London tickers as CODE.LSE. stock_symbols.exchange holds the MIC
(XLON) that the rest of the application keys on, so the two are not
interchangeable and eodhd_symbol() does the translation in one place.
"""

import os
import requests

BASE_URL = "https://eodhd.com/api"
EXCHANGE_SUFFIX = "LSE"

# Fundamentals sections worth flattening into stock_info. Financials is
# deliberately excluded: it is deeply nested per reporting period and belongs in
# the financial_statement_* tables, not in the flat attribute/value store.
SCALAR_SECTIONS = ("General", "Highlights", "Valuation", "SharesStats",
                   "Technicals", "SplitsDividends")


class EodhdError(Exception):
    pass


def api_key():
    key = os.environ.get("EODHD_API_KEY", "").strip()
    if not key:
        raise EodhdError(
            "EODHD_API_KEY is not set. Export it in the environment the cron "
            "jobs run under; do not hard code it.")
    return key


def eodhd_symbol(symbol, suffix=EXCHANGE_SUFFIX):
    """stock_symbols.symbol -> the ticker EODHD expects (VOD -> VOD.LSE)."""
    return symbol if "." in symbol else "{}.{}".format(symbol, suffix)


def _get(path, session=None, **params):
    params["api_token"] = api_key()
    params.setdefault("fmt", "json")
    getter = session.get if session is not None else requests.get
    response = getter("{}/{}".format(BASE_URL, path), params=params, timeout=60)

    if response.status_code == 401:
        raise EodhdError("EODHD rejected the API key (401)")
    if response.status_code == 402:
        raise EodhdError("this endpoint is not included in the current EODHD plan (402)")
    if response.status_code == 404:
        return None          # unknown ticker, treated as "no data" by callers
    if response.status_code == 429:
        raise EodhdError("EODHD daily call limit reached (429)")
    response.raise_for_status()

    return response.json()


def get_fundamentals(symbol, session=None):
    """Full fundamentals document for one symbol, or None if not covered."""
    return _get("fundamentals/{}".format(eodhd_symbol(symbol)), session=session)


def get_eod(symbol, start_date, end_date, session=None):
    """Daily prices for one symbol between two Y-m-d dates."""
    rows = _get("eod/{}".format(eodhd_symbol(symbol)), session=session,
                **{"from": start_date, "to": end_date, "period": "d", "order": "a"})
    return rows or []


def flatten_fundamentals(document, sections=SCALAR_SECTIONS):
    """
    Reduce a fundamentals document to {"Section.Field": value} pairs.

    Every scalar field in the selected sections is kept, not just the ones
    currently mapped to an indicator. That is deliberate: it mirrors what the
    yfinance loader did, means a new indicator needs only a screen_indicators
    row rather than a code change, and lets you see the real field names in
    stock_info before deciding what to map.

    Keys are capped at 50 characters to match stock_info.attribute; anything
    longer is skipped rather than silently truncated into a colliding key.
    """
    flat = {}
    if not isinstance(document, dict):
        return flat

    for section in sections:
        values = document.get(section)
        if not isinstance(values, dict):
            continue
        for field, value in values.items():
            if isinstance(value, (dict, list)):
                continue        # nested structures do not belong in the EAV store
            if value is None or value == "":
                continue
            attribute = "{}.{}".format(section, field)
            if len(attribute) > 50:
                continue

            # stock_info.value is varchar(255). General.Description in
            # particular runs to hundreds of characters, so an unbounded value
            # fails the insert under strict mode and truncates silently
            # otherwise. Only long values are touched, so numbers keep their
            # own type rather than all becoming strings.
            if isinstance(value, str) and len(value) > 255:
                value = value[:252] + "..."

            flat[attribute] = value

    return flat
