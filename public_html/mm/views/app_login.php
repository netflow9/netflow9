<div style="width:20rem; margin: 0 auto; margin-top: 10rem">
    <?php
    if (isset($error))
	echo "<div class='alert alert-danger'>$error</div>";
    ?>

    <form method="post">
	<h5 class="mb-1">Please sign in</h5>
	<label for="username">Username</label>
	<input id="username" type="text" name="username" class="form-control" required autofocus>
	<label for="password">Password</label>
	<input type="password" id="password" name="password" class="form-control" required>
	<label for="db">DB(s)</label>
	<input id="db" type="text" name="db" class="form-control">
	<button class="mt-2 btn btn-primary btn-block" type="submit">Login</button>
    </form>

</div>

