"""The London Stock Exchange issuer lists: market, country and listing type, month by month.

Files in data/reference/lse_issuer_lists/ (Reports, Issuers on
londonstockexchange.com): Issuer_list.xlsx (the current list) and one
workbook per year from 2016, each with a sheet per month. The header row is
found by its column names. The columns vary by year:

    2016   List Date, Company, ..., Country of Incorporation, Market,
           Mkt Cap £m, International Main Market, FCA Listing Category
    2020+  Admission Date, Company Name, ..., Country of Incorporation,
           World Region, Market (+ International Issuer, Company Market Cap)

There is no TIDM or ISIN, so companies are matched to the FMP master by
normalised name, with market cap (within 20% in the same month) as the
tie-break.

What the universe takes from it, per company and month:

- Main Market only (AIM too if include_aim); every other market is out;
- incorporated in the UK, Jersey, Guernsey or the Isle of Man: in;
- incorporated elsewhere: in only with a premium listing (ESCC from July
  2024), the FTSE UK index rule that admits DCC or IAG and leaves BHP out.
  Where a month has no listing-category column, the company's category from
  the nearest month that has one is used, carried by matched symbol; failing
  that, out, and listed in the report. (The International Main Market /
  Issuer flag marks non-UK incorporation, not a secondary listing: it is Y
  for premium-listed DCC, so it decides nothing.)
- "MAIN MARKET / ADMISSION TO TRADING ONLY" is not a listing: out;
- before January 2016 each company's January 2016 status is used;
- a company in no list at all falls back to the ISIN rule (GB, JE, GG, IM).

data/reference/listing_overrides.csv (symbol, decision in/out, reason) can
correct a decision the rule gets wrong; every override is reported.
"""
from __future__ import annotations

import datetime as dt
import json
import logging
import re
from pathlib import Path

import numpy as np
import pandas as pd

log = logging.getLogger(__name__)

STOP = {"PLC", "LTD", "LIMITED", "GROUP", "HOLDINGS", "HOLDING", "THE"}
MONTHS = {m: i for i, m in enumerate(["jan", "feb", "mar", "apr", "may", "jun",
                                      "jul", "aug", "sep", "oct", "nov", "dec"], 1)}
HOME = {"UNITED KINGDOM", "UK", "GREAT BRITAIN", "ENGLAND", "ENGLAND AND WALES", "SCOTLAND", "WALES",
        "NORTHERN IRELAND", "JERSEY", "GUERNSEY", "ISLE OF MAN", "CHANNEL ISLANDS"}


def norm_name(name) -> str:
    if not isinstance(name, str):
        return ""
    s = name.upper().replace("&", " AND ")
    s = re.sub(r"[.'`’]", "", s)               # P.L.C. -> PLC, Fuller's -> FULLERS
    s = re.sub(r"[^A-Z0-9 ]+", " ", s)
    return " ".join(t for t in s.split() if t not in STOP)


def _find_header(raw: pd.DataFrame) -> int | None:
    for i in range(min(40, len(raw))):
        cells = [str(v).strip().lower() for v in raw.iloc[i].tolist()]
        if any(c.startswith("company") for c in cells) and "market" in [c.strip() for c in cells]:
            return i
    return None


def _columns(cols) -> dict:
    out = {}
    for c in cols:
        l = str(c).strip().lower()
        if l.startswith("company") and "cap" not in l:
            out.setdefault("company", c)
        elif l == "market":
            out["market"] = c
        elif "country of incorporation" in l:
            out["country"] = c
        elif "cap" in l:
            out["cap_m"] = c
        elif l in ("list date", "admission date") or "admission" in l:
            out.setdefault("admission", c)
        elif "listing category" in l:
            out["category"] = c
        elif "international main market" in l:
            out["intl_main"] = c
        elif "international issuer" in l:
            out["intl_issuer"] = c
    return out


def _sheet_month(sheet: str, year: int | None) -> pd.Timestamp | None:
    s = sheet.strip().lower()
    m = MONTHS.get(s[:3])
    if m is None or year is None:
        return None
    y = re.search(r"(20\d\d|\d\d)\s*$", s)
    if y and len(y.group(1)) == 2 and s[3:].strip():
        year = 2000 + int(y.group(1))
    return pd.Timestamp(year=year, month=m, day=1)


