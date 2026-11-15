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

import datetime
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


def is_fund_flag(profile):
    """'Y', 'N' or None from Profile.isFund.

    fund_sectors catches a fund whose sector says so, and most do, but not
    all: FGT files under "Equity Investments", VEIL and VOF under "General
    Financial" and SEQI under "Financial Services". FMP flags all four, 92 of
    the 350 in all. None means the profile did not say, which is left alone
    rather than guessed at.
    """
    if not isinstance(profile, dict) or "isFund" not in profile:
        return None

    flag = profile.get("isFund")

    if isinstance(flag, bool):
        return 'Y' if flag else 'N'
    if isinstance(flag, (int, float)):
        return 'Y' if flag else 'N'
    if isinstance(flag, str):
        text = flag.strip().lower()
        if text in ("true", "yes", "y", "1"):
            return 'Y'
        if text in ("false", "no", "n", "0"):
            return 'N'
    return None


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


# The currency the price is quoted in has nothing to do with the currency the
# accounts are kept in. FMP's ratios-ttm and key-metrics-ttm divide the one by
# the other without converting, so a dollar reporter's P/E came back smaller
# than it is by the whole exchange rate: SHEL at 8.43 where it is about 11.1.
FX_BASE = "GBP"


def fetch_reported_currency(symbol, session=None):
    """The currency a company keeps its accounts in, or None.

    One income statement is enough - the field is the same on every period -
    and the loader only asks when it has no answer stored or a stale one, so
    this is not a call per symbol per night.
    """
    record = _first(_get("income-statement", session=session,
                         symbol=fmp_symbol(symbol), limit=1))
    currency = (record or {}).get("reportedCurrency")
    currency = str(currency).strip().upper() if currency else ""
    return currency if len(currency) == 3 and currency.isalpha() else None


def fetch_fx_pair(pair, session=None):
    """The price of one currency pair, e.g. GBPUSD, or None.

    FMP answers an unquoted pair with an empty list rather than an error, so
    "no such pair" and "nothing to say about it" arrive the same way and both
    mean the caller has to find the rate another way.
    """
    record = _first(_get("quote", session=session, symbol=pair))
    price = (record or {}).get("price")
    try:
        price = float(price)
    except (TypeError, ValueError):
        return None
    return price if price > 0 else None


def resolve_fx_rate(quote_ccy, get_pair, base=FX_BASE):
    """(rate, how) for base -> quote_ccy, or (None, reason).

    Quoted directly where the plan quotes the pair, and crossed through the
    dollar where it does not: GBPGEL returns [] but GBPUSD and USDGEL are both
    quoted, so a pound buys GBPUSD x USDGEL lari.

    get_pair is passed in rather than called directly so the arithmetic can be
    tested without an API key or a network.
    """
    quote_ccy = str(quote_ccy or "").strip().upper()

    if not quote_ccy:
        return None, "no currency"
    if quote_ccy == base:
        return 1.0, "same currency"

    direct = get_pair(base + quote_ccy)
    if direct:
        return direct, "quote"

    if quote_ccy == "USD":
        return None, "no " + base + "USD rate"

    base_usd = get_pair(base + "USD")
    usd_quote = get_pair("USD" + quote_ccy)
    if base_usd and usd_quote:
        return base_usd * usd_quote, "cross:USD"

    return None, "no " + base + quote_ccy + " rate, and no USD cross"


# Quarters of cash flow summed for the trailing twelve month buyback figure.
CASH_FLOW_QUARTERS = 4


def fetch_sections(symbol, session=None):
    """Fetch every section for one symbol. Returns {section: record}.

    CashFlowQuarters is a list rather than a record, which flatten()'s field
    loop skips; it is there for the buyback yield alone. One more call per
    symbol - about 350 a night on this universe - rather than a cache with a
    staleness rule, because the figure moves every quarter and the saving
    would be a few hundred calls against a 600 a minute allowance.
    """
    code = fmp_symbol(symbol)
    out = {}
    for section, path in SECTIONS:
        out[section] = _first(_get(path, session=session, symbol=code)) or {}
    out["CashFlowQuarters"] = _get("cash-flow-statement", session=session,
                                   symbol=code, period="quarter",
                                   limit=CASH_FLOW_QUARTERS) or []

    # Piotroski and Altman, worked out by the provider from statements it
    # already holds. This application used to compute both from
    # financial_statement_values, which is empty on the live database, so the
    # scores were never written and the quote page's health card has been
    # commented out for as long as anyone can remember. RR comes back as 7
    # and 3.09.
    out["Scores"] = _first(_get("financial-scores", session=session,
                                symbol=code)) or {}

    # Revenue, EPS and EBIT growth. earnings_growth exists as an indicator and
    # has never been filled: the local calculation needs next year's and this
    # year's EPS estimates, which no loader writes.
    out["Growth"] = _first(_get("financial-growth", session=session,
                                symbol=code, limit=1)) or {}

    return out


