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

After every merge, `master.py` makes a second pass over the symbol master using the prices. It sets each line's quote currency and market-cap currency from price × shares, because FMP's profile currency is sometimes the reporting currency. It also drops loan stock and bond-like series, second share classes, and a second London line under the same company name (INVP/INVR).

`universe` then reads the LSE issuer lists in `data/reference/lse_issuer_lists/` (`lse.py`). Each company is matched by normalised name, with market cap as the tie-break. Month by month it must be on the Main Market (no AIM), and either incorporated in the UK, Jersey, Guernsey or the Isle of Man, or premium (ESCC) listed. A month with no listing-category column uses the company's category from the nearest month that has one. Before 2016, each company's January 2016 status is used. A company in no list falls back to the ISIN rule. A line whose FMP cap is over 3 times the LSE's is reported, not excluded. Non-ordinary lines such as SANB.L go out through the overrides file. Where two lines share a company name, the universe decides each month: the line trading that month wins, then the higher traded value. The universe ranks like the FTSE 350: the top 350 with investment trusts counted, then the trusts dropped (`universe.rank_with_trusts`). Their market caps come from `fetch --trust-caps`, run once. To correct a decision, add a row to `data/reference/listing_overrides.csv` (`symbol,decision,reason`, with decision `in` or `out`). The findings go to `data/lse_report.json`: match rate by year, names matching two lines, columns per year, members not in any list, and cap gaps over 25% (also in `data/lse_cap_gaps.csv`). Without the lists, ISIN country and `data/reference/aim_companies.*` are used instead. `clean.py` drops bad ticks: a move over 50% that comes back within 20% inside 3 sessions. They are recorded in `data/bad_ticks.parquet`.

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

## Sharemanager's backtests (the worker)

Every backtest asked for on sharemanager's strategy page runs here. Sharemanager only queues it; the worker claims it, runs it and posts the result back, all through one door, `backtest_api.php` on the N100 (claim, result, failure). The worker has no SSH, no database access and no login on the N100.

| File | What it does |
|---|---|
| `worker.py` | `uv run research worker`: polls `backtest_api`, one run at a time. A failure is posted with its reason and the next run goes ahead. Every run is logged in the test log as a hand-started one (`kind: research`, family `sm_<strategy>`), so it counts as a trial. |
| `from_rules.py` | Turns the rules snapshot in a claim into the engine's strategy, or refuses and names the rule it cannot translate. Its docstring lists what it covers. |
| `deploy/com.sharemanager.backtest-worker.plist` | The launchd entry. |

Settings are in `~/.config/sharemanager-research/worker.env`, mode 600 (the worker refuses any other mode):

```
SM_BACKTEST_API_URL=http://<n100 tailscale name>/backtest_api.php
SM_BACKTEST_API_TOKEN=<the same value as in /etc/sharemanager.env on the N100>
```

The token is never logged. The worker refuses to start unless `research validate --replay-targets` passed at the current, clean git commit (it reads `data/validation/run9_replay_check.json` and checks the run is in the test log), so run that after every pull. A run interrupted by a stop or a restart is posted as failed, never run again silently. Sharemanager refuses any run that ends on or after the holdout start, and the engine refuses it again.

The N100's own dealing rules are used for these runs: raw closes, a price on or before the day, at most 50 orders a rebalance, and the cost model asked for on the form (`t212` is the cost model run 9 was dealt with).

## The N100's definitions, copied exactly