def _year_from_dates(df: pd.DataFrame, cmap: dict, month: int) -> int | None:
    """The sheet's year from the admission dates in it: the latest admission is on or before the sheet's month."""
    col = cmap.get("admission")
    if not col:
        return None
    d = pd.to_datetime(df[col], errors="coerce", dayfirst=True).dropna()
    d = d[(d.dt.year >= 1990) & (d.dt.year <= 2100)]
    if d.empty:
        return None
    last = d.max()
    return int(last.year if last.month <= month else last.year + 1)


class UnreadableWorkbook(RuntimeError):
    pass


def read_issuer_lists(folder: Path, today: dt.date | None = None) -> tuple[pd.DataFrame, dict]:
    """All months in one frame, and the columns each year had.

    The year of a month sheet comes from, in order: the sheet name ("Jan 25"),
    a year in the folder the workbook sits in (Issuer_list_archive_2025_11),
    the admission dates inside the sheet, and only then the file name. The
    2025 archive is a file named ..._2026-01-05... because it was produced in
    January 2026, and the file name alone made it a second 2026.

    A workbook that cannot be read stops the run: skipping one silently lost
    the only year with listing categories.
    """
    folder = Path(folder)
    today = today or dt.date.today()
    frames, columns_found, sources = [], {}, {}
    files = sorted(p for p in folder.rglob("*") if p.suffix.lower() in (".xlsx", ".xls", ".xlsm")
                   and not p.name.startswith("~$"))
    for f in files:
        rel_dirs = f.relative_to(folder).parts[:-1]
        fy = next((int(m.group(1)) for d in reversed(rel_dirs) for m in [re.search(r"(20\d\d)", d)] if m), None)
        ny = re.search(r"(20\d\d)", f.name)
        name_year = int(ny.group(1)) if ny else None
        try:
            book = pd.read_excel(f, sheet_name=None, header=None, engine="calamine")
        except Exception as e:
            raise UnreadableWorkbook(f"cannot read {f.name}: {e}") from None
        month_sheets = [s for s in book if _sheet_month(s, 2000) is not None]
        for sheet, raw in book.items():
            h = _find_header(raw)
            if h is None:
                continue
            df = raw.iloc[h + 1:].copy()
            df.columns = [str(c).strip() for c in raw.iloc[h].tolist()]
            cmap = _columns(df.columns)
            if "company" not in cmap or "market" not in cmap:
                continue
            probe = _sheet_month(sheet, 2000)
            if probe is not None:
                sheet_named_year = re.search(r"(20\d\d|\b\d\d)\s*$", sheet.strip()[3:]) if sheet.strip()[3:].strip() else None
                if sheet_named_year:
                    year = _sheet_month(sheet, 2000).year
                else:
                    year = fy or _year_from_dates(df, cmap, probe.month) or name_year
                if year is None:
                    continue
                month = pd.Timestamp(year=year, month=probe.month, day=1)
                basis = "sheet name" if sheet_named_year else ("folder" if fy else "dates inside" if year != name_year else "file name")
            elif not month_sheets:
                month = pd.Timestamp(year=today.year, month=today.month, day=1)       # the current list
                basis = "current list"
            else:
                continue                                                          # a stray sheet
            columns_found.setdefault(str(month.year), set()).update(cmap)
            out = pd.DataFrame({k: df[v] for k, v in cmap.items()})
            out = out[out["company"].notna()]
            out["company"] = out["company"].astype(str).str.strip()
            for k in ("market", "country", "category", "intl_main", "intl_issuer"):
                if k in out:
                    out[k] = out[k].astype("string").str.strip().str.upper()
            if "cap_m" in out:
                out["cap_m"] = pd.to_numeric(out["cap_m"], errors="coerce")
            out["month"] = month
            out["source"] = f"{f.name}:{sheet}"
            sources.setdefault(str(month.date())[:7], []).append(f"{f.name}:{sheet} ({basis})")
            frames.append(out)
    info = {k: sorted(v) for k, v in columns_found.items()}
    if not frames:
        return pd.DataFrame(columns=["month", "company", "market"]), info
    allm = pd.concat(frames, ignore_index=True)
    allm["norm"] = allm["company"].map(norm_name)
    # One row per company and month. Where one name has two rows (Investec plc
    # and Investec Ltd both normalise to INVESTEC), the plain Main Market row wins
    # over "MAIN MARKET / ADMISSION TO TRADING ONLY", then AIM, then the rest.
    rank = allm["market"].map(lambda x: 0 if x == "MAIN MARKET" else 1 if str(x).startswith("MAIN MARKET")
                              else 2 if x == "AIM" else 3)
    allm = allm.assign(_r=rank).sort_values(["month", "norm", "_r"]).drop_duplicates(["month", "norm"]).drop(columns="_r")
    counts = allm.groupby("month").size()
    med = float(counts.median())
    info["_rows_per_month"] = {str(k.date())[:7]: int(v) for k, v in counts.items()}
    info["_thin_months"] = {str(k.date())[:7]: int(v) for k, v in counts[counts < 0.6 * med].items()}
    info["_sheets_per_month"] = {k: v for k, v in sources.items()}
    info["_months_from_two_files"] = {k: v for k, v in sources.items() if len({s.split(':')[0] for s in v}) > 1}
    return allm, info


