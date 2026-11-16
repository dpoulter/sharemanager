<?php

	/**
	 * Nightly regulatory announcements: fetch, store, score, and write the
	 * sentiment indicators.
	 *
	 *   php includes/fetch_rns.php [YYYY-MM-DD]
	 *
	 * Replaces includes/fetch_news.php in refresh_all.sh. Runs after the
	 * prices, because the as of date is the newest date in historical_prices
	 * and the indicators are written against it.
	 *
	 * Which route: the company pages, one per symbol. The brief asked for
	 * whichever needs fewest requests and offered a daily all-announcements
	 * index as the cheaper option, but its own note said that page served
	 * five links without JavaScript, and this session has no route to
	 * investegate.co.uk to check whether there is a full-day index behind it.
	 * 350 company pages at one every two seconds is twelve minutes, which is
	 * an acceptable price for a route that is known to list a company's
	 * announcements. If somebody confirms a full-day index, this loop is the
	 * only thing that changes: everything after rns_announcement_links() is
	 * already indifferent to where the links came from.
	 *
	 * Being a good guest, in order of how much it matters:
	 *
	 *   robots.txt is fetched and honoured before anything else, and the run
	 *   refuses outright if the paths it needs are disallowed;
	 *
	 *   one request every RNS_FETCH_INTERVAL seconds, measured from the end
	 *   of the last one, with no parallelism anywhere;
	 *
	 *   a User-Agent that says what this is, so whoever wants it to stop
	 *   knows who to ask;
	 *
	 *   429 and 5xx back off and then give up for the night;
	 *
	 *   the announcement limit is a full stop. The run ends, what is left
	 *   stays unfetched and tomorrow picks it up. The first month's backfill
	 *   may take several nights. That is the correct outcome, not a problem
	 *   to be solved: never log in, never work round the limit.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	include("rns.php");

	log_job("fetch_rns");

	$requestedDate = (PHP_SAPI === 'cli' && isset($argv[1])) ? $argv[1] : null;

	$exchanges = array("XLON");

	foreach ($exchanges as $exchange) {

		$_SESSION["exchange"] = $exchange;

		$asOf = resolve_asof_date($requestedDate, $exchange);

		if ($asOf === null) {
			$why = ($requestedDate === null)
			     ? "no prices have been loaded for ".$exchange
			     : "there are no ".$exchange." prices for '".$requestedDate."'";
			fwrite(STDERR, "fetch_rns: refusing to run, ".$why."\n");
			write_log("fetch_rns", "refusing to run: ".$why);
			exit(2);
		}

		print_r("Asofdate=".$asOf."\r\n");

		/* Ask permission before asking for anything else. */
		if (!rns_robots_allows('/company/') || !rns_robots_allows('/announcement/')) {
			fwrite(STDERR, "fetch_rns: robots.txt disallows the pages this needs; not running\n");
			write_log("fetch_rns", "level=WARN robots.txt disallows /company/ or"
			                      ." /announcement/; nothing fetched");
			exit(3);
		}

		/* How far back to look, worked out PER COMPANY.
		
		   It used to be one window for the whole run: a day before the
		   newest announcement in the table. The first live run stopped after
		   73 companies, which left the newest row at yesterday - so the
		   next run would have looked back one day for the other 277 and
		   their October announcements would never have been fetched at all.
		   An interrupted backfill could never finish, and nothing would have
		   said so.
		
		   Per company, a company with nothing stored (or no tidm yet, which
		   means it has never been successfully read) gets the full backfill
		   and one with a history gets a day before its own newest. The run
		   can then be interrupted as often as it likes and each company
		   picks up where it left off. */
		$company_newest = [];
		foreach (query("select symbol, max(date(published_at)) d
		                  from rns_announcements group by symbol") as $row) {
			if ($row['d'] !== null) { $company_newest[$row['symbol']] = (string) $row['d']; }
		}

		$backfill_from = date('Y-m-d', strtotime($asOf . ' -' . RNS_BACKFILL_DAYS . ' day'));

		$window_for = function ($symbol) use ($company_newest, $backfill_from) {
			if (!isset($company_newest[$symbol])) { return $backfill_from; }
			$since = date('Y-m-d', strtotime($company_newest[$symbol] . ' -1 day'));
			/* Never look further forward than the backfill would: a company
			   whose newest announcement is from last year still only needs
			   the last RNS_BACKFILL_DAYS. */
			return max($since, $backfill_from);
		};

		print_r("looking back to ".$backfill_from." for a company with nothing stored,"
		        ." or a day before its own newest\r\n");
		write_log("fetch_rns", "window per company, backfill from=".$backfill_from
		                      ." to=".$asOf." companies_with_history=".count($company_newest));

		$symbols = query("select symbol from stock_symbols
		                   where enabled='Y' and exchange=? order by symbol", $exchange);

		$counts = ["companies" => 0, "listed" => 0, "new" => 0, "skipped" => 0,
		           "duplicate" => 0, "corrected" => 0, "undated" => 0, "thin" => 0,
		           "refused" => 0, "dropped" => 0, "retried" => 0];
		$refusals = 0;      // the site saying no: 429, 403, the limit page
		$dropped  = 0;      // the connection failing: status 0
		$stop     = null;
		$started  = microtime(true);
		$last     = 0.0;

		/* One request, throttled from the end of the previous one. Returns
		   [status, body] or null when the run must stop. $kind says which
		   sort of page was asked for, so the limit check knows what the page
		   failing to carry would make it a limit page. */
		$fetch = function ($url, $kind = 'announcement')
		         use (&$last, &$refusals, &$dropped, &$counts, &$stop) {

			$attempt = function ($url) use (&$last) {
				$wait = RNS_FETCH_INTERVAL - (microtime(true) - $last);
				if ($wait > 0) { usleep((int) round($wait * 1000000)); }
				$answer = rns_http_get($url);
				$last = microtime(true);
				return $answer;
			};

			[$status, $body] = $attempt($url);

			/* Status 0 is no answer at all: the connection failed, timed out
			   or was reset. That is not the site refusing - on the first
			   live run three of them in a row stopped the night after 73
			   companies, and the site answered normally a minute later - so
			   it is retried once after the back-off and counted separately.
			   Its own limit is higher, because a network that drops one
			   request will drop two, and the run should still give up rather
			   than hammer a dead link 350 times. */
			if ($status === 0) {
				$dropped++;
				$counts['dropped']++;
				write_log("fetch_rns", "level=WARN no answer from ".$url
				                      ." (connection failed); retrying once"
				                      ." (".$dropped." of ".RNS_MAX_DROPPED.")");
				if (RNS_BACKOFF_SECONDS > 0) { sleep((int) min(30, RNS_BACKOFF_SECONDS)); }

				[$status, $body] = $attempt($url);
				$counts['retried']++;

				if ($status === 0) {
					write_log("fetch_rns", "level=WARN still no answer from ".$url);
					return null;
				}

				write_log("fetch_rns", "the retry of ".$url." answered ".$status);
			}

			if ($status === 429 || $status === 403) {
				$refusals++;
				$counts['refused']++;
				write_log("fetch_rns", "level=WARN ".$status." from ".$url
				                      ."; backing off (".$refusals." of ".RNS_MAX_REFUSALS.")");
				/* A longer wait than the throttle, growing with each refusal.
				   A site answering 429 wants less traffic, not the same
				   traffic a second later. */
				$backoff = RNS_BACKOFF_SECONDS * $refusals;
				if ($backoff > 0) { sleep(min(30, $backoff)); }
				return null;
			}

			if ($status >= 500) {
				/* The server is having a bad time rather than telling us to
				   go away. Backed off like a refusal but counted with the
				   dropped connections: both are "the other end is not
				   working", and neither is Investegate saying no. */
				$dropped++;
				$counts['dropped']++;
				write_log("fetch_rns", "level=WARN ".$status." from ".$url
				                      ."; backing off (".$dropped." of ".RNS_MAX_DROPPED.")");
				$backoff = RNS_BACKOFF_SECONDS * $dropped;
				if ($backoff > 0) { sleep(min(30, $backoff)); }
				return null;
			}

			if ($status !== 200 || $body === '') {
				$dropped++;
				$counts['dropped']++;
				write_log("fetch_rns", "level=WARN status ".$status." from ".$url);
				return null;
			}

			$limit = rns_limit_page($body, $kind);
			if ($limit !== null) {
				$stop = "the site showed its announcement limit ('".$limit."')";
				return null;
			}

			return [$status, $body];
		};

		foreach ($symbols as $row) {

			if ($stop !== null
			    || $refusals >= RNS_MAX_REFUSALS
			    || $dropped >= RNS_MAX_DROPPED) { break; }

			$symbol = $row['symbol'];
			$links  = [];
			$tidm   = null;
			$from   = $window_for($symbol);

			foreach (rns_tidm_candidates($symbol) as $candidate) {

				$answer = $fetch(rns_company_url($candidate), 'listing');
				if ($answer === null) { break; }

				$found = rns_announcement_links($answer[1]);
				if (count($found)) { $links = $found; $tidm = $candidate; break; }

				write_log("fetch_rns", $symbol." as '".$candidate
				                      ."' listed no announcements");
			}

			if ($stop !== null) { break; }

			$counts['companies']++;

			if (!count($links)) { continue; }

			if ($tidm !== null) { rns_remember_tidm($symbol, $tidm); }

			foreach ($links as $link) {

				if ($stop !== null) { break; }

				$counts['listed']++;

				if ($link['published_at'] === null) {
					/* No date, no row. An announcement filed under the wrong
					   day would be put beside a price move it had nothing to
					   do with, which is worse than not filing it. */
					$counts['undated']++;
					write_log("fetch_rns", "no date beside ".$link['url']);
					continue;
				}

				/* The listing is newest first, so the first one older than
				   this company's own window means the rest are too. */
				if (substr($link['published_at'], 0, 10) < $from) { break; }

				/* Already on record. Still handed to rns_store(), which
				   compares the listing's date with the stored one and
				   corrects it if they differ - that is how the rows stored
				   by the version that dated every announcement a row out get
				   repaired, by being seen again. No body is fetched: the text
				   has not changed, only what was believed about when it was
				   published. */
				if (count(query("select id from rns_announcements where announcement_id=?",
				                $link['announcement_id']))) {

					$was = rns_store([
						"announcement_id" => $link['announcement_id'],
						"symbol"          => $symbol,
						"published_at"    => $link['published_at'],
					]);

					if ($was === 'corrected') { $counts['corrected']++; }
					else                      { $counts['duplicate']++; }
					continue;
				}

				$title = rns_title_from_slug($link['title_slug']);

				/* The skip decision is made from the slug, before the request
				   - which is the whole point of having one. Most of an RNS
				   feed is a buyback's daily paperwork, and reading it to find
				   out it was paperwork would be 350 wasted requests a night. */
				if (rns_skip_reason($title) !== null) {
					rns_store(["announcement_id" => $link['announcement_id'],
					           "symbol" => $symbol, "tidm" => $link['tidm'],
					           "source" => $link['source'], "category" => '',
					           "title" => $title, "published_at" => $link['published_at'],
					           "url" => $link['url'], "body" => '']);
					$counts['skipped']++;
					continue;
				}

				$answer = $fetch($link['url']);
				if ($answer === null) { continue; }

				/* The announcement, then the site's own AI summary out of it,
				   then the wire's boilerplate off the end. The last of those
				   is the same several hundred words on every announcement
				   and is paid for in tokens as well as in space. */
				$body = rns_strip_rns_footer(rns_strip_ai_summary(rns_body($answer[1])));

				if (mb_strlen($body) < RNS_MIN_BODY_CHARS) {
					/* Not an announcement. Not stored, so a later run tries
					   again rather than keeping a page of furniture as the
					   text of a company's results. */
					$counts['thin']++;
					write_log("fetch_rns", "only ".mb_strlen($body)." characters of text at "
					                      .$link['url']."; left for another run");
					continue;
				}

				$stored = rns_store([
					"announcement_id" => $link['announcement_id'],
					"symbol"          => $symbol,
					"tidm"            => $link['tidm'],
					"source"          => $link['source'],
					"category"        => '',
					"title"           => $title,
					/* The listing row's date, and only that. Reading one out
					   of the document dated a trading update to the end of a
					   buyback programme in 2027. */
					"published_at"    => $link['published_at'],
					"url"             => $link['url'],
					"body"            => $body,
				]);

				if ($stored === 'new')      { $counts['new']++; }
				elseif ($stored === 'skipped') { $counts['skipped']++; }
				else                        { $counts['duplicate']++; }
			}
		}

		$elapsed = round(microtime(true) - $started);

		print_r("read ".$counts['companies']." companies, ".$counts['listed']
		        ." announcements listed, ".$counts['new']." stored, "
		        .$counts['skipped']." skipped, ".$counts['duplicate']." already had, "
		        .$counts['corrected']." dates corrected, "
		        .$counts['dropped']." connection failures, "
		        .$elapsed."s\r\n");
		write_log("fetch_rns", "companies=".$counts['companies']
		                      ." listed=".$counts['listed']
		                      ." new=".$counts['new']
		                      ." skipped=".$counts['skipped']
		                      ." duplicate=".$counts['duplicate']
		                      ." corrected=".$counts['corrected']
		                      ." undated=".$counts['undated']
		                      ." thin=".$counts['thin']
		                      ." refused=".$counts['refused']
		                      ." dropped=".$counts['dropped']
		                      ." retried=".$counts['retried']
		                      ." seconds=".$elapsed);

		if ($stop !== null) {
			print_r("stopped early: ".$stop."\r\n");
			write_log("fetch_rns", "level=WARN stopped early because ".$stop
			                      ."; the rest are left for the next run");
		} elseif ($refusals >= RNS_MAX_REFUSALS) {
			print_r("stopped early after ".$refusals." refusals\r\n");
			write_log("fetch_rns", "level=WARN stopped after ".$refusals
			                      ." refusals; the rest are left for the next run");
		} elseif ($dropped >= RNS_MAX_DROPPED) {
			print_r("stopped early after ".$dropped." connection failures\r\n");
			write_log("fetch_rns", "level=WARN stopped after ".$dropped
			                      ." connection failures; the rest are left for the next run."
			                      ." This is a network or a server problem rather than a refusal.");
		}

		/* Scoring. Capped, so a backfill that finds a thousand announcements
		   is several nights rather than one surprising bill. */
		$scored = rns_score_unscored();

		print_r("scored ".$scored['scored']." of ".$scored['considered']." announcements, $"
		        .number_format($scored['cost'], 4)."\r\n");
		write_log("fetch_rns", "level=INFO rns_scoring"
		                      ." scored=".$scored['scored']
		                      ." considered=".$scored['considered']
		                      ." failed=".$scored['failed']
		                      ." model=".NEWS_SCORE_MODEL
		                      ." input_tokens=".$scored['input_tokens']
		                      ." output_tokens=".$scored['output_tokens']
		                      ." cost_usd=".number_format($scored['cost'], 6)
		                      ." capped=".($scored['capped'] ? 'yes' : 'no'));

		if ($scored['capped']) {
			write_log("fetch_rns", "level=WARN more than ".RNS_SCORE_MAX_PER_RUN
			                      ." announcements were waiting; the rest are left for"
			                      ." the next run");
		}

		/* The price moves, stored on the row so the page does not work them
		   out while somebody waits. After scoring, because a new
		   announcement should arrive with its move, and over the whole
		   window rather than only tonight's rows: the move runs to the
		   latest close, so every announcement's move changed today. */
		$moves = rns_refresh_price_moves($asOf, $exchange);
		print_r("worked out ".$moves['written']." price moves ("
		        .$moves['unknown']." without a baseline)\r\n");

		/* The indicators, from whatever is scored now. */
		$written = rns_write_indicators($asOf, $exchange);
		print_r("wrote ".$written." sentiment statistics for ".$asOf."\r\n");
		write_log("fetch_rns", "indicators written=".$written." asof=".$asOf);
	}
