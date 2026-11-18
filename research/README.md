# Backtest research (Mac mini)

This is a Python setup for testing many strategy ideas on UK shares without fooling ourselves. The PHP engine on the N100 stays the final check for anything that looks good. This setup is for research: it runs fast, handles many variants, and is honest about its limits.

It runs natively on the Mac mini under `uv` (Python 3.12, locked in `uv.lock`). It does not run on the N100.

## Install (Jarvis)

1. Dale writes `~/.config/sharemanager-research/env` with mode 600, containing one line: `FMP_API_KEY=...`. Nothing logs the key, and error messages mask it.
2. Install `uv` with `brew install uv`.
3. Clone the repo to `~/sharemanager-research` using the read-only deploy key.
4. In `~/sharemanager-research/research`, run `uv sync`.

Data goes to `~/sharemanager-research/data/` as Parquet, reports to `~/sharemanager-research/reports/` and logs to `~/sharemanager-research/logs/`. All three folders are outside git. Paths are set in `config.yaml`.

## Order of work

| Step | Command | Check |
|---|---|---|
| 1 | `nohup uv run research fetch --full > ~/sharemanager-research/logs/fetch.out 2>&1 &` | `data/data_report.md`; progress in `data/fetch_state.json` |
| 2 | `uv run research universe --live stock_symbols.csv` | overlap with the N100's list, also in `data/universe_overlap.json` |
| 3 | `uv run research validate` | must pass before anything else is trusted; reads the three files in `data/validation/` |
| 4 | `uv run research signals` | one comparison table for Dale |
| nightly | `uv run research fetch` | top-up: new prices, market caps and statements |
| any time | `uv run research fetch --merge-only` | no downloads: merge, master pass, price cleaning, data report |

After every merge, `master.py` makes a second pass over the symbol master using the prices. It sets each line's quote currency and market-cap currency from price × shares, because FMP's profile currency is sometimes the reporting currency. It also drops loan stock and bond-like series, overseas ISINs (anything outside GB, JE, GG and IM, counted by prefix in `data/master_report.json`) and second share classes, and marks AIM shares from `data/reference/aim_companies.*` (the LSE file, as CSV or XLSX). `clean.py` drops bad ticks: a move over 50% that comes back within 20% inside 3 sessions. They are recorded in `data/bad_ticks.parquet`.

A full pull is resumable. Each symbol's file is written as soon as it is fetched, so if the run is interrupted, starting it again carries on from where it stopped. Calls are paced to `fmp.calls_per_minute` (600), drop to a lower limit if FMP announces one in its headers, and back off on 429.

The N100 exports for step 3 are CSV files:

- `stock_symbols.csv` has a `symbol` column.
- The run 9 orders file has the columns `date, symbol, side, quantity, price`.
- The run 9 values file has the columns `date, total`.

## Other commands

```
uv run research run strategies/trend_following.yaml --period dev
uv run research run strategies/*.yaml --period val
uv run research run strategies/x.yaml --period holdout --final     # the final exam, once
uv run research compare momentum_12_1
uv run research report <run-id>
```

A strategy is a small YAML file in `strategies/`. The docstring in `src/research/strategy.py` lists every field. The file's hash is stored with each run, so give a changed strategy a new `name`, but keep its `family` so that it counts as another trial of the same idea.

## What is where

| File | What it does |
|---|---|
| `fetch.py` | Symbol master (listed + delisted `.L`, exclusions with reasons), prices (raw and dividend-adjusted, in 15-year chunks), dividends, market cap from 2007, statements (annual and interim), FX |
| `checks.py` | The data report: gaps over 10 sessions, day moves over 50%, pounds-not-pence, empty revenue/total assets, counts per year |
| `pit.py` | The publication lag: accounts are usable from period end + 4 months (annual) or + 3 months (interim) |
| `universe.py` | The top 350 by market cap, main market only (AIM can be switched on), rebuilt on the first trading day of each month from 2007; "FTSE 100-like" and "FTSE 250-like" tiers |
| `engine.py` | The portfolio simulation, using the N100's rules (see the module docstring) |
| `costs.py` | Trading 212 costs, with the date they were checked |
| `signals/` | The ten Open Source Asset Pricing signals (`osap.py`, each citing its paper) and the two N100 strategies (`live.py`) |
| `periods.py` | The development, validation and locked holdout periods |
| `runlog.py` | The append-only test log (`log/runs.parquet`, one part file per run) and the freeze after `--final` |
| `metrics.py` | Metrics, and the deflated Sharpe ratio (Bailey and López de Prado, 2014) |
| `report.py` | One self-contained HTML file per run |

## Rules that keep it honest

- **Holdout.** The holdout runs from 18 May 2025 to the latest date. Any run reaching it fails unless `--final` is passed. A final run is logged as such and freezes the strategy: a second final, or a later run of that name with a changed file, is refused. The run 9 reproduction is the only exception, because it is an engine check (logged as `engine_validation`). It does show trend following over holdout dates, so treat that figure as seen.
- **Test log.** Every run is logged, good or bad. Nothing in the package deletes or rewrites a run. The log is git-ignored, so back it up.
- **Gate.** A strategy goes forward only if it beats the equal-weight benchmark after costs in both development and validation, and its deflated Sharpe (SR − SR0, using the family's trial count from the log) is still positive.
- **Caveats.** Every report prints them: the development period is survivorship-biased, statements carry FMP's latest restated figures, and the lag is a rule rather than the real publication date.

## Tests

Run them with `uv run pytest`. They cover:

- the publication lag
- delisting exits
- the universe rebuild
- costs
- the holdout lock and the freeze
- the append-only log
- pence-to-pounds conversion and share counts
- the next-close fill on the adjusted series
- the buffer and the exit rule
- each signal on hand-made data
- the deflated Sharpe ratio
- an end-to-end fetch-to-report run against a fake FMP

## Known gaps

- **AIM flag.** FMP does not mark AIM lines. The LSE file lists only companies on AIM today, so dead AIM companies stay unmarked.
- **Delisting reason.** FMP's delisted list gives no reason. Both cases are handled the same way: the company leaves at its last traded price.
- **Periods.** Development runs from 2007 to 2022 and is survivorship-biased. Validation runs from 2023 to 17 May 2025, because the data has dead companies only from 2023. Both are in `config.yaml`.