def match_to_master(issuers: pd.DataFrame, master: pd.DataFrame, caps: pd.DataFrame | None = None) -> tuple[pd.DataFrame, dict]:
    """Add `symbol` to each issuer row by normalised name; report match rates and doubles."""
    lines = master[master["included"].fillna(False).astype(bool)].copy()
    lines["norm"] = lines["name"].map(norm_name)
    by_norm = lines.groupby("norm")["symbol"].apply(list).to_dict()
    by_norm.pop("", None)
    # A looser second try: one name is the other's leading words, either way
    # round ("DCC" in 2016 and "DCC ENERGY" now; "BAE SYSTEMS" and "BAE SYSTEMS ORD").
    by_first = {}
    for n in by_norm:
        by_first.setdefault(n.split()[0], []).append(n)

    cap_at = None
    if caps is not None and len(caps):
        c = caps.copy()
        c["month"] = c.index.to_period("M").to_timestamp() if isinstance(c.index, pd.DatetimeIndex) else c["month"]
        cap_at = c.groupby("month").last()       # month x symbol, pounds, last close of the month

    syms_out, doubles = [], {}
    for row in issuers.itertuples(index=False):
        cand = by_norm.get(row.norm)
        if not cand and row.norm:
            toks = row.norm.split()
            hits = [n for n in by_first.get(toks[0], [])
                    if (lambda a, b: a == b[:len(a)])(*(sorted([n.split(), toks], key=len)))]
            loose = sorted({s for n in hits for s in by_norm[n]})
            cand = loose if len(loose) == 1 else None
        if not cand:
            syms_out.append(None)
            continue
        if len(cand) > 1:
            pick = None
            lse_cap = getattr(row, "cap_m", np.nan)
            if cap_at is not None and pd.notna(lse_cap) and row.month in cap_at.index:
                gaps = {s: abs(cap_at.at[row.month, s] / (lse_cap * 1e6) - 1)
                        for s in cand if s in cap_at.columns and pd.notna(cap_at.at[row.month, s])}
                close = [s for s, g in gaps.items() if g <= 0.2]
                if len(close) == 1:
                    pick = close[0]
            if pick is None:
                doubles.setdefault(row.company, sorted(cand))
                pick = sorted(cand)[0]
            syms_out.append(pick)
        else:
            syms_out.append(cand[0])
    out = issuers.copy()
    out["symbol"] = syms_out
    rate = out.assign(y=out["month"].dt.year, hit=out["symbol"].notna()) \
        .loc[lambda d: d["market"].isin(["MAIN MARKET", "AIM"])].groupby("y")["hit"].mean().round(3).to_dict()
    return out, {"match_rate_by_year": {int(k): float(v) for k, v in rate.items()},
                 "names_matching_two_lines": doubles}


def _is_premium(cat) -> bool:
    if not isinstance(cat, str) or not cat:
        return False
    c = cat.upper()
    return ("PREMIUM" in c or c.strip() == "ESCC" or "COMMERCIAL COMPANIES" in c) and "SECONDARY" not in c \
        and "INTERNATIONAL COMMERCIAL" not in c


