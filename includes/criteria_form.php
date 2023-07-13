<!DOCTYPE html>
<html>
  <head>
    <title>New Criteria</title>
  </head>
  <body>
    <form action="criteria.php" method="post">
        <fieldset>
		<?php //get criteria record id criteria id was passed
			if (isset($id)){
				$rows=query("select * from screen_criteria where id=?",$id);
				$criteria=$rows[0];
			}
		?>
		<?php //get screen id if screen_id passed
			if (isset($screen_id)):?>
				<input name="screen_id"  value="<?=$screen_id?>" type="hidden"/>
		<?php endif ?>
		<div class="form-group">
		<input name="id" <?php if(isset($criteria["id"])): ?> value="<?=$criteria["id"]?>" <?php endif?> type="hidden"/>
		<input autofocus class="form-control" name="description" <?php if(isset($criteria["description"])): ?> value="<?= $criteria["description"] ?>" <?php endif?>  placeholder="Description" width="50%" type="text"/>
		<table class="table">
		<th><tr><td>Indicator</td><td>Operator</td><td>Constant</td><td>Indicator</td></tr></th>
		<tr>
		<td>
		<select name="indicator">
		<?php	
			  //get indicators
		        $indicators = query("SELECT indicator_id, name, description FROM screen_indicators");

			foreach($indicators as $indicator): ?>
				<option value="<?=$indicator['indicator_id']?>"<?php if (isset($criteria["indicator_id"])&&$indicator['indicator_id']==$criteria["indicator_id"]): ?> selected <?php endif?> ><?= $indicator["description"]?></option>
			<?php endforeach?>
		</select></td>	
	        <td>
		<select name="operator">
			<option value=">" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='>'): ?> selected <?php endif?>>></option>
			<option value="<" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='<'): ?> selected <?php endif?>><</option>
			<option value="=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='='): ?> selected <?php endif?>=</option>
			<option value="!=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='!='): ?> selected <?php endif?>>!=</option>
			<option value=">=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='>='): ?> selected <?php endif?>>>=</option>
			<option value="<=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='<='): ?> selected <?php endif?>><=</option>
			<option value="Top Percent" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='Top Percent'): ?> selected <?php endif?>>Top Percentile</option>
		</select>	
                <td><input name="first_operand" <?php if(isset($criteria["first_operand"])): ?> value="<?= $criteria["first_operand"] ?>" <?php endif?> type="text"/></td>
				<td>
				<select name="second_operand">
					<option value=""></option>
					<?php foreach($indicators as $indicator):?>
						<option value="<?=$indicator['name']?>"<?php if (isset($criteria["second_operand"])&&$indicator['name']==$criteria["second_operand"]): ?> selected <?php endif?>><?=$indicator["description"]?></option>
					<?php endforeach?>
               </select></td>	
		</tr>
		</table>
            </div>
            <div class="form-group">
                <button type="submit" name="create" class="btn btn-default">Submit</button>
		<button type="submit" name="cancel" class="btn btn-default">Cancel</button>
            </div>
        </fieldset>
    </form>
  </body>
 </html>
