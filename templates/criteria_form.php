<!DOCTYPE html>
<html>
  <head>
    <title>New Criteria</title>
  </head>
  <body>
    <?php
	/* An industry criterion is not a comparison of an indicator: it is "is"
	   or "is not" and a choice among the industries the industry map knows.
	   They are ticked, never typed, and criteria.php checks every one of
	   them on the server (share_screen.php) before anything is stored. */
	$industry_edit = isset($id) ? industry_criterion((int) $id) : null;
	$industry_posted = isset($industry_posted) && is_array($industry_posted) ? $industry_posted : null;
	$industry_operator = $industry_posted !== null ? (string) ($industry_posted['operator'] ?? '')
	                   : ($industry_edit !== null ? $industry_edit['operator'] : 'ind_not_in');
	$industry_chosen = $industry_posted !== null ? (array) ($industry_posted['industries'] ?? [])
	                 : ($industry_edit !== null ? $industry_edit['industries'] : []);
	?>
    <?php if (isset($industry_message) && $industry_message !== null): ?>
    <div class="alert alert-danger py-2">Not saved: <?= e($industry_message) ?></div>
    <?php endif ?>
    <?php if (screen_industry_ready() && isset($screen_id) && (!isset($id) || $industry_edit !== null)): ?>
    <form action="criteria.php" method="post" id="industry-criterion" class="mb-4">
        <fieldset>
		<input name="screen_id" value="<?= (int) $screen_id ?>" type="hidden"/>
		<?php if ($industry_edit !== null): ?><input name="id" value="<?= (int) $industry_edit['id'] ?>" type="hidden"/><?php endif ?>
		<h3>Industry</h3>
		<div class="mb-2">
			Industry
			<select name="industry_operator">
			<?php foreach (screen_industry_operators() as $value => $words): ?>
				<option value="<?= e($value) ?>"<?= $industry_operator === $value ? ' selected' : '' ?>><?= e($words) ?></option>
			<?php endforeach ?>
			</select>
			one of:
		</div>
		<div class="mb-2">
		<?php foreach (industry_names() as $k => $name): ?>
			<div class="form-check form-check-inline">
				<input class="form-check-input" type="checkbox" name="industries[]" id="industry-<?= (int) $k ?>"
				       value="<?= e($name) ?>"<?= in_array($name, $industry_chosen, true) ? ' checked' : '' ?>>
				<label class="form-check-label" for="industry-<?= (int) $k ?>"><?= e($name) ?></label>
			</div>
		<?php endforeach ?>
		</div>
		<?php $industry_unmapped = industry_unmapped_sectors(); ?>
		<?php if (count($industry_unmapped)): ?>
		<p class="small text-muted" id="industry-unmapped">
			Sector names the industry map does not know, among enabled shares:
			<?php foreach ($industry_unmapped as $i => $u): ?><?= $i ? '; ' : '' ?><?= e($u['name'] === '' ? '(no sector)' : $u['name']) ?> (<?= (int) $u['shares'] ?>)<?php endforeach ?>.
			A share with one of these is never kept by "is" and always kept by "is not".
		</p>
		<?php endif ?>
		<button type="submit" name="create_industry" class="btn btn-outline-primary">Save industry criterion</button>
		<button type="submit" name="cancel" class="btn btn-outline-primary">Cancel</button>
        </fieldset>
    </form>
    <?php endif ?>
    <?php if ($industry_edit === null): ?>
    <?php if (screen_industry_ready() && isset($screen_id)): ?><h3>Indicator</h3><?php endif ?>
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
		<div class="mb-3">
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
			<option value="=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='='): ?> selected <?php endif?>=<</option>
			<option value="!=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='!='): ?> selected <?php endif?>>!=</option>
			<option value=">=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='>='): ?> selected <?php endif?>>>=</option>
			<option value="<=" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='<='): ?> selected <?php endif?>><=</option>
			<option value="top_percent" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='top_percent'): ?> selected <?php endif?>>Top Percentile</option>
			<option value="top_n" <?php if (isset($criteria["operator"])&&$criteria["operator"]=='top_n'): ?> selected <?php endif?>>Best N (Constant)</option>
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
            <div class="mb-3">
                <button type="submit" name="create" class="btn btn-outline-primary">Submit</button>
		<button type="submit" name="cancel" class="btn btn-outline-primary">Cancel</button>
            </div>
        </fieldset>
    </form>
    <?php endif ?>
  </body>
 </html>
