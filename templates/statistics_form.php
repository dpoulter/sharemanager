<!DOCTYPE html>
<html>
  <head>
    <title>Statistics Form</title>
  </head>
 <style>
.field label {
    float: left;
    width: 30%;
    text-align: right;
    padding-right: 10px;
    margin: 5px 0px 5px 0px;
  }
  .field input {
    width: 70%;
    margin: 0px;
  }
</style>
  <body>
    <h3>Calculate Statistics</h3>
    <form action="statistics.php" method="post" class="form-inline">
        <fieldset>
            <div class="input-group">
		<input autofocus  id="asofdate" size="10" name="asOfDate" value=<?php echo date("d-m-Y");?>  type="text"/>
                <button type="submit" class="btn btn-default">Calculate</button>
	    	<ul class="nav nav-tabs" id="myTab" data-tabs="tabs">
			<?php $i=0?>
			<?php foreach ($category_indicators as $category_indicator): ?>
			<?php write_log("statistics_form.php","category name=".$category_indicator["category"]["name"]); ?>
      		<li role="presentation" <?php if ($i==0): write_log('statistics_form.php','class=active')?> class="active" <?php endif; ?>><a data-toggle="tab" href="#<?= $category_indicator["category"]["name"] ?>"><?= $category_indicator["category"]["description"] ?></a></li>
			<?php $i++; endforeach; ?>
   		</ul>
	    <div class="tab-content">
		<?php $i=0; foreach ($category_indicators as $category_indicator): ?>
  		<div class="tab-pane<?php if ($i==0): write_log('statistics_form.php','active');?> active<?php endif;?>" id="<?= $category_indicator["category"]["name"]?>">
                  <table>
					<tr><td></td></tr>
					<?php foreach ($category_indicator["indicators"] as $indicator): ?>
						<tr><td> <input type="checkbox" name="indicators[]" value="<?= $indicator["name"] ?>" checked="checked"><?= $indicator["description"] ?><br></td></tr>
					<?php endforeach;?>
					<tr><td></td></tr>
                 </table>
        </div>
	    <?php $i++; endforeach; ?>
		</div>
            <div class="form-group">
            </div>
        </fieldset>
    </form>
  </body>
 </html>
