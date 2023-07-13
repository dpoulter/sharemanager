<?php

    /**
     * strategies.php
     *
     * Implement Share Strategies.
     */

    require_once("constants.php");
	//include("functions.php");

    /**
     * Backing Winners
     */
    function backing_winners(){
		
	   //Set Strategy Id
	   $strategy_id=1;
    
		//first insert shares into strategy shares 
		//write_log("strategies.php","first insert shares into strategy shares ");
		
		/*$shares = query("SELECT symbol, sum(if(trx_type='BUY',shares,0)-if(trx_type='SELL',shares,0)) shares FROM purchases where session_id = ? group by symbol ", $_SESSION["id"]);
		foreach ($shares as $share){
			//check if we still have the shares in stock
			//write_log("strategies.php","check if we still have the shares in stock");
			
				if ($share["shares"]>0){
					//check if the shares are in the strategy
					//write_log("strategies.php","check if the shares are in the strategy: symbol=".$share["symbol"].", strategy_id=$strategy_id");
					
					$exists = query("select symbol from strategy_shares where session_id= ? and symbol=? and strategy_id=?",$_SESSION["id"],$share["symbol"],$strategy_id);
					//write_log("strategies.php","Number of rows=".count($exists));
					
						if (count($exists)==0){
							//shares not in strategy so add
							//write_log("strategies.php","shares not in strategy so add");
							
							query("insert into strategy_shares (symbol, status, session_id,strategy_id) values (?,?,?,?)",$share["symbol"],'0',$_SESSION["id"],$strategy_id);
						}
				}
				else{
					// remove from strategy because shares not in stock
					//write_log("strategies.php","remove from strategy because shares not in stock");
					
					query("delete from strategy_shares where session_id= ? and symbol=? and strategy_id=?",$_SESSION["id"],$share["symbol"],$strategy_id);
				}
		}*/
		/***************************************************************************************/
		/*  Share Classification                                                                 
		/***************************************************************************************/
		//write_log("strategies.php","Share Classification     ");
		
		//go through each share with status 0 (not classified)
		$shares = query("select symbol,classification from strategy_shares where session_id = ? and strategy_id=?", $_SESSION["id"],$strategy_id);
		foreach ($shares as $share){
			//get current share price
			$asOfDate=new DateTime();
    		$asOfDate->sub(new DateInterval('P1D'));
			$current_price_query=query("select price from historical_prices hp1 where date=(select max(date) from historical_prices hp2 where symbol=hp1.symbol) and symbol=?",$share["symbol"]);
			$current_price=$current_price_query[0]["price"];
			//write_log("strategies.php","symbol=".$share["symbol"]);
			//get purchase price
			$share_price=query("select purchase_date,price from purchases p, historical_prices hp where hp.symbol=p.symbol and hp.date=p.purchase_date and trx_type='BUY' and p.symbol=? and session_id=? and purchase_date=(select max(pu.purchase_date) from purchases pu where pu.trx_type='BUY' and pu.session_id=p.session_id and pu.symbol=p.symbol)",$share["symbol"],$_SESSION["id"]);
			if (count($share_price)>0){
				$price_paid=$share_price[0]["price"];
				$purchase_date=$share_price[0]["purchase_date"];
				//Get peak price
				$peak_share_price=query("select max(price) peak_price from historical_prices where symbol=? and date > ?",$share["symbol"],$purchase_date);
				$peak_price=$peak_share_price[0]["peak_price"];
				
				//write_log("strategies.php","current_price =$current_price ,price_paid=$price_paid,peak_price=$peak_price,purchase_date=$purchase_date");
				//Calculate gains and losses
				if (isset($current_price)&&isset($price_paid)&&isset($peak_price)){
					$purch_gainloss_percent = round(($current_price - $price_paid)/$price_paid,2); 
					$peak_gainloss_percent = round(($current_price - $peak_price)/$peak_price,2);
					//write_log("strategies.php","purch_gainloss_percent=$purch_gainloss_percent ,peak_gainloss_percent=$peak_gainloss_percent");
					//determine classification based on purchase gain 
					if ($purch_gainloss_percent>=0.2&&$purch_gainloss_percent <0.3){
							if ($share["classification"]=="NORM")
								//update status_date and classification
								query("update strategy_shares set status=?,classification=?,status_date=?, purch_gainloss_percent=?,peak_gainloss_percent=? where symbol=? and strategy_id=?","1.0","MINI",date('Y-m-d'),$purch_gainloss_percent,$peak_gainloss_percent,$share["symbol"],$strategy_id);
							else	
								//just update purchase gain/loss and peak loss
								query("update strategy_shares set purch_gainloss_percent=?,peak_gainloss_percent=? where symbol=? and strategy_id=?",$purch_gainloss_percent,$peak_gainloss_percent,$share["symbol"],$strategy_id);
					}
					elseif ($purch_gainloss_percent>=0.3){
						if ($share["classification"]=="MINI")
						//status changes from MINI to SUPER so set status date as date of change
							query("update strategy_shares set status=?,classification=?,status_date=?, purch_gainloss_percent=?,peak_gainloss_percent=? where symbol=? and strategy_id=?","1.0","SUPER",date('Y-m-d'),$purch_gainloss_percent,$peak_gainloss_percent,$share["symbol"],$strategy_id);
						else
							//just update purchase gain/loss and peak loss
							query("update strategy_shares set  purch_gainloss_percent=?,peak_gainloss_percent=? where symbol=? and strategy_id=?",$purch_gainloss_percent,$peak_gainloss_percent,$share["symbol"],$strategy_id);
					}
					else
						// status changes from NULL to NORM so set status date as date of change
						if (empty($share["classification"]))
							query("update strategy_shares set status=?,classification=?,status_date=?, purch_gainloss_percent=?,peak_gainloss_percent=? where symbol=? and strategy_id=?","1.0","NORM",date('Y-m-d'),$purch_gainloss_percent,$peak_gainloss_percent,$share["symbol"],$strategy_id);
						else
							//just update purchase gain/loss and peak loss
							query("update strategy_shares set purch_gainloss_percent=?,peak_gainloss_percent=? where symbol=? and strategy_id=?",$purch_gainloss_percent,$peak_gainloss_percent,$share["symbol"],$strategy_id);
				}
			}
		}
		/******************************************************/
		/* Set Buy/Sell Flags
		/******************************************************/
		//write_log("strategies.php","Set Buy/Sell Flags   ");
		
		//Get superstar shares
		$shares = query("select symbol,status,status_date, purch_gainloss_percent,peak_gainloss_percent from strategy_shares where classification='SUPER' and session_id = ? and strategy_id=?", $_SESSION["id"],$strategy_id);
		foreach ($shares as $share){
			//get original purchase and new purchases of this share
			$orig_purch=query("select min(purchase_date) purchase_date,sum(shares) shares from purchases p where trx_type='BUY' and symbol=? and session_id=? and purchase_date<=?",$share["symbol"],$_SESSION["id"],$share["status_date"]);
			$new_purch=query("select sum(shares) qty from purchases p where trx_type='BUY' and symbol=? and session_id=? and purchase_date>?",$share["symbol"],$_SESSION["id"],$orig_purch[0]["purchase_date"]);
			//get sales of this share
			$new_sales=query("select sum(shares) qty from purchases p where trx_type='SELL' and symbol=? and session_id=? and purchase_date>?",$share["symbol"],$_SESSION["id"],$orig_purch[0]["purchase_date"]);
			
			if ($share["status"]=='1.0' && $share["purch_gainloss_percent"]>=0.4)
					//Check to see if we have made a purchase greater than the original quantity. first get original qty
					if ($new_purch[0]["qty"]>=$orig_purch[0]["shares"])
						//we have purchased an equal quantity already so set buy sell flag to null
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'2.0',null,$_SESSION["id"],$share["symbol"],$strategy_id);
					else
						//need to purchase equal qty so set buy sell flag to BUY
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'2.0','BUY',$_SESSION["id"],$share["symbol"],$strategy_id);
					
			elseif ($share["status"]=='2.0' && $share["peak_gainloss_percent"]<=-0.15)
					//Need to sell half of holding so check if sales of half of holding have been made
					if ($new_sales[0]["qty"]/2>=$orig_purch[0]["shares"])
						//half of holding already been sold so set sell flag to null
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'2.1',null,$_SESSION["id"],$share["symbol"],$strategy_id);
					else
						//set sell flag 
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'2.1','SELL',$_SESSION["id"],$share["symbol"],$strategy_id);
			
			elseif ($share["status"]=='2.1' && $share["peak_gainloss_percent"]<=-0.3)
					//need to sell rest of holdings but check if we have sold already
					if ($new_sales[0]["qty"]>=$orig_purch[0]["shares"])
						//sold shares already
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'2.2',null,$_SESSION["id"],$share["symbol"],$strategy_id);
					else
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'2.2','SELL',$_SESSION["id"],$share["symbol"],$strategy_id);
			
		}
		//Get Ministar shares
		$shares = query("select symbol,status, status_date, purch_gainloss_percent,peak_gainloss_percent from strategy_shares where classification='MINI' and session_id = ?  and strategy_id=?", $_SESSION["id"],$strategy_id);
		foreach ($shares as $share){
			//get original purchase and new purchases of this share
			$orig_purch=query("select min(purchase_date) purchase_date,sum(shares) shares from purchases p where trx_type='BUY' and symbol=? and session_id=? and purchase_date<=?",$share["symbol"],$_SESSION["id"],$share["status_date"]);
			$new_purch=query("select sum(shares) qty from purchases p where trx_type='BUY' and symbol=? and session_id=? and purchase_date>?",$share["symbol"],$_SESSION["id"],$orig_purch[0]["purchase_date"]);
			//get sales of this share
			$new_sales=query("select sum(shares) qty from purchases p where trx_type='SELL' and symbol=? and session_id=? and purchase_date>?",$share["symbol"],$_SESSION["id"],$orig_purch[0]["purchase_date"]);
			
			if ($share["status"]=='1.0' && $share["peak_gainloss_percent"]<=-0.07)
					//Need to sell half of holding so check if sales of half of holding have been made
					if ($new_sales[0]["qty"]/2>=$orig_purch[0]["shares"])
						//sold shares already
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'1.1',null,$_SESSION["id"],$share["symbol"],$strategy_id);
					else
						//set sell flag
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'1.1','SELL',$_SESSION["id"],$share["symbol"],$strategy_id);
			
			elseif ($share["status"]=='1.1' && $share["peak_gainloss_percent"]<=-0.14)
					//need to sell rest of holdings but check if we have sold already
					if ($new_sales[0]["qty"]>=$orig_purch[0]["shares"])
						//sold shares already
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'1.2',null,$_SESSION["id"],$share["symbol"],$stategy_id);
					else
						//set sell flag
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'1.2','SELL',$_SESSION["id"],$share["symbol"],$strategy_id);
			
		}
		//Get Normal shares
		$shares = query("select symbol,status,status_date, purch_gainloss_percent,peak_gainloss_percent from strategy_shares where classification='NORM' and session_id = ? and strategy_id=?", $_SESSION["id"],$strategy_id);
		foreach ($shares as $share){
			//get original purchase and new purchases of this share
			$orig_purch=query("select min(purchase_date) purchase_date,sum(shares) shares from purchases p where trx_type='BUY' and symbol=? and session_id=? and purchase_date<=?",$share["symbol"],$_SESSION["id"],$share["status_date"]);
			$new_purch=query("select sum(shares) qty from purchases p where trx_type='BUY' and symbol=? and session_id=? and purchase_date>?",$share["symbol"],$_SESSION["id"],$orig_purch[0]["purchase_date"]);
			//get sales of this share
			$new_sales=query("select sum(shares) qty from purchases p where trx_type='SELL' and symbol=? and session_id=? and purchase_date>?",$share["symbol"],$_SESSION["id"],$orig_purch[0]["purchase_date"]);
			
			if ($share["status"]=='1.0' && ($share["purch_gainloss_percent"]<=-0.08||$share["peak_gainloss_percent"]<=-0.1))
					//Need to sell all
					if ($new_sales[0]["qty"]>=$orig_purch[0]["shares"])
						//sold shares already
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'1.1',null,$_SESSION["id"],$share["symbol"],$strategy_id);
					else
						query("update strategy_shares set status=?,buy_sell_flag=? where session_id=? and symbol=? and strategy_id=?",'1.1','SELL',$_SESSION["id"],$share["symbol"],$strategy_id);
			
			
		}
		
    }

  	//Get share symbols for a strategy
	function get_strategy_shares($strategy_id){
		$shares=[];
		$rows = query("SELECT symbol from strategy_shares where strategy_id=? and session_id=?",$strategy_id,$_SESSION["id"]);
    // if we found rows
     foreach($rows as $row)
     		array_push($shares,["symbol"=>$row["symbol"]]);   
     		
     return $shares;
    }	

?>