def monthly_status(matched: pd.DataFrame, months: pd.DatetimeIndex) -> pd.DataFrame:
    """symbol x month status with the basis for each decision.

    Columns: symbol, month, market, country, home, premium, basis.
    """
    s = matched.dropna(subset=["symbol"]).copy()
    if s.empty:
        return pd.DataFrame(columns=["symbol", "month", "market", "country", "home", "premium", "basis"])
    s = s.sort_values(["symbol", "month"]).drop_duplicates(["symbol", "month"])
    for k in ("category", "intl_main", "intl_issuer", "country"):
        if k not in s:
            s[k] = pd.NA
    # The listing category where a month has none: the nearest month that has one.
    s["category_used"] = s.groupby("symbol")["category"].transform(lambda x: x.ffill().bfill())
    s["category_from"] = np.where(s["category"].notna(), "this month",
                                  np.where(s["category_used"].notna(), "nearest month with a category", None))
    cols = ["market", "country", "category_used", "category_from", "intl_main", "intl_issuer"]
    jan16 = pd.Timestamp("2016-01-01")
    later = months[months >= jan16]
    early = months[months < jan16]
    rows = []
    for sym, g in s.groupby("symbol"):
        g = g.set_index("month")[cols]
        r = g.reindex(later, method="ffill", tolerance=pd.Timedelta(days=100))
        basis = pd.Series(np.where(r.index.isin(g.index), "this month", "carried from the last month listed"),
                          index=later)
        r = r[r["market"].notna()]
        part = r.assign(basis=basis.reindex(r.index))
        if len(early) and g.index.min() <= jan16 + pd.Timedelta(days=31):
            e = pd.DataFrame([g.iloc[0].to_numpy()] * len(early), index=early, columns=cols)
            part = pd.concat([e.assign(basis="Jan 2016 status (before the lists start)"), part])
        part.insert(0, "symbol", sym)
        rows.append(part.rename_axis("month").reset_index())
    if not rows:
        return pd.DataFrame(columns=["symbol", "month", "market", "country", "home", "premium", "basis"])
    st = pd.concat(rows, ignore_index=True).rename(columns={"category_used": "category"})
    st["home"] = st["country"].isin(list(HOME)).fillna(False).astype(bool)
    prem = st["category"].map(_is_premium)
    st["premium"] = prem
    # The International Main Market / International Issuer flag is Y for DCC,
    # which is premium: it marks non-UK incorporation, not a secondary listing,
    # so it decides nothing here.
    st["overseas_rule"] = np.where(st["home"], "home country",
                           np.where(st["category"].notna(), np.where(prem, "premium listing", "not premium"),
                                    "no category: out"))
    return st


def eligible(st: pd.DataFrame, include_aim: bool) -> pd.Series:
    mk = st["market"].fillna("")
    on_market = (mk == "MAIN MARKET") | (include_aim & (mk == "AIM"))
    overseas_ok = st["home"] | st["overseas_rule"].eq("premium listing")
    return (on_market & overseas_ok).astype(bool)


def load_overrides(data: Path) -> pd.DataFrame:
    p = Path(data) / "reference" / "listing_overrides.csv"
    if not p.exists():
        return pd.DataFrame(columns=["symbol", "decision", "reason"])
    o = pd.read_csv(p, dtype=str)
    o["symbol"] = o["symbol"].str.strip().map(lambda s: s if s.endswith(".L") else s + ".L")
    o["decision"] = o["decision"].str.strip().str.lower()
    return o


