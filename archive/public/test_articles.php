<html>
	
	<?php include "../includes/share_functions.php";
	
	$articles=get_articles('AGA.L');

	print ("<ul>");
	foreach($articles as $article){
		print ("<li><a href=".$article["link"].">".$article["title"]."</a>");
	
	}
	print ("</ul>");
    ?>
</html>