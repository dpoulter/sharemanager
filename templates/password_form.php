
<form action="reset_passwd.php" method="post">
    <fieldset>
		<div class="col-md-3">
        	<div class="form-group">
            	<input autofocus class="form-control" id="password" name="password" type="password" placeholder="Enter new password"/>
            </div>
            <div class="form-group">
            	<input autofocus class="form-control" id="password2" name="password2" type="password" placeholder="Re-type new password"/>
            	<input name="action" type="hidden" value="<?php print($encrypt) ?>" />
            </div>
            <div class="form-group">
            	<button type="submit" class="btn btn-default" onclick="mypasswordmatch();">Reset Password</button>
        	</div>
		</div>
    </fieldset>
    
   
    
</form>
