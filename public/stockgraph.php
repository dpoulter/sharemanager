<?php // content="text/plain; charset=utf-8"
require_once ('jpgraph-4.2.0/src/jpgraph.php');
require_once ('jpgraph-4.2.0/src/jpgraph_line.php');
require_once ('jpgraph-4.2.0/src/jpgraph_bar.php');
require_once( "jpgraph-4.2.0/src/jpgraph_date.php" );
require_once ('../includes/Prices.php');

function creategraph($symbol,$timespan){
	
	switch ($timespan) {
					case '1m': $interval='P1M';
						
						break;
						
					case '3m': $interval='P3M';
						
						break;
					
					case '6m': $interval='P6M';
						
						break;
					
					case '1y': $interval='P12M';
					
						break;
					
					case '2y': $interval='P24M';
					
						break;
					
					case '5y': $interval='P60M';
					
						break;
					
					case '10y': $interval='P120M';
					
						break;
					
					default: $interval='P6M';
						
						break;
				}

	$enddate= new DateTime();
	$startdate= new DateTime();
	$startdate->sub(new DateInterval($interval));
	
	$graphprices=new Prices(); 
	$graphprices-> graph($symbol,$startdate,$enddate);
	
	$dates=array();
	$ydata=array();
	$dates = $graphprices->graphdates;
	
	$xdata=array();
	foreach($dates as $date){
		array_push($xdata,$date->getTimestamp());
	}
	//print_r($xdata);
	$ydata = $graphprices->graphvalues;
	//print_r($ydata);
	
	 
	 // Width and height of the graph
	$width = 600; $height = 330;
	 
	// Create a graph instance
	$graph = new Graph($width,$height);
	 
	// Specify what scale we want to use,
	// int = integer scale for the X-axis
	// int = integer scale for the Y-axis
	$graph->SetScale('datlin');
	
	$graph->xaxis->SetLabelAngle(90);
	
	
	$graph->SetMargin(40,40,30,80);
	
	$graph->xaxis->scale->SetDateFormat( 'd-M-y' );
	 
	// Setup a title for the graph
	//$graph->title->Set('Sunspot example');
	 
	// Setup titles and X-axis labels
	//$graph->xaxis->title->Set('(year from 1701)');
	 
	// Setup Y-axis title
	//$graph->yaxis->title->Set('(# sunspots)');
	 
	// Create the linear plot
	$lineplot=new LinePlot($ydata,$xdata);
	 
	// Add the plot to the graph
	$graph->Add($lineplot);
	 
	// Display the graph
	$graph->Stroke();

  
  }

if (isset($_GET["symbol"])&&isset($_GET["timespan"])){
	creategraph($_GET["symbol"],$_GET["timespan"]);
}
 
?>