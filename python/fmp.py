"""
Minimal Financial Modeling Prep API client.

Covers what this application needs: fundamentals (the ratios that feed
screen_indicators), end of day prices and the live quote. The API key comes
from the FMP_API_KEY environment variable so it is never committed.

Everything here targets FMP's "stable" API. The older /api/v3/ endpoints
answer 403 "Legacy Endpoint ... only available for legacy users" for accounts
opened after the change, so they are not a fallback and are not attempted.

FMP addresses London tickers as CODE.L (VOD.L). stock_symbols.exchange holds
the MIC (XLON) that the rest of the application keys on, and EODHD used a third
spelling (CODE.LSE), so none of the three are interchangeable: fmp_symbol()
does the translation in one place.

Units: FMP reports LSE prices in GBp (pence) and market capitalisation in GBP
(pounds). Confirmed against VOD.L, whose price came back as 116.95 alongside a
market cap of 27,095,677,700 - pence would make that a 271 million pound
company. Mixing the two is a hundredfold error, so market cap is converted to
the price's own unit on the way in; see normalise_market_cap().
"""

import json
import os
from urllib import request as urlrequest, parse as urlparse, error as urlerror

BASE_URL = "https://financialmodelingprep.com/stable"
EXCHANGE_SUFFIX = "L"

# Currencies quoted in minor units (pence) while balance-sheet figures from the
# same provider are in major units (pounds).
MINOR_UNIT_CURRENCIES = {"GBp": 100, "GBX": 100, "ZAc": 100, "ILA": 100}


class FmpError(Exception):
    pass


def api_key():
    key = os.environ.get("FMP_API_KEY", "").strip()
    if not key:
        raise FmpError(
            "FMP_API_KEY is not set. Export it in the environment the cron "
            "jobs run under; do not hard code it.")
    return key


def fmp_symbol(symbol, suffix=EXCHANGE_SUFFIX):
    """stock_symbols.symbol -> the ticker FMP expects (VOD -> VOD.L)."""
    return symbol if "." in symbol else "{}.{}".format(symbol, suffix)


def _get(path, session=None, **params):
    """One GET against the stable API. Returns parsed JSON, or None for 404.

    Deliberately stdlib only. The jobs run from cron on a plain server, and
    requiring requests meant one more thing to install and to keep installed;
    urllib is enough for a GET returning JSON. `session` is accepted and
    ignored, so callers written against the EODHD client still work.
    """
    params["apikey"] = api_key()
    url = "{}/{}?{}".format(BASE_URL, path, urlparse.urlencode(params))
    try:
        with urlrequest.urlopen(url, timeout=60) as response:
            return json.loads(response.read().decode("utf-8", "replace"))
    except urlerror.HTTPError as e:
        body = e.read().decode("utf-8", "replace")[:200]
        if e.code == 401:
            raise FmpError("FMP rejected the API key (401)")
        if e.code == 403:
            # The legacy-endpoint refusal arrives as 403 naming itself, and
            # means the path is wrong rather than the plan being short.
            if "Legacy Endpoint" in body:
                raise FmpError("this is a legacy /api/v3 path; the stable API "
                               "is the only one these accounts may use: " + body)
            raise FmpError("FMP refused the request (403): " + body)
        if e.code == 404:
            return None      # unknown ticker, treated as "no data" by callers
        if e.code == 429:
            raise FmpError("FMP rate limit reached (429)")
        raise FmpError("FMP returned {}: {}".format(e.code, body))
    except ValueError as e:
        raise FmpError("FMP returned a body that is not JSON: {}".format(e))


def _first(body):
    """The stable API answers with a list even for a single symbol."""
    if isinstance(body, list):
        return body[0] if body else None
    return body if isinstance(body, dict) else None


def normalise_market_cap(market_cap, currency):
    """Market cap in the same unit the price is quoted in.

    FMP gives LSE prices in pence and market cap in pounds. Everything in this
    application that pairs the two - the quote page's Mkt cap beside Price, and
    any ratio derived from them - needs one unit, and price is the one the rest
    of the database already uses.
    """
    if market_cap is None:
        return None
    factor = MINOR_UNIT_CURRENCIES.get(currency)
    return market_cap * factor if factor else market_cap


def shares_outstanding(profile, quote):
    """Shares in issue, derived, because no stable endpoint here reports it.

    quote and profile both carry marketCap and price. Once the two are in the
    same unit their ratio is the share count. Derived rather than read, so it
    is approximate and moves with the price; it is good to about three
    significant figures, which is all the quote page displays.
    """
    cap = (profile or {}).get("marketCap") or (quote or {}).get("marketCap")
    price = (quote or {}).get("price") or (profile or {}).get("price")
    currency = (profile or {}).get("currency")
    if not cap or not price:
        return None
    cap = normalise_market_cap(cap, currency)
    try:
        return round(cap / price)
    except ZeroDivisionError:
        return None


# stock_info.attribute is widened to varchar(100) by sql/fmp_migration.sql.
# The longest name FMP returns is 52 characters
# (KeyMetrics.salesGeneralAndAdministrativeToRevenueTTM), which the old
# varchar(50) could not hold; the guard stays as a backstop against a provider
# adding something longer still.
MAX_ATTRIBUTE = 100
MAX_VALUE = 255

# Which endpoint each section comes from. The section name is the application's
# own prefix, not something FMP sends, so stock_info says where a number came
# from and two providers can coexist in one table.
SECTIONS = (
    ("Quote",      "quote"),
    ("Profile",    "profile"),
    ("KeyMetrics", "key-metrics-ttm"),
    ("Ratios",     "ratios-ttm"),
)


def fetch_sections(symbol, session=None):
    """Fetch every section for one symbol. Returns {section: record}."""
    code = fmp_symbol(symbol)
    out = {}
    for section, path in SECTIONS:
        out[section] = _first(_get(path, session=session, symbol=code)) or {}
    return out


def flatten(sections):
    """{section: record} -> {"Section.field": value} ready for stock_info.

    Nulls and empty strings are dropped rather than stored, so a missing figure
    stays missing instead of becoming the string "None". Nested values are
    skipped: this table is flat, and anything structured belongs elsewhere.
    """
    flat = {}
    for section, record in sections.items():
        if not isinstance(record, dict):
            continue
        for field, value in record.items():
            if value is None or value == "":
                continue
            if isinstance(value, (dict, list)):
                continue
            attribute = "{}.{}".format(section, field)
            if len(attribute) > MAX_ATTRIBUTE:
                continue
            if isinstance(value, str) and len(value) > MAX_VALUE:
                value = value[:MAX_VALUE - 3] + "..."
            flat[attribute] = value

    # Market cap in the price's own unit, and the derived share count. Both are
    # written under Derived so nothing reads them back as provider figures.
    profile = sections.get("Profile") or {}
    quote = sections.get("Quote") or {}
    currency = profile.get("currency")
    cap = normalise_market_cap(profile.get("marketCap"), currency)
    if cap is not None:
        flat["Derived.marketCapInQuoteCurrency"] = cap
    shares = shares_outstanding(profile, quote)
    if shares:
        flat["Derived.sharesOutstanding"] = shares

    return flat
