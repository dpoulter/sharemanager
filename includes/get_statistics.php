<?php

    require_once("constants.php");
    include("functions.php");
	include("share_functions.php");
	
	log_job("get_statistics");

	$asOfDate=new DateTime();
    $asOfDate->sub(new DateInterval('P1D'));
	print_r( "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
	
	//Set Exchanges
	$exchanges = array("XLON");
	
	foreach ($exchanges as $exchange){
	    
		$_SESSION["exchange"]=$exchange;
	
		//write_log("get_statistics.php","Get statistics to calculate");
		
	    //Get statistics to calculate
		$indicators=get_stats_indicators();
		foreach ($indicators as $indicator){
	
			print_r( $indicator);
			//write_log("get_statistics.php","Indicator=".$indicator["name"]);
			
			//Get statistics for each symbol
			indicator_stats(date_format($asOfDate,'Y-m-d'),$indicator["name"],null);
			
		}	
		
		//write_log("get_statistics.php","Clear all Average statistics before As of date");
		
	    /* Clear all Average statistics before as of date.
	
	       The INDUSTRY insert below used to leave exchange null while this
	       delete matched on it, so industry rows were never cleared and every
	       run added another set: 5,704 rows for one date where there are 920
	       distinct ones. The insert now writes the exchange like the other
	       two, and this also clears any row that still has none, which is
	       exactly the rows that bug left behind - so the live table tidies
	       itself on the next run rather than needing a migration. Once those
	       are gone the "or exchange is null" arm matches nothing and can go. */
		query("delete from statistic_averages where date<=? and (exchange=? or exchange is null)",date_format($asOfDate,'Y-m-d'),$_SESSION["exchange"]);
			
		//write_log("get_statistics.php","Calculate Market Median for all statistics");
			
		//Calculate Market Median for all statistics
		$indicators=query("select name indicator from screen_indicators where enabled='Y'");
		foreach ($indicators as $indicator){
			$avg = query("SELECT x.indicator, x.value from statistics x, screen_indicators si,statistics y 
				where x.date=? and x.indicator=? and x.exchange=? and x.date=y.date and x.indicator=y.indicator and y.exchange=x.exchange and si.name=x.indicator and if(x.value=0,if(rank_zero='N',0,1),1)=1
				GROUP BY x.indicator,x.value HAVING SUM(SIGN(1-SIGN(y.value-x.value)))/COUNT(*) > .5
				LIMIT 1",date_format($asOfDate,'Y-m-d'),$indicator['indicator'],$_SESSION["exchange"]);
			
			if (count($avg)>0){
				write_log("get_statistics.php","insert into statistic_averages (date, category, indicator,type, value, exchange) values ('".date_format($asOfDate,'Y-m-d')."','".$avg[0]['indicator']."',"."'MARKET'".","."'MEDIAN'".",".$avg[0]['value'].",'".$_SESSION["exchange"]."')");
				
				if (!query("insert into statistic_averages (date, category, indicator,type, value, exchange) values (?,?,?,?,?,?)",date_format($asOfDate,'Y-m-d'),'MARKET',$avg[0]['indicator'],'MEDIAN',$avg[0]['value'],$_SESSION["exchange"]))
					write_log("get_statistics.php","Insert failed for indicator".$avg[0]['indicator']);
			}
		}
		
		write_log("get_statistics.php","Calculate Sector Medians for all statistics");
		
		/* Sector and industry medians are the comparison sets the relative
		   valuations on the quote page are worked out against, so funds are
		   left out of both sides of them: a trust must not drag the median
		   price/book of the companies it shares an industry with. A fund
		   sector contains nothing but funds, so it now has no median at all,
		   which is why calc_implied_valuation() skips funds outright. */

		//Calculate Sector Medians for all statistics
		$sectors=query('select distinct sector from stock_symbols where exchange=?',$_SESSION["exchange"]);
		foreach ($sectors as $sector){
			$indicators=query("select name from screen_indicators where enabled='Y'");
	   		foreach ($indicators as $indicator){
				$avg = query("SELECT x.indicator, x.value, ssx.sector from statistics x,screen_indicators si, statistics y , stock_symbols ssx, stock_symbols ssy
				where x.date=? and x.indicator=? and ssx.sector=? and ssx.exchange=? and x.date=y.date and x.indicator=y.indicator and si.name=x.indicator 
				and x.symbol=ssx.symbol and y.symbol=ssy.symbol and ssx.sector = ssy.sector and ssy.enabled='Y' and if(x.value=0,if(rank_zero='N',0,1),1)=1
				".not_fund_sql('ssx.symbol').not_fund_sql('ssy.symbol')."
				GROUP BY x.indicator,x.value, ssx.sector HAVING SUM(SIGN(1-SIGN(y.value-x.value)))/COUNT(*) > .5
				LIMIT 1",date_format($asOfDate,'Y-m-d'),$indicator['name'],$sector['sector'],$_SESSION["exchange"]);
				if(count($avg)>0) {
					query("insert into statistic_averages (date, category, indicator,type, value, sector,exchange) values (?,?,?,?,?,?,?)",date_format($asOfDate,'Y-m-d'),'SECTOR',$avg[0]['indicator'],'MEDIAN',$avg[0]['value'],$avg[0]['sector'],$_SESSION["exchange"]);
				}
			}
	 		
			
		}
	
	//Calculate Industry Medians for all statistics
		$industries=query('select distinct industry from stock_symbols where exchange=?',$_SESSION["exchange"]);
		foreach ($industries as $industry){
			$indicators=query("select name from screen_indicators where enabled='Y'");
	   		foreach ($indicators as $indicator){
				$avg = query("SELECT x.indicator, x.value, ssx.industry from statistics x,screen_indicators si, statistics y , stock_symbols ssx, stock_symbols ssy
				where x.date=? and x.indicator=? and ssx.industry=? and x.exchange=? and x.date=y.date and x.indicator=y.indicator and si.name=x.indicator 
				and x.symbol=ssx.symbol and y.symbol=ssy.symbol and ssx.industry = ssy.industry and ssx.enabled='Y' and if(x.value=0,if(rank_zero='N',0,1),1)=1
				".not_fund_sql('ssx.symbol').not_fund_sql('ssy.symbol')."
				GROUP BY x.indicator,x.value, ssx.industry HAVING SUM(SIGN(1-SIGN(y.value-x.value)))/COUNT(*) > .5
				LIMIT 1",date_format($asOfDate,'Y-m-d'),$indicator['name'],$industry['industry'],$_SESSION["exchange"]);
				if(count($avg)>0) {
					query("insert into statistic_averages (date, category, indicator,type, value, industry, exchange) values (?,?,?,?,?,?,?)",date_format($asOfDate,'Y-m-d'),'INDUSTRY',$avg[0]['indicator'],'MEDIAN',$avg[0]['value'],$avg[0]['industry'],$_SESSION["exchange"]);
				}
			}
	 		
			
		}
		
		//Calculate Price Valuation for each share
		$rows = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",$_SESSION["exchange"]);
		foreach($rows as $row) 
			calc_implied_valuation(date_format($asOfDate,'Y-m-d'),$row['symbol']);
		
		//Calculate Rank for each statistic
		write_log("get_statistics.php","Calculate Rank for each statistic");
		
		/* rank_funds says whether investment funds are ranked on this
		   indicator. It arrives with sql/fund_sectors.sql, so it is selected
		   as a constant until that has been applied - see fund_config_ready()
		   in share_functions.php. */
		$rank_funds_col = fund_config_ready() ? "ifnull(nullif(rank_funds,''),'Y')" : "'Y'";

	    $indicators=query("select name, if(rank_order='','DESC',rank_order) rank_order,
	                             ifnull(nullif(rank_negative,''),'NORMAL') rank_negative,
	                             ".$rank_funds_col." rank_funds
	                        from screen_indicators where enabled='Y' and calc_rank='Y'");

	    foreach ($indicators as $indicator){

			/* rank_order comes from the database and is interpolated below, so
			   it is checked against what it is allowed to be rather than
			   trusted. An unrecognised value falls back to the column default. */
			$rank_order = trim($indicator['rank_order']);
			if (!preg_match('/^value\s+(ASC|DESC)$/i', $rank_order)) {
				write_log("get_statistics.php",
				          "unexpected rank_order '".$rank_order."' for "
				          .$indicator['name'].", using value DESC");
				$rank_order = 'value DESC';
			}

			/* A negative valuation ratio is not a cheap share. pe,
			   price_sales_ratio and price_book_ratio rank value ASC, so a
			   loss-maker or a company with negative book value sorted ahead of
			   every profitable one: on the FTSE 350 for 2026-11-13 the six best
			   P/E percentiles were all negative.

			   EXCLUDE leaves them unranked, so percentile stays null and the
			   score queries fall back to the neutral 50 they already use. That
			   is the honest answer: a negative ratio does not mean cheap, and
			   it does not mean expensive either - it means the ratio has no
			   meaning for this company, so it should neither help nor hurt.
			   LAST is available for anyone who would rather a loss-maker scored
			   worst than neutral. */
			$negatives = strtoupper(trim($indicator['rank_negative']));
			$negative_filter = ($negatives === 'EXCLUDE') ? " AND value >= 0" : "";
			$order_by = ($negatives === 'LAST') ? "(value < 0), ".$rank_order : $rank_order;

			/* rank_funds='N' leaves investment funds out of this indicator's
			   ranking. The filter goes in the innermost select, which is both
			   the set that gets ranked and the set @rownum counts, so a fund
			   gets no percentile and is not in the denominator either: a
			   company is ranked against companies and nothing else. The raw
			   value stays in statistics and still shows on the quote page.

			   The momentum indicators keep rank_funds='Y'. A price series is a
			   price series whoever owns the assets behind it. */
			$fund_filter = (strtoupper(trim($indicator['rank_funds'])) === 'N')
			             ? not_fund_sql('s.symbol') : "";

		 	$sql1 = "SELECT
			 symbol,
			 IF(value = 0, IF(rank_zero = 'N', IF(rank_order = 'DESC', 0, @rownum), `rank`), `rank`) AS `rank`,
			 ROUND(100 - (IF(value = 0, IF(rank_zero = 'N', @rownum, `rank`), `rank`) / @rownum * 100)) AS percentile
		  FROM
			 (
				SELECT
				   CASE WHEN @prev_value = value THEN @rownum
						WHEN @prev_value := value THEN @rownum := @rownum + 1
						ELSE @rownum := @rownum + 1
				   END AS `rank`,
				   value,
				   symbol,
				   rank_zero,
				   rank_order
				FROM
				   (
					  SELECT
						 *
					  FROM
						 (
							SELECT
							   value,
							   symbol,
							   si.rank_zero,
							   si.rank_order
							FROM
							   statistics s,
							   screen_indicators si
							WHERE
							   s.indicator = si.name
							   AND date = '".date_format($asOfDate, 'Y-m-d')."'
							   AND indicator = '".$indicator['name']."'
							   ".$negative_filter."
							   ".$fund_filter."
						 ) s,
						 (SELECT @rownum := 0, @prev_value := NULL) r
					  ORDER BY ".$order_by."
				   ) AS st
			 ) AS result";
	     
			/* Clear the previous run's ranks for this date and indicator before
			   working out the new ones. The write below only touches the rows the
			   ranking query returns, so any row it no longer returns kept the
			   stat_rank and percentile it was given earlier. That is exactly the
			   rows EXCLUDE drops: a negative P/E stopped being ranked but kept the
			   percentile it had when negatives still sorted first, so it went on
			   scoring as the cheapest share in the index and the fix looked like
			   it had not worked. Nulling them first means an unranked row reads as
			   unranked, which is what the score queries already treat as the
			   neutral 50.

			   Scoped to date and indicator, matching the update below, which is
			   not scoped by exchange either. */
			query("update statistics set stat_rank=null, percentile=null"
			      ." where date=? and indicator=?",
			      date_format($asOfDate,'Y-m-d'), $indicator['name']);

	     	write_log("get_statistics.php","query=$sql1");

			write_log("get_statistics.php","Execute Query for ".$indicator['name']." and AsofDate=".date_format($asOfDate, 'Y-m-d'));
	     
	        $ranks = query($sql1);

			write_log("get_statistics.php","Query executed...");
	        
	        if (is_array($ranks)){
	        	foreach($ranks as $rank){

					write_log("get_statistics.php","Update statistics...");
	        	
	        		//query("insert into ranks(symbol,indicator,rank,percentile) values(?,?,?,?,?)",$rank['symbol'],$indicator['name'],$rank['rank'],$rank['percentile']);	
					query("update statistics set stat_rank=?, percentile=? where symbol=? and indicator=? and date=? ",$rank['rank'],$rank['percentile'],$rank['symbol'],$indicator['name'],date_format($asOfDate,'Y-m-d'));	
	        	}
	        }
	        
	   }       
	   
		//Calculate Momentum Rank for each share
		write_log("get_statistics.php","Calculate Momentum Rank for each share");
		
		$sql      =   "SELECT symbol,  stat_rank,round(100-(stat_rank/@rownum*100)) percentile FROM (SELECT case when @prev_value=value then @rownum when @prev_value:=value then @rownum := @rownum + 1 else @rownum:=@rownum+1 end AS stat_rank, value, symbol, rank_zero FROM (select round(sum(if(percentile is null,50,percentile))) as value,symbol,si.rank_zero FROM statistics s, screen_indicators si where s.indicator=si.name and date= ? and indicator in ('3mnth','6mnth','12mnth') and s.exchange=? group by symbol,si.rank_zero) s, (SELECT @rownum :=0,@prev_value := NULL) r  ORDER BY value DESC) as result";

	    write_log("get_statistics.php","Query= ".$sql);

		$scores=query($sql,date_format($asOfDate,'Y-m-d'),$_SESSION['exchange']);

		write_log("get_statistics.php","Asof Date= ".date_format($asOfDate,'Y-m-d').", exchange=".$_SESSION['exchange']);
		write_log("get_statistics.php","No of rows returned= ".count($scores));

		/* Without this a second run for the same date appends a whole
		   fresh set instead of replacing it: two runs for 2026-11-13 left
		   700 momentum_score rows where there should be 350. Scoped to
		   this date, indicator and exchange, so other days and other
		   exchanges are untouched. */
		query("delete from statistics where date=? and indicator=? and exchange=?",
		      date_format($asOfDate,'Y-m-d'),'momentum_score',$_SESSION['exchange']);

		foreach($scores as $score){

			write_log("get_statistics.php","insert into statistics...symbol=".$score['symbol'].", value=".$score['percentile']);

			query("insert into statistics (symbol,date, indicator,value,exchange) values (?,?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'momentum_score',$score['percentile'],$_SESSION['exchange']);
		}
		
		//Calculate Value Ranking
		write_log("get_statistics.php","Calculate Value Ranking");
		
		/* not_fund_sql() leaves investment funds out: no value_score row is
		   written for a fund at all, rather than one built from the neutral 50
		   a missing percentile falls back to. See sql/fund_sectors.sql. */
		$sql      =   "SELECT symbol,  stat_rank,round(100-(stat_rank/@rownum*100)) percentile FROM (SELECT case when @prev_value=value then @rownum when @prev_value:=value then @rownum := @rownum + 1 else @rownum:=@rownum+1 end AS stat_rank, value, symbol, rank_zero FROM (select round(sum(if(percentile is null,50,percentile))) as value,symbol,si.rank_zero FROM statistics s, screen_indicators si where s.indicator=si.name and date= ? and s.exchange=? and indicator in ('shareholder_yield','pe','price_sales_ratio','price_book_ratio','enterprise_value_to_ebitda','price_free_cash_flow_per_share') ".not_fund_sql('s.symbol')."  group by symbol,si.rank_zero) s, (SELECT @rownum :=0,@prev_value := NULL) r  ORDER BY value DESC) as result";
		
		/* $sql      =   "
		SELECT  symbol, round(sum(if(percentile is null,50,percentile))/6) as percentile
		FROM statistics r where date=? and indicator in ('shareholder_yield','pe','price_sales_ratio','price_book_ratio','enterprise_value_to_ebitda','price_free_cash_flow_per_share') 
		group by symbol";
	*/
	
		$scores = query($sql,date_format($asOfDate,'Y-m-d'),$_SESSION['exchange']);
		query("delete from statistics where date=? and indicator=? and exchange=?",
		      date_format($asOfDate,'Y-m-d'),'value_score',$_SESSION['exchange']);

		foreach($scores as $score){
				query("insert into statistics (symbol,date, indicator,value,exchange) values (?,?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'value_score',$score['percentile'],$_SESSION['exchange']);
	
		}
		
		//Calculate Quality Ranking
		write_log("get_statistics.php","Calculate Quality Ranking");
		$sql      =   "SELECT symbol, stat_rank,round(100-(stat_rank/@rownum*100)) percentile FROM (SELECT case when @prev_value=value then @rownum when @prev_value:=value then @rownum := @rownum + 1 else @rownum:=@rownum+1 end AS stat_rank, value, symbol, rank_zero FROM (select round(sum(if(percentile is null,50,percentile))) as value,symbol,si.rank_zero FROM statistics s, screen_indicators si where s.indicator=si.name and date= ? and category = 10  and s.exchange=? ".not_fund_sql('s.symbol')." group by symbol,si.rank_zero) s, (SELECT @rownum :=0,@prev_value := NULL) r  ORDER BY value DESC) as result";
		
		/* $sql      =   "
		SELECT  symbol, round(sum(if(percentile is null,50,percentile))/1) as percentile
		FROM statistics r where date=? and indicator in ('roe_ttm') 
		group by symbol";
	*/
		$scores = query($sql,date_format($asOfDate,'Y-m-d'),$_SESSION['exchange']);
		write_log("get_statistics.php","Number of scores for quality ranking: ".count($scores));
		query("delete from statistics where date=? and indicator=? and exchange=?",
		      date_format($asOfDate,'Y-m-d'),'quality_score',$_SESSION['exchange']);

		foreach($scores as $score){
				query("insert into statistics (symbol,date, indicator,value,exchange) values (?,?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'quality_score',$score['percentile'],$_SESSION['exchange']);
	
		}
		
		
	
		
		//Calculate Overall Ranking
		write_log("get_statistics.php","Calculate Overall Ranking");
				/* This joined statistics to screen_indicators on si.name = s.indicator,
		   but the three indicators it sums - value_score, momentum_score and
		   quality_score - are scores this job writes, not rows in
		   screen_indicators, so the join matched nothing and overall_score was
		   never written. The join only ever carried si.rank_zero, which is
		   grouped by and then used in no condition, so dropping it changes
		   nothing else. The value and quality queries above keep their join:
		   theirs select real indicators, and the quality one needs
		   si.category. */
		/* Funds are filtered here too. They still have a momentum_score, so
		   without this a fund would get an overall score of its momentum plus
		   the neutral 50 twice - which is how trusts reached the overall top
		   ten in the first place. */
		$sql      =   "SELECT symbol, stat_rank,round(100-(stat_rank/@rownum*100)) percentile FROM (SELECT case when @prev_value=value then @rownum when @prev_value:=value then @rownum := @rownum + 1 else @rownum:=@rownum+1 end AS stat_rank, value, symbol FROM (select round(sum(if(value is null,50,value))) as value,symbol FROM statistics s where date= ? and indicator in ('value_score','momentum_score','quality_score') and s.exchange=? ".not_fund_sql('s.symbol')."  group by symbol) s, (SELECT @rownum :=0,@prev_value := NULL) r  ORDER BY value DESC) as result";
		
		 /*$sql      =   "SELECT  symbol, round(sum(if(value is null,50,value))/3) as percentile
		FROM statistics r where date=? and indicator in ('value_score','momentum_score','quality_score') 
		group by symbol";
		*/
		$scores = query($sql,date_format($asOfDate,'Y-m-d'),$_SESSION['exchange']);
		query("delete from statistics where date=? and indicator=? and exchange=?",
		      date_format($asOfDate,'Y-m-d'),'overall_score',$_SESSION['exchange']);

		foreach($scores as $score){
				query("insert into statistics (symbol,date, indicator,value,exchange) values (?,?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'overall_score',$score['percentile'],$_SESSION['exchange']);
	
		}
		
	
		//Record the date the statistics are current as of. This is a separate
		//marker from the log_job("get_statistics") row above, which records when
		//the job ran: log_job writes NOW, this writes the as of date, and the
		//pages that display statistics need the as of date.
		write_log("get_statistics.php","insert record into jobs");
		
		query("insert into jobs (job_name, job_date) values (?,?)",'get_statistics_asof',date_format($asOfDate,'Y-m-d H:i:s'));
	}
 ?>
