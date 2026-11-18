<h1>Log in</h1>
<?php if ($error): ?><p class="error"><?= $e($error) ?></p><?php endif ?>
<form method="post" action="<?= $e($app->url('/login')) ?>">
  <?= WebApplication\Csrf::field() ?>
  <label>Login <input name="login" autocomplete="username" required></label>
  <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
  <label><input type="checkbox" name="remember" value="1"> Remember me</label>
  <button>Log in</button>
</form>
