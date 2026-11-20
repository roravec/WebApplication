<?php

#[\AllowDynamicProperties]
class Module_Login
{
	/* Public  *****************************/
	public $errorMessage ="";
	public $justLogout = "";
	public $AssetsPath = "assets";

	public function __construct()
	{
	}
	
	public function GetHTML()
	{
		$text = '
		<main class="form-signin">
  <form action="stadiumControl" method="post" id="frmLogin">
  <input type="hidden" name="action" value="login">
    <center><a href="index.php" title="HBK Pravotice"><img class="mb-4" src="'.$this->AssetsPath.'/images/logo-small.png" alt="" ></center></a>';

	if ($this->errorMessage != "") $text.= '<div class="alert alert-warning" role="alert">'.$this->errorMessage.'</div>';

	if ($this->justLogout) $text.= '<div class="alert alert-success" role="alert">You have been successfully logged out.</div>';

    $text.= '<h1 class="h3 mb-3 fw-normal">Sign in</h1>
    <label for="inputEmail" class="visually-hidden">Email address</label>
    <input type="email" name="email" id="inputEmail" class="form-control" placeholder="Email address" required autofocus>
    <label for="inputPassword" class="visually-hidden">Password</label>
    <input type="password" name="password" id="inputPassword" class="form-control" placeholder="Password" required>
    <div class="checkbox mb-3">
      <label>
        <input type="checkbox" name="remember" value="remember-me"> Remember me
      </label>
    </div>
    <button name="login" value="Login" class="w-100 btn btn-lg btn-primary" type="submit">Sign in</button>
    
  </form>
</main>
		';
		return $text;
	}
}

?>