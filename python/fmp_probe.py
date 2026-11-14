#!/usr/bin/env python3
"""Probe the Financial Modeling Prep API and report what it actually returns.

The EODHD mapping in sql/eodhd_migration.sql was written from the provider's
published field names and never checked against a live response, which is why
README-eodhd.md still carries a "correct this against real output" step. This
script exists so the FMP mapping does not repeat that: run it once, paste the
output, and the adapter gets built against what the API really sends.

    export FMP_API_KEY=...        # or it will prompt
    python3 python/fmp_probe.py               # per-symbol endpoints, VOD.L
    python3 python/fmp_probe.py SHEL.L BP.L
    python3 python/fmp_probe.py --bulk        # whole-universe endpoints

The --bulk run matters at scale: 2000 symbols is four calls each for
fundamentals and one a day each for prices, where a bulk endpoint is one call
for the lot. Those endpoints answer CSV rather than JSON and are a higher-tier
feature, so the probe reports either shape and treats a 402 or 403 as an
answer.

It only reads. It writes nothing, changes nothing, and never prints the key -
the key is stripped from every URL before it is shown.

Each endpoint is tried for your symbol and for a US control symbol. That
distinguishes the two failures that look identical from the outside:

  symbol empty, control populated  -> the plan does not cover that exchange
  both empty                       -> wrong endpoint, or wrong ticker format

FMP takes the key as an ?apikey= query parameter on both the v3 and the newer
stable API; neither reads an Authorization header.
"""

import json
import os
import sys
import getpass
from urllib import request, parse, error

CONTROL = "AAPL"          # US symbol every plan covers, as a control
TIMEOUT = 25
MAX_VALUE = 60            # truncate long values when showing a sample


def endpoints(symbol):
    """The endpoints the application needs, on both API generations."""
    return [
        ("v3     quote",         f"/api/v3/quote/{parse.quote(symbol)}", {}),
        ("v3     historical",    f"/api/v3/historical-price-full/{parse.quote(symbol)}",
                                 {"from": "2026-09-01", "to": "2026-09-30"}),
        ("v3     profile",       f"/api/v3/profile/{parse.quote(symbol)}", {}),
        ("v3     key-metrics",   f"/api/v3/key-metrics-ttm/{parse.quote(symbol)}", {}),
        ("v3     ratios-ttm",    f"/api/v3/ratios-ttm/{parse.quote(symbol)}", {}),
        ("stable quote",         "/stable/quote",            {"symbol": symbol}),
        ("stable historical",    "/stable/historical-price-eod/full",
                                 {"symbol": symbol, "from": "2026-09-01", "to": "2026-09-30"}),
        ("stable profile",       "/stable/profile",          {"symbol": symbol}),
        ("stable key-metrics",   "/stable/key-metrics-ttm",  {"symbol": symbol}),
        ("stable ratios",        "/stable/ratios-ttm",       {"symbol": symbol}),
    ]


BULK = [
    # Whole-universe downloads. On FMP these are a Premium/Ultimate feature and
    # answer CSV rather than JSON, so the probe reports either shape.
    ("profile-bulk",        "/stable/profile-bulk",        {"part": "0"}),
    ("ratios-ttm-bulk",     "/stable/ratios-ttm-bulk",     {"part": "0"}),
    ("key-metrics-ttm-bulk", "/stable/key-metrics-ttm-bulk", {"part": "0"}),
    ("scores-bulk",         "/stable/scores-bulk",         {"part": "0"}),
    # One call for every symbol's close on a given day, which is what a nightly
    # price refresh wants instead of one call per symbol.
    ("batch-eod",           "/stable/batch-eod",           {"date": "2026-11-13"}),
    # A quote for a list of symbols in one request.
    ("batch-quote",         "/stable/batch-quote",         {"symbols": "VOD.L,SHEL.L,BP.L"}),
]