def build_status(data: Path, master: pd.DataFrame, caps_wide: pd.DataFrame | None, months: pd.DatetimeIndex,
                 include_aim: bool) -> tuple[pd.DataFrame | None, dict]:
    """Eligibility per month and symbol (month x symbol bool), or None without the lists."""
    folder = Path(data) / "reference" / "lse_issuer_lists"
    if not folder.exists():
        return None, {"lse_issuer_lists": "not found"}
    issuers, cols = read_issuer_lists(folder)
    matched, rep = match_to_master(issuers, master.reset_index() if "symbol" not in master else master, caps_wide)
    st = monthly_status(matched, months)
    st["eligible"] = eligible(st, include_aim)
    # LSE market cap beside FMP's, per matched company and month.
    lse_caps = pd.DataFrame(columns=["symbol", "month", "cap_m", "company"])
    if "cap_m" in matched:
        lse_caps = matched.dropna(subset=["symbol", "cap_m"])[["symbol", "month", "cap_m", "company"]]
    st = st.merge(lse_caps.drop_duplicates(["symbol", "month"]), on=["symbol", "month"], how="left")
    # A line whose FMP cap is over 3 times the LSE's may carry its issuer's cap
    # rather than its own. Reported, not excluded: the rule flagged 176 lines
    # including ABF.L, which belongs in. Non-ordinary lines such as SANB.L
    # (Santander UK's preference line) go out through listing_overrides.csv.
    st["fmp_cap"] = np.nan
    if caps_wide is not None and len(caps_wide):
        mcap = caps_wide.groupby(caps_wide.index.to_period("M").to_timestamp()).last()
        st["fmp_cap"] = [mcap.at[m_, s_] if (m_ in mcap.index and s_ in mcap.columns) else np.nan
                         for m_, s_ in zip(st["month"], st["symbol"])]
    inflated = (st["fmp_cap"] > 3 * st["cap_m"] * 1e6).fillna(False).astype(bool)
    st["inflated_cap"] = inflated
    ov = load_overrides(data)
    st["override"] = None
    for r in ov.itertuples(index=False):
        st.loc[st["symbol"] == r.symbol, "eligible"] = r.decision == "in"
        st.loc[st["symbol"] == r.symbol, "override"] = f"{r.decision}: {r.reason}"
    elig = st.pivot(index="month", columns="symbol", values="eligible").reindex(months)
    st.to_parquet(Path(data) / "lse_status.parquet")
    rep.update({
        "files_columns_by_year": cols,
        "listing_category_years": sorted(y for y, c in cols.items() if not y.startswith("_") and "category" in c),
        "category_rule": "a month with no category column uses the company's category from the nearest month "
                         "that has one, carried by matched symbol (DCC in 2016 is DCC ENERGY now); failing that "
                         "an overseas company is out unless listing_overrides.csv says in. The International "
                         "Main Market / Issuer flag is not used: it is Y for premium DCC.",
        "overseas_rule_counts_latest": st[st["month"] == st["month"].max()]["overseas_rule"].value_counts().to_dict()
        if len(st) else {},
        "basis_counts": st["basis"].value_counts().to_dict() if len(st) else {},
        "overrides": ov.to_dict("records"),
        "lines_with_fmp_cap_over_3x_lse (reported only)": sorted(st.loc[st["inflated_cap"], "symbol"].unique().tolist()),
        "issuer_rows": int(len(issuers)), "matched_rows": int(matched["symbol"].notna().sum()),
    })
    return elig, rep


def isin_fallback(master: pd.DataFrame, include_aim: bool) -> pd.Series:
    """For a company in no LSE list: in if its ISIN country is GB, JE, GG or IM (or unknown), and not AIM."""
    ov = master.get("overseas_isin", pd.Series(False, index=master.index)).fillna(False).astype(bool)
    aim = master.get("is_aim", pd.Series(False, index=master.index)).fillna(False).astype(bool)
    return ~ov & (include_aim | ~aim)


def cap_check(universe: pd.DataFrame, data: Path) -> pd.DataFrame:
    """Universe members whose FMP cap is more than 25% from the LSE's in the same month."""
    sp = Path(data) / "lse_status.parquet"
    if not sp.exists():
        return pd.DataFrame()
    st = pd.read_parquet(sp, columns=["symbol", "month", "cap_m", "company"]).dropna(subset=["cap_m"])
    u = universe.copy()
    u["month"] = pd.to_datetime(u["date"]).dt.to_period("M").dt.to_timestamp()
    j = u.merge(st, on=["symbol", "month"], how="inner")
    j["gap"] = j["market_cap"] / (j["cap_m"] * 1e6) - 1
    return j[j["gap"].abs() > 0.25][["date", "symbol", "rank", "market_cap", "cap_m", "gap", "company"]]


def write_report(data: Path, rep: dict) -> Path:
    p = Path(data) / "lse_report.json"
    p.write_text(json.dumps(rep, indent=1, default=str))
    return p
