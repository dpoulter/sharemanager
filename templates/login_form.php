<form action="login.php" method="post">
    <fieldset>
        </p>
		<div class="col-md-4">
        <div class="mb-3">
            <input autofocus class="form-control" name="username" placeholder="Username" type="text" maxlength="20" size="20""/>
        </div>
        <div class="mb-3">
            <input class="form-control" name="password" placeholder="Password" type="password" maxlength="20" size="20"/>
        </div>
        <div class="mb-3">
            <button type="submit" class="btn btn-outline-primary">Log In</button>
        </div>
        <div>
        	<input type="hidden" name="client_id" value="<?php $client_id ?>"/>
        	<input type="hidden" name="state" value="<?php $state ?>"/>
        	<input type="hidden" name="response_type" value="<?php $response_type ?>"/>
        	<input type="hidden" name="response_uri" value="<?php $response_uri ?>"/>
        </div>
		</div>
    </fieldset>
</form>
<div>
    <p><a href="register.php">Register</a> for an account</p>
    <p><a href="reset_passwd.php">Forgot Username or Password</a></p>
</div>

 <!--  Cookie Notice  -->

 <div id="lawmsg" class="alert alert-info alert-dismissible h6 fade show fixed-bottom" role="alert">
    <!-- Bootstrap 5 replaced .close with .btn-close, which draws its own icon,
         so the &times; span it needed before is gone. -->
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    &nbsp; By continuing to use this website you consent to the use of cookies which is required for the functioning of the website. &nbsp; We will not use this data to track you or share it with anyone else. &nbsp;
</div>
