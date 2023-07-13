<?php

    // configuration
    require("../includes/config.php");
  

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
		   if (isset($_POST['dividend_id']))
				$dividend_id=$_POST['dividend_id'];
			if (isset($_POST['symbol']))
           $symbol=$_POST['symbol'];
         if (isset($_POST['dividend_date']))
           $dividend_date=$_POST['dividend_date'];
         if (isset($_POST['amount']))
           $amount=$_POST['amount'];
           $errors='';
		   //echo "No of input: ".count($symbol);
           //for ($i=0;isset($symbol)&&$i<count($symbol);$i++){
           //validate submission
           if (empty($symbol)) {
             $errors=$errors . "Symbol must be entered <br/>";
            }
            else
            {
              $share_symbol=substr($symbol[0],0,strpos($symbol[0],' '));
              //$quote = lookup($share_symbol);

            //if ($quote==false){
            //  $errors=$errors . "Invalid Symbol: " . $symbol . "<br/>";
            // };
            };
           if (empty($dividend_date[0])) {
             $errors=$errors . "Dividend Date must be entered<br />";
            };
           if (empty ($amount[0])) {
             $errors=$errors . "Amount must be entered. <br/>";
 
            };
          
         // };
          if (strlen($errors)>0) {
              echo "<b>Errors</b> </br>" . $errors ;
              echo "<a href='javascript:history.go(-1);'>Back</a>";
          }
          else
          {
           //Update Dividends
           write_log("dividends.php","Update Dividends");
         //   for ($i=0;isset($symbol)&&$i<count($symbol);$i++) {
				if (isset($dividend_id[0])){
				    //echo "update id=".$dividend_id[$i];
					
					//update_cash_history
					
					
					write_log("dividends.php","dividend_id=".$dividend_id[0]);
					
					$dividends= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  dividend_id=?",$dividend_id[0]);
					
					write_log("dividends.php","update_cash_history negative");
					
					update_cash_history($dividend_date[0],'DIVIDEND',-1*$dividends[0]['amount']);										
					
					write_log("dividends.php","update_cash_history positive");
					
					update_cash_history($dividend_date[0],'DIVIDEND',$amount[0]);
				    
					write_log("dividends.php","update dividend record");
					
					query("update dividends set session_id=?,symbol=?,dividend_date=?,amount=? where dividend_id=?",$_SESSION["id"],$symbol[0],$dividend_date[0],$amount[0],$dividend_id[0]);
					
					
				 }
				 else {
				        //echo "insert id=".$dividend_id[$i];
				    write_log("dividends.php","INsert Dividends");    
				    $symbol=$symbol[0];

					write_log("dividends.php","Symbol = ".$symbol);    
				
		       		query("INSERT into dividends (session_id,symbol,dividend_date,amount) VALUES(?,?,?,?)", $_SESSION["id"],$symbol,$dividend_date[0],$amount[0] );
		       		
						//update cash history		       		
		       		update_cash_history($dividend_date[0],'DIVIDEND',$amount[0]);
				}
			//};
			//query dividends 
			write_log("dividends.php","query dividends"); 
			
			$rows= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  session_id = ?", $_SESSION["id"]);
			// render edit Dividends form without editable rows
			render("edit_dividends.php", ["title" => "Edit Dividends", "dividends" => $rows,"month" => $month, "year"=>$year]);
			
			}
		}
		elseif (isset($_POST["btnEdit"])){
				//query dividends 
				write_log("dividends.php","btn Edit"); 
				$rows= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  session_id = ?", $_SESSION["id"]);
				// render edit Dividends form with editable rows
				render("edit_dividends.php", ["title" => "Edit Dividends", "dividends" => $rows,"month" => $month, "year"=>$year]);
		}
		elseif (isset($_POST["btnAdd"])){
				 
				render("add_dividend.php", ["title" => "Add Dividend"]);
		}
		else {
			
			//query dividends 
			write_log("dividends.php","query dividends for month and year"); 
			
			$rows= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  session_id = ? and month(dividend_date)=? and year(dividend_date)=?", $_SESSION["id"],$month,$year);
			// render edit Dividends form with editable rows
			render("edit_dividends.php", ["title" => "Edit Dividends", "dividends" => $rows,"month" => $month, "year"=>$year]);		
		}
    }
	elseif ($_SERVER["REQUEST_METHOD"] == "GET"){
		
		write_log("dividends.php","Request Method is GET"); 
		

		
		if (isset($_GET["dividend_id"])){
			$dividend_id=$_GET["dividend_id"];
			write_log("dividends.php","dividend_id=".$dividend_id);
		}
		if (isset($_GET["action"])) {
			$action=$_GET["action"];
			if ($action=="delete"){
			    write_log("dividends.php","action1=delete");
				//update cash history
				$dividends= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  dividend_id=?",$dividend_id);
				update_cash_history($dividends[0]['dividend_date'],'DIVIDEND',-1*$dividends[0]['amount']);
				
				//delete dividend record
				query("delete from dividends where dividend_id=?",$dividend_id);
				
				//query dividends
				$rows= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  session_id = ?", $_SESSION["id"]);
				// render edit Dividends form without editable rows
				render("edit_dividends.php", ["title" => "Edit Dividends", "dividends" => $rows,"month" => date('m'), "year"=>date('Y')]);
			}
			elseif($action=="edit") {
				$dividends= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  dividend_id=?",$dividend_id);
				render("edit_dividend.php", ["title" => "Edit Dividend", "dividends" => $dividends[0]]);
			}
		}
	
		else
		{
			//query dividends 
			$rows= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  session_id = ? and month(dividend_date)=? and year(dividend_date)=?", $_SESSION["id"], trim(date('m')), date('Y'));
			// render edit Dividends form without editable rows
			render("edit_dividends.php", ["title" => "Edit Dividends", "dividends" => $rows, "month" => date('m'), "year"=>date('Y')]);
		}
	}
	else
		{
			//query dividends 
			
			write_log("dividends.php","Query all dividends"); 
			
			
			$rows= query("SELECT dividend_id,symbol,dividend_date,amount from dividends where  session_id = ?", $_SESSION["id"]);
			// render edit Dividends form without editable rows
			render("edit_dividends.php", ["title" => "Edit Dividends", "dividends" => $rows,"month" => date('m'), "year"=>date('Y')]);
		}
?>
