
        <body>
	<form method="post" action="criteria_list.php">
        <h3>Criteria List</h3>
        <div>
	<table class="table">
	<tr>
	<th>Delete</th><th>Description</th>
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
                echo '<tr><td><input type="checkbox" value="'. $item["id"] .'" name="delete[]"></input></td>';
				echo  '<td>' . $item["description"] . '</td></tr>'; 
	    }
	}
      

?>

 </table>
</div>
<input type="submit" name="Delete" value="Delete" class="btn btn-outline-primary"/>
<input type="submit" name="New" value="New" class="btn btn-outline-primary"/>

</form>
</body>