def call(path, params, key):
    """GET one endpoint. Returns (http status, parsed body or raw text)."""
    query = dict(params)
    query["apikey"] = key
    url = "https://financialmodelingprep.com" + path + "?" + parse.urlencode(query)
    try:
        with request.urlopen(url, timeout=TIMEOUT) as r:
            raw = r.read().decode("utf-8", "replace")
            status = r.status
    except error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        status = e.code
    except Exception as e:                      # network, DNS, TLS
        return 0, f"{type(e).__name__}: {e}"
    try:
        return status, json.loads(raw)
    except ValueError:
        # Bulk endpoints answer CSV. Keep enough to show the header and a row.
        return status, raw[:4000]


def first_record(body):
    """The first object in whatever shape came back, or None."""
    if isinstance(body, list):
        return body[0] if body and isinstance(body[0], dict) else None
    if isinstance(body, dict):
        # historical-price-full nests the series under "historical"
        for k in ("historical", "data", "results"):
            v = body.get(k)
            if isinstance(v, list) and v and isinstance(v[0], dict):
                return v[0]
        return body
    return None


def describe(body):
    """One line saying what shape came back."""
    if isinstance(body, list):
        return f"list of {len(body)}"
    if isinstance(body, dict):
        for k in ("historical", "data", "results"):
            if isinstance(body.get(k), list):
                return f"dict with {k}[] of {len(body[k])}"
        if "Error Message" in body:
            return "error: " + str(body["Error Message"])[:120]
        return f"dict of {len(body)} keys"
    return str(body)[:120]


def show(label, status, body, verbose):
    print(f"  {label:22} http {status:<4} {describe(body)}")
    if not verbose:
        return
    rec = first_record(body)
    if not isinstance(rec, dict):
        return
    for k, v in list(rec.items()):
        text = json.dumps(v) if not isinstance(v, str) else v
        if len(text) > MAX_VALUE:
            text = text[:MAX_VALUE] + "..."
        print(f"      {k:34} {text}")


def show_bulk(label, status, body):
    """Report a bulk endpoint, which may answer CSV rather than JSON."""
    if isinstance(body, (list, dict)):
        print(f"  {label:22} http {status:<4} {describe(body)}")
        rec = first_record(body)
        if isinstance(rec, dict):
            print("      fields: " + ", ".join(list(rec)[:14]))
        return

    text = str(body)
    if status != 200:
        print(f"  {label:22} http {status:<4} {text.strip()[:150]}")
        return

    lines = [l for l in text.splitlines() if l.strip()]
    if not lines:
        print(f"  {label:22} http {status:<4} empty")
        return
    header = lines[0]
    print(f"  {label:22} http {status:<4} CSV, {len(lines) - 1}+ rows in this chunk")
    print("      columns: " + header[:200])
    if len(lines) > 1:
        print("      first:   " + lines[1][:200])


def main():
    key = os.environ.get("FMP_API_KEY") or getpass.getpass("FMP API key (not echoed, not printed): ")
    if not key.strip():
        sys.exit("no key given")
    key = key.strip()

    args = [a for a in sys.argv[1:] if a != "--bulk"]
    bulk_only = "--bulk" in sys.argv
    symbols = args or ["VOD.L"]

    print("Financial Modeling Prep probe")
    print("The key is never printed; URLs below are shown without it.\n")

    if bulk_only:
        print("=== bulk endpoints (whole universe in one call) " + "=" * 14)
        print("A 402 or 403 here means the endpoint is not on this plan; that is\n"
              "an answer, not a failure - the loader just fetches per symbol.\n")
        for label, path, params in BULK:
            status, body = call(path, params, key)
            show_bulk(label, status, body)
        print("\nPaste all of the above back. Nothing in it is secret.")
        return

    for symbol in symbols:
        print(f"=== {symbol} " + "=" * (60 - len(symbol)))
        for label, path, params in endpoints(symbol):
            status, body = call(path, params, key)
            show(label, status, body, verbose=True)
        print()

    print(f"=== {CONTROL} (US control: shows whether an empty result above is "
          f"your plan or the endpoint) " + "=" * 5)
    for label, path, params in endpoints(CONTROL):
        status, body = call(path, params, key)
        show(label, status, body, verbose=False)

    print("\nPaste all of the above back. Nothing in it is secret.")


if __name__ == "__main__":
    main()
