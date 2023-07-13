
     <body>
	<form method="post" action="screen_criteria.php">
        <h3>Criteria List</h3>
        <div>
	<table class="table">
	<tr>
	<th>Description</th>
	</tr>
	<tr>
        <?php

 //query screens
    $rows = query("SELECT id, description from screen_criteria");
    // if we found history
        if (count($rows) >= 1){
            $criteria = [];
            foreach ($rows as $row){
                    $criteria[] = [
                        "id" => $row["id"],
                        "description" => $row["description"]
                    ];
            }
	    //display criteria
			foreach($criteria as $item){
                echo '<tr><td><input type="checkbox" value="'. $item["id"] .'" name="select[]"></input></td>';
				echo  '<td>' . $item["description"] . '</td></tr>'; 
	    }
	}
      

?>

 </table>
</div>
<input type="hidden" value=<?= $_POST["screen_id"] ?> name="screen_id"></input>
<input type="submit" name="Select" value="Select"/>
<input type="submit" name="Cancel" value="Cancel"/>
</form>
</body>
