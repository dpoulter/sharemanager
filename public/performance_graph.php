<?php // content="text/plain; charset=utf-8"
require_once ('../includes/simple_graph.php');
//The browser loads this through <img src>, so anything that goes wrong
//here can only appear as a broken icon. Turn a failure into a legible
//picture instead.
graph_error_trap(800, 400);
//jpgraph is a third-party library that is not vendored in this repository.
//Without it these requires fatal and the <img> renders as a broken icon, so
//fall back to the small SVG renderer when the library is not installed.
if (jpgraph_available()) {
	require_once ('jpgraph-4.2.0/src/jpgraph.php');
	require_once ('jpgraph-4.2.0/src/jpgraph_line.php');
	require_once ('jpgraph-4.2.0/src/jpgraph_bar.php');
	require_once( "jpgraph-4.2.0/src/jpgraph_date.php" );
}
require_once ('../includes/Prices.php');

function creategraph($session_id,$timespan){
	
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
	
	
	 // Width and height of the graph
	$width = 800; $height = 400;
	
	/* Read both series before picking a renderer, so "is there anything to
	   draw" is answered once and whichever renderer runs sees the same data.
	   A session with no holdings yields two empty arrays. The SVG path has
	   always handled that, but jpgraph throws on an empty series ("Empty input
	   data array", error 25121) - and inside an <img> an exception is a broken
	   icon, not a message. So the check sits above the branch rather than
	   inside the fallback, which is only reached on a host without jpgraph. */
	$profit=new Prices();
	$profit->performance($session_id,$startdate,$enddate);
	$holding=new Prices();
	$holding->total_holding($session_id,$startdate,$enddate);

	$profitvalues  = is_array($profit->graphvalues)  ? $profit->graphvalues  : array();
	$holdingvalues = is_array($holding->graphvalues) ? $holding->graphvalues : array();

	if (count($profitvalues)==0 && count($holdingvalues)==0) {
		graph_message_svg("No performance history available",$width,$height);
		return;
	}

	if (!jpgraph_available()) {
		simple_multi_line_svg(array(
			array("label"=>"Profit","dates"=>$profit->graphdates,"values"=>$profitvalues),
			array("label"=>"Total","dates"=>$holding->graphdates,"values"=>$holdingvalues)
		),$width,$height);
		return;
	}
	 
	// Create a graph instance
	$graph = new Graph($width,$height);
	 
	// Specify what scale we want to use,
	// int = integer scale for the X-axis
	// int = integer scale for the Y-axis
	$graph->SetScale('datlin');
	
	$graph->xaxis->SetLabelAngle(90);
	
	
	$graph->SetMargin(50,40,30,80);
	
	$graph->xaxis->scale->SetDateFormat( 'd-M-y' );
		
	/* Both series, from the data already read above. This used to build two
	   more Prices objects and re-run the same two queries for numbers it
	   already had. The per-series skip is belt and braces: today both series
	   come from the same rows of the performance table so they are empty or
	   populated together, and the guard above has already returned in the
	   empty case - but an empty array reaching LinePlot is the "Empty input
	   data array" raise, and that is too sharp an edge to leave uncovered. */
	foreach (array(array('Profit', $profit->graphdates,  $profitvalues),
	               array('Total',  $holding->graphdates, $holdingvalues)) as $series) {
		list($legend, $dates, $ydata) = $series;
		if (count($ydata) == 0) { continue; }

		$xdata = array();
		foreach ($dates as $date) { array_push($xdata, $date->getTimestamp()); }

		$lineplot = new LinePlot($ydata, $xdata);
		$lineplot->SetLegend($legend);
		$graph->Add($lineplot);
	}

	// Display the graph
	$graph->Stroke();

  
  }

if (isset($_GET["session_id"])&&isset($_GET["timespan"])){
	creategraph($_GET["session_id"],$_GET["timespan"]);
}
 
?>