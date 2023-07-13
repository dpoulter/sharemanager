<?php

    // configuration
	require("../includes/config.php");
	require("../includes/portfolio_functions.php");
  
	//Set default for year and month

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
		if(isset($_POST['month']))
				$month=$_POST['month'];
			else
				$month=trim(date('m'));
			if(isset($_POST['year']))
				$year=$_POST['year'];
			else
				$year=date('Y');
        
		if (isset($_POST["btnSubmit"])){
		   if(isset($_POST['id']))
				$id=$_POST['id'];
			if(isset($_POST['symbol']))
           $symbol=$_POST['symbol'];
         if(isset($_POST['trx_type']))
		   	$trx_type=$_POST['trx_type'];
		   if(isset($_POST['shares']))
		   	$shares=$_POST['shares'];
		   if(isset($_POST['price_paid']))
           $price_paid=$_POST['price_paid'];
         if(isset($_POST['commission']))
           $commission=$_POST['commission'];
         if(isset($_POST['purchase_date']))
	       $purchase_date=$_POST['purchase_date'];
           $errors='';
		  // echo "No of input: ".count($symbol);
          // for ($i=0;isset($symbol)&&$i<count($symbol);$i++){
           //validate submission
           if (empty($symbol)) {
             $errors=$errors . "Symbol must be entered <br/>";
            }
            else
            {
            	if (strpos($symbol[0],' ')>0)
             		$symbol=substr($symbol[0],0,strpos($symbol[0],' '));
				else {
					$symbol=$symbol[0];
				}
			 //print_r($symbol);
			// write_log('edit.php','Symbol='.$symbol);
             
             $quote = lookup($symbol);
			 
			 

            if ($quote==false){
              $errors=$errors . "Invalid Symbol: " . $symbol . "<br/>";
             };
            };
           if (empty($shares[0])) {
             $errors=$errors . "Number of shares must be entered <br />";
            };
			if ($shares[0]<=0) {
             $errors=$errors . "Number of shares transaction must be greater than zero <br />";
            };
			
           
           if (empty ($price_paid[0])) {
             $errors=$errors . "Price paid per share must be entered <br/>";
 
            };
           if (empty ($commission[0])) {
               $errors=$errors . "Commission paid must be entered <br/>"; 
               };
          //};
		  
		  
			
		   
          if (strlen($errors)>0) {
              echo "<b>Errors</b> </br>" . $errors ;
              echo "<a href='javascript:history.go(-1);'>Back</a>";
          }
          else
          {
           //Update Portfolio
           for ($i=0;isset($symbol)&&$i<1;$i++) {
            	
				//write_log('edit.php','Symbol[i]=='.$symbol[$i]);
            	
				
				//echo "Trx Type=".$trx_type[$i];
				if (isset($id[$i])){
									
					//get price paid and commmission before update
					 //write_log("edit.php","get price paid and commmission before update");
					 
					 $old_values=query("select price_paid price_paid,shares, commission from purchases where id=?",$id[$i]);
			
							
					//write_log('edit.php','Symbol=='.$symbol);
					
				  //  echo "update id=".$id[$i];
					query("update purchases set session_id=?,symbol=?,trx_type=?,shares=?,price_paid=?, commission=?, purchase_date=? where id=?",$_SESSION["id"],$symbol,$trx_type[$i],$shares[$i],$price_paid[$i], $commission[$i],$purchase_date[$i],$id[$i]);
					
					// write_log("edit.php","update_portfolio");
					 
					update_portfolio($symbol);
					
										
					//update cash balance
					$log_str="update cash balance - Commission=".$old_values[0]["commission"]+$commission[$i];
					write_log("edit.php",$log_str);
					$log_str="update cash balance - No of shares Prev:".$old_values[0]["shares"]." Previous Price:".$old_values[0]["price_paid"]." New Shares:".$shares[$i];				
					write_log("edit.php",$log_str);
					$amount=-1*$old_values[0]["shares"]*$old_values[0]["price_paid"]/100+($price_paid[$i]/100*$shares[$i])+ -1*$old_values[0]["commission"]+$commission[$i];
					
					update_cash_history($purchase_date[$i],$trx_type[$i],$amount);
				 }
				 else {
				 		//write_log('edit.php','Symbol=='.$symbol);
				 	
				       // echo "insert id=".$id[$i];
				
		       		query("INSERT into purchases (session_id,symbol,trx_type, shares,price_paid, commission, purchase_date) VALUES(?,?,?,?,?,?,?)", $_SESSION["id"],$symbol,$trx_type[$i],$shares[$i],$price_paid[$i], $commission[$i],$purchase_date[$i]);
					
					update_portfolio($symbol);
					
					//update cash balance
					//write_log("edit.php","update cash balance - price paid=".$price_paid[$i]." Commission=".$commission[$i]." Num of shares=".$shares[$i]);
					if($trx_type[$i]=='SELL')
						$amount = ($price_paid[$i]/100*$shares[$i]-$commission[$i]);
					else	
						$amount = ($price_paid[$i]/100*$shares[$i]+$commission[$i]);
					//write_log("edit.php","amount=".$amount);
					update_cash_history($purchase_date[$i],$trx_type[$i],$amount);
				}

				//Calculate Portfolio Value
				$session_id= $_SESSION["id"];
		
				//write_log("calc_performance.php","user_id=".$user["id"]);
				
				$current_date=new DateTime();
				
				write_log("calc_performance.php","current_date=".$current_date->format('Y-m-d'));
				
				//Delete existing records
				$result=query('delete from performance where session_id=? and performance_date >= ?',$session_id,$current_date->format('Y-m-d'));
				$result=query('delete from portfolio_performance where session_id=? and as_of_date >=?',$session_id,$current_date->format('Y-m-d'));
				
				
				
				$performance=calc_performance($current_date->format('Y-m-d'),$session_id);
					
				write_log("calc_performance.php","No of records returned by calc_performance=".count($performance));
				
					if (count($performance)>0){
						
						
						
						$insert=query('insert into performance(session_id,performance_date, total_value, total_profit, total_holding, cash) values (?,?,?,?,?,?)'
								,$session_id,$current_date->format('Y-m-d'),$performance['total_value'],$performance['total_profit'],
								$performance['total_value']+$performance['cash'],$performance['cash']);
								
								
						
						//Insert Active Positions
						foreach($performance['active_positions'] as $position){
								
							write_log("calc_performance.php","insert active share=".$position['symbol']);
							
							$insert=query('insert into portfolio_performance(active,as_of_date, commission, dividends, price, price_paid, price_sold,profit, profit_perc,profit_raw, qty_purchased, qty_sold, session_id, symbol, value, value_raw) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
										,'Y',$current_date->format('Y-m-d')
										,$position['commission'],$position['dividends'],$position['price'],$position['price_paid'],$position['price_sold'],$position['profit'],$position['profit_perc'],
										$position['profit_raw'],$position['qty_purchased'],$position['qty_sold'],$session_id,$position['symbol'],$position['value'],$position['value_raw']);
						}
				
						//Insert Inactive Positions
						foreach($performance['inactive_positions'] as $position){
							
							write_log("calc_performance.php","insert Inactive share=".$position['symbol']);
							
							$insert=query('insert into portfolio_performance(active,as_of_date, commission, dividends, price, price_paid, price_sold,profit, profit_perc,profit_raw, qty_purchased, qty_sold, session_id, symbol, value, value_raw) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
										,'N',$current_date->format('Y-m-d')
										,$position['commission'],$position['dividends'],$position['price'],$position['price_paid'],$position['price_sold'],$position['profit'],$position['profit_perc'],
										$position['profit_raw'],$position['qty_purchased'],$position['qty_sold'],$session_id,$position['symbol'],$position['value'],$position['value_raw']);
							
						}
					}
			
				//query shares held by user
				$rows= query("SELECT id,symbol,trx_type,shares,price_paid,commission,purchase_date from purchases where  session_id = ?", $_SESSION["id"]);
				// render edit portfolio form 
				render("edit_portfolio.php", ["title" => "Transactions", "positions" => $rows,"month" => date('m'), "year"=>date('Y')]);
			
			}
		  }
		}
		elseif (isset($_POST["btnEdit"])){
				//query shares held by user
				$rows= query("SELECT id,symbol,,shares,price_paid,commission,purchase_date from purchases where  session_id = ?", $_SESSION["id"]);
				// render edit portfolio form with editable rows
				render("edit_portfolio.php", ["title" => "Edit Transaction", "ptrx_typeositions" => $rows,"edits"=>$_POST["edit"],"month" => date('m'), "year"=>date('Y')]);
		
		
		}
		elseif (isset($_POST["btnAdd"])){
				
			render("add_transaction.php", ["title" => "Add Transaction"]);
				
		}
		
		else {
			
			//Query transactions for month and year
			$rows= query("SELECT id,symbol,trx_type,shares,price_paid,commission,purchase_date from purchases where  session_id = ? and month(purchase_date)=? and year(purchase_date)=?", $_SESSION["id"],$month,$year);
			// render edit portfolio form 
			render("edit_portfolio.php", ["title" => "Transactions", "positions" => $rows,"month" => $month, "year"=>$year]);
		}
    }
	elseif ($_SERVER["REQUEST_METHOD"] == "GET"){
		
		if (isset($_GET["id"])){
			$id=$_GET["id"];
			//write_log("edit.php","id=".$id);
		}
		if (isset($_GET["action"])) {
			$action=$_GET["action"];
			if ($action=="delete"){
			   // write_log("edit.php","action1=delete");
				//get price paid and commmission before update
				$old_values=query("select price_paid/100*shares value, commission from purchases where id=?",$id);
				//update cash balance
				//write_log("edit.php","Price Paid=".$old_values[0]["price_paid"]." COmmission=".$old_values[0]["commission"]);
				$amount=$old_values[0]["value"] + $old_values[0]["commission"];
				$date = date('Y-m-d');
				update_cash_history($date,'DELETE',$amount);
				query("delete from purchases where id=?",$id);
				//query shares held by user
				$rows= query("SELECT id,symbol,trx_type,shares,price_paid,commission,purchase_date from purchases where  session_id = ?", $_SESSION["id"]);
				// render edit portfolio form without editable row
				render("edit_portfolio.php", ["title" => "Transactions", "positions" => $rows,"month" => date('m'), "year"=>date('Y')]);
			}
			elseif ($action=="edit"){
				$rows= query("SELECT id,symbol,trx_type,shares,price_paid,commission,purchase_date from purchases where id=?",$id);
				render("edit_transaction.php", ["title" => "Edit Transaction", "position" => $rows[0]]);
			}
		}
		
	
		else
		{
			//query shares held by user
			$rows= query("SELECT id,symbol,trx_type,shares,price_paid,commission,purchase_date from purchases where  session_id = ? and month(purchase_date)=? and year(purchase_date)=?", $_SESSION["id"], trim(date('m')), date('Y'));
			// render edit portfolio form
			render("edit_portfolio.php", ["title" => "Transactions", "positions" => $rows, "month" => date('m'), "year"=>date('Y')]);
		}
	}
?>