- **6-month return** (`signals/base.py: n100_return_detail`, from `calc_momentum()` and `backfill_trend_statistics.php`): calendar months, not trading days. It runs from the first price on or after (date − 6 months) to the last price on or before the date, using each share's own prices, and only where the start is before the end. A share with less history than the window gets the return over the history it has.
- **Price basis** (`price_basis`): the N100 uses raw closes with no dividends, so `validate` runs `raw`. Research runs use `adjusted`.
- **Selection and fills** (read from `strategy_functions.php`): a candidate needs only a price on or before the date (`strategy_price_asof`), traded that day or not; a rebalance of more than 50 orders is refused whole; the two-closes-below exit reads rows that carry a stale close over a no-trade day; a holding more than 2% from target is topped up or trimmed, otherwise left alone; a buy the cash no longer covers is trimmed to fit. `validate` sets the first two (`require_traded_today: false`, `max_orders: 50`).
- **Order of orders:** `strategy_plan_orders` reads `strategy_targets` (primary key strategy, as_of_date, symbol) with no `ORDER BY`, so targets are planned in symbol order, then held shares that are no longer targets, also by symbol. The executor puts every sell before any buy (a stable `usort`). So cash runs out on the buy that is last alphabetically (on 2 Nov 2022 that was TRN, not the lowest-ranked DOCS), and the trim has no minimum order. `engine.n100_symbol_key` gives the order; FMP's `BT-A.L` is the N100's `BT.A`.
- **Market caps** (`clean.py`): pence read as pounds is fixed at the merge. A single-day value over 20 times the median of the 20 days around it is replaced by the previous day's (`data/cap_spikes.parquet`). Each series is then split at one-day steps of 70 to 140 times, and each segment is judged against the median of the 40 trading days before and after it. With both neighbours, ×100 or ×0.01 when the ratio to both is within 80 to 125 (or its inverse) and the segment is flat (largest under 3 times the median). A segment at either end has one neighbour: the ratio must be within 90 to 112 and the segment at least 20 days, and only the shorter end of a two-segment series moves. Everything else at a step is refused and left as it was; `data/cap_rescale_refused.parquet` and the data report give the ratio to each neighbour. A refused segment is then rescaled anyway if its median cap over price × shares outstanding (statements) is within 80 to 125 times, or its inverse, and flat (`data/cap_rescued_by_shares.parquet`); the refused file keeps the rest with `price_shares_ratio`. `data/reference/cap_overrides.csv` (`symbol,from,to,action,value,reason`; action `drop`, `multiply` or `price_x_shares`) holds case-by-case decisions for what no rule can fix, such as SDRY.L; every row applied is in the data report. The £500bn ceiling is a check on the result, not a rule inside the rescale: the data report fails the build if a kept symbol has a cap above £500bn, or above £300bn unless it is AZN, HSBA, SHEL, BP, ULVR, GSK, BATS, RIO, LLOY, BARC, DGE, REL, LSEG or BHP (`data/cap_ceiling_offenders.csv`), and it lists the top 20 caps per year.
- **UK companies only** (`master.py`): a line is kept only if its ISIN starts with GB, IE, JE, GG or IM (otherwise `overseas ISIN`, whatever the LSE lists say). With no ISIN it is kept only if FMP's profile country is one of those (`no ISIN, not UK`), and its profile currency must be sterling or empty (`no ISIN, not sterling`: Lukoil is GB on the profile and RUB); `research fetch --profile-country` fetches the profile again, once, for held lines with no ISIN, and the survivors are listed in the data report. A kept line whose market cap is not in pounds is out (`cap not in GBP`); nothing is converted. Those with a UK ISIN (Compass, IHG and the like) are listed apart for a case-by-case decision.
- **Two lines, one company:** the LSE row is given to every FMP line with that name, and the universe keeps one each month: the home-ISIN line, then the one trading, then the more heavily traded. `research lse-rows investec --year 2026` prints the raw LSE rows for a name.

### When `validate` still differs

`data/validation/run9_check.json` gives, for the first month where holdings, sells or quantities differ: both sides' fills side by side (date, quantity, price), the quantities that differ, what the research engine decided for each share and why (drift band, under the minimum order, trimmed for cash, refused), and our top 20 with returns and base dates. Two optional exports from the N100 make it exact:

- `n100_6mnth_YYYY-MM.csv` (`symbol,value`): the N100's own `6mnth` for that month's rebalance date.
- `run9_ours_orders.csv` is written on every `validate` run, in the layout of `n100_run9_orders.csv`, so the two fills lists can be joined.
- `first_value_the_n100_way`: the N100 writes each night's value after that night's fills, so its first value (98,723.75) is cash after the first build plus holdings at the decision close. Ours is still 100,000 on that date. The report computes ours the N100's way, which separates a timing difference from a sizing one.
- `first_month_quantities_differ.day_one`: for the first build, each share's fill date, quantity and price on both sides, our fill price over theirs, and the range of investable amounts that would produce the N100's quantities from our decision prices. One range that fits all fifteen means the sizing base differs by a single number (the implied cost multiplier says which constant); no range means the prices or the weights differ.
- `watch_shares`: HFD, KNOS and IHP, plus any share that differs, with both sides' fills and our rank at the first rebalances.
- `n100_stats_YYYY-MM-DD.csv` (`symbol,close_price,ma200,6mnth`): the N100's `statistics` rows for the rebalance date. Units are normalised (the N100 stores pence, ours are pounds; the baseline is what most shares agree on), so only real gaps and unit mismatches are listed, and a share whose screen result differs comes with the one-day 70 to 140 times steps in its price history. It names every share whose place in the trend screen (close above its 200-day average) differs between the N100 and the research engine, which is how a share like HFD that one side buys and the other doesn't gets explained.

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
