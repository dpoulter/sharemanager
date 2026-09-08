<!doctype html>
<html lang="en">
<?php
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.
?>

    <head>
        
        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        
       
       <!--  <link href="/css/bootstrap.css" rel="stylesheet"/>
        <link href="/css/bootstrap-theme.min.css" rel="stylesheet"/>
        -->
       
        <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="/css/bootstrap.min.css" >
    <link href="/css/styles1.css" rel="stylesheet"/>
    <link rel="stylesheet" href="/css/fontawesome.min.css">
    
 <!-- <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js"></script> -->
     <script src="/js/jquery-3.3.1.min.js"></script>   
      <!--   <script src="/js/popper.js"></script>  -->
         <script src="/js/bootstrap.bundle.min.js"></script> 
         <script src="/js/bloodhound.min.js"></script> 
        <script src="/js/typeahead.jquery.js"></script> 
        <script src="/js/scripts.js"></script>  
  
        <?php if (isset($title)): ?>
            <title>Share Portfolio Manager <?= htmlspecialchars($title) ?></title>
        <?php else: ?>
            <title>Share Portfolio Manager</title>
        <?php endif ?>

        

</head>

    <body>
    
    <section class="bg-white pt-5">
    
    <div class="container mt-5" >
    	 	
    	
    	
        
     <!--       <div id="middle" class="navigation">
              <ul class="nav nav-pills">
                 <li role="presentation" class="active"><a href="index.php">Home</a></li>
                <li class="dropdown"><a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" role="button" aria-expanded="false">Portfolio<span class="caret"></span></a>
			<ul class="dropdown-menu" role="menu">
				<li><a href="performance.php">Overview</a></li>
				<li><a href="edit.php">Transactions</a></li>
				<li><a href="dividends.php">Dividends</a></li>
				<li><a href="topup.php">Deposit Cash</a></li>
				<li><a href="cash_history.php">Cash History</a></li>
			</ul>
		</li>
     -->
		<!--<li class="dropdown"><a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" role="button" aria-expanded="false">Screens<span class="caret"></span></a>
          		<ul class="dropdown-menu" role="menu">
				<li><a href="screen_list.php">List Screens</a></li>
				</ul>
        </li>          		
		-->
        <!--
		<li role="presentation"><a href="logout.php">Log Out</a></li>
             </ul>
            </div>
        -->
            </p>
            
<nav class="navbar navbar-expand-lg fixed-top navbar-dark bg-primary">
  <div class="container-fluid">
  <a class="navbar-brand" href="index.php">Share Manager</a>
  <!-- Without a toggler the collapse above never opens, so on a phone the whole
       navigation was simply invisible. -->
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
          data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown"
          aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navbarNavDropdown">
    <ul class="navbar-nav me-auto">
      <li class="nav-item active">
        <a class="nav-link" href="index.php">Home <span class="visually-hidden">(current)</span></a>
      </li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          Portfolio
        </a>
        <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
          <a class="dropdown-item" href="performance.php">Overview</a>
          <a class="dropdown-item" href="edit.php">Transactions</a>
          <a class="dropdown-item" href="dividends.php">Dividends</a>
          <a class="dropdown-item" href="topup.php">Deposit Cash</a>
          <a class="dropdown-item" href="cash_history.php">Cash History</a>
        </div>
      </li>
      <li class="nav-item active">
        <a class="nav-link" href="screen_list.php">Screens<span class="visually-hidden">(current)</span></a>
      </li>
      <li class="nav-item active">
        <a class="nav-link" href="logout.php">Log Out<span class="visually-hidden">(current)</span></a>
      </li>
    </ul>
  <form id="search" class="d-flex ms-auto" action="quote.php" method="post">
		<input class="form-control me-2 typeahead" type="search" name="symbol" placeholder="Enter Symbol" aria-label="Search">
		<button type="submit" class="btn btn-outline-light">Lookup</button>
  </form>
  </div>
  </div>
</nav>