# Above this, the figure is not a buyback yield, it is a mistake. A company
# handing back a quarter of its market capitalisation in a year is possible
# and rare; a run of them means the periods being summed do not add up to a
# year. Nothing is written over the cap, because a wrong yield ranks.
BUYBACK_YIELD_CAP = 0.25


def _period_end(period):
    """The date a reported period ends, or None."""
    if not isinstance(period, dict):
        return None
    for field in ("date", "endDate", "fillingDate"):
        raw = period.get(field)
        if not raw:
            continue
        try:
            return datetime.datetime.strptime(str(raw)[:10], "%Y-%m-%d").date()
        except ValueError:
            continue
    return None


def _one_year_before(day):
    """The same day a year earlier, stepping back from 29 February."""
    try:
        return day.replace(year=day.year - 1)
    except ValueError:                       # 29 February
        return day.replace(year=day.year - 1, day=28)


def trailing_twelve_months(periods):
    """The reported periods covering the year that ends at the latest one.

    Taking "the last four" covers twelve months only for a company that
    reports quarterly, and most UK companies report half-yearly: FMP labels
    their two periods Q2 and Q4, so four of them are two years. TRN's four
    came to 304m against a true trailing twelve months of 172m, and the
    buyback yields that came out of that reached 65%.

    Periods are consecutive and do not overlap, so the ones inside the year
    are the ones ending after the same day a year before the latest end -
    which is the period boundaries doing the work rather than a count. A
    period ending exactly a year earlier is the year before's and is left
    out.
    """
    dated = []
    for period in (periods or []):
        end = _period_end(period)
        if end is not None:
            dated.append((end, period))

    if not dated:
        return []

    dated.sort(key=lambda pair: pair[0], reverse=True)
    cutoff = _one_year_before(dated[0][0])

    return [period for end, period in dated if end > cutoff]


def buyback_yield(periods, market_cap, cap=BUYBACK_YIELD_CAP, log=None):
    """Trailing twelve month buybacks as a fraction of market capitalisation.

    commonStockRepurchased is a cash outflow, so FMP reports it negative; the
    sign is dropped because a yield is what was handed back, not a deficit. A
    company that issued shares rather than buying them back reports a positive
    number here, which is not a negative buyback yield - it is a different
    thing - so only outflows count.

    Only the periods inside the trailing year count, whether the company
    reports quarterly, half-yearly or annually: see trailing_twelve_months().
    A period with no readable end date is not counted at all, because there is
    no way to tell which year it belongs to.

    The sum is in the company's reporting currency and the market cap is in
    the quote currency, which for a London line is pounds. That mismatch is
    corrected downstream by fx_adjust='DIVIDE' rather than here, so one place
    owns the conversion.
    """
    if not market_cap:
        return None

    try:
        market_cap = float(market_cap)
    except (TypeError, ValueError):
        return None
    if market_cap <= 0:
        return None

    counted = trailing_twelve_months(periods)
    if not counted:
        return None

    spent = 0.0
    seen = False
    for period in counted:
        value = period.get("commonStockRepurchased")
        if value is None or value == "":
            continue
        try:
            value = float(value)
        except (TypeError, ValueError):
            continue
        seen = True
        if value < 0:
            spent += -value

    if not seen:
        return None

    yield_ = spent / market_cap

    if cap is not None and yield_ > cap:
        if log:
            log("  buyback yield {:.1%} over the {:.0%} cap from {} period(s) "
                "ending {} - not written".format(
                    yield_, cap, len(counted), _period_end(counted[0])))
        return None

    return yield_


def flatten(sections, log=None):
    """{section: record} -> {"Section.field": value} ready for stock_info.

    log, when given, is called with a line about anything dropped on the way
    through - today only a buyback yield over the sanity cap, which is silent
    otherwise and would look like a company that simply bought nothing back.

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

    # One EPS growth field, whichever of the two the response carries. FMP
    # sends epsgrowth on some responses and epsDilutedGrowth on others, and a
    # provider_field is one column, so the choice is made here rather than in
    # the mapping - where it would have to be made again for every indicator
    # that wanted it. Both raw fields are still written above.
    growth = sections.get("Growth") or {}
    if isinstance(growth, dict):
        for field in ("epsgrowth", "epsDilutedGrowth"):
            value = growth.get(field)
            if value is not None and value != "" and not isinstance(value, (dict, list)):
                flat["Derived.epsGrowth"] = value
                break

    # Buybacks over the market cap. Not a figure FMP sends: it reports the
    # dividend yield but not what a company handed back by buying its own
    # shares, and the two together are the shareholder yield the value score
    # ranks on. The raw market cap, not the pence-normalised one: the cash
    # flow figures are in major units and so is marketCap.
    buybacks = buyback_yield(sections.get("CashFlowQuarters"),
                             profile.get("marketCap"), log=log)
    if buybacks is not None:
        flat["Derived.buybackYieldTTM"] = round(buybacks, 8)

    return flat
