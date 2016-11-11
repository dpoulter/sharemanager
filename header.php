<!DOCTYPE html>
<?php
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.
?>
<html>

    <head>

        <link href="/css/bootstrap.css" rel="stylesheet"/>
        <link href="/css/bootstrap-theme.min.css" rel="stylesheet"/>
        <link href="/css/styles1.css" rel="stylesheet"/>

        <?php if (isset($title)): ?>
            <title>Share Portfolio Manager <?= htmlspecialchars($title) ?></title>
        <?php else: ?>
            <title>Share Portfolio Manager</title>
        <?php endif ?>

        

</head>

    <body>
    	
    	
				
    	 <div class="container">
    	 	
    	
    	
		<div id="top">
             <h2>Share Portfolio Manager</h2>
            </div>
      
       

            
        <script src="/js/jquery-1.10.2.min.js"></script>
        <script src="/js/bootstrap.min.js"></script>
		 <script src="/js/bloodhound.min.js"></script>
               <!-- https://github.com/twitter/typeahead.js/ -->

        <script src="/js/typeahead.jquery.js"></script>
        <script src="/js/scripts.js"></script>
       
        
            <div id="middle" class="navigation">
              <ul class="nav nav-pills">
                 <li role="presentation" class="active"><a href="index.php">Home</a></li>
                <li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Portfolio<span class="caret"></span></a>
			<ul class="dropdown-menu" role="menu">
				<li><a href="performance.php">Overview</a></li>
				<li><a href="edit.php">Transactions</a></li>
				<li><a href="dividends.php">Dividends</a></li>
				<li><a href="topup.php">Deposit Cash</a></li>
				<li><a href="cash_history.php">Cash History</a></li>
			</ul>
		</li>
		<li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Screens<span class="caret"></span></a>
          		<ul class="dropdown-menu" role="menu">
				<li><a href="screen_list.php">List Screens</a></li>
				</ul>
        </li>          		
		

		<li role="presentation"><a href="logout.php">Log Out</a></li>
             </ul>
            </div>
            </p>


