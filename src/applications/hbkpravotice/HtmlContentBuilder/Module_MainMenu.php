<?php

#[\AllowDynamicProperties]
class Module_MainMenu
{
	/* Public  *****************************/
	public function __construct($app)
	{
		$this->app = $app;
	}
	
	public $MenuItem_Home;
	public $MenuItem_Events;
	public $MenuItem_KonovicaCup;
	public $MenuItem_PravoticeCup;
	public $MenuItem_PHBL;
	public $MenuItem_About;
	public $AssetsPath = "assets";
	public $LogoText;
	public $app;

	public $stadiumControl = false;
  
	public function GetHTML()
	{
		if ($this->stadiumControl)
		{
			$this->LogoText = "Stadium Control";
		}
		$text = "";
		$text .= '
		<!-- ***** Header Area Start ***** -->
		<header class="header-area header-sticky">
			<div class="container">
				<div class="row">
					<div class="col-12">
						<nav class="main-nav">
							<!-- ***** Logo Start ***** -->
							<a href="index.php" class="logo">
								<img src="'.$this->AssetsPath.'/images/logo-small.png">
								<div class="text-logo">'.$this->LogoText.'</div>
							</a>
							<!-- ***** Logo End ***** -->
							<!-- ***** Menu Start ***** -->
							<ul class="nav">';
							if (!$this->stadiumControl)
							{
								$text .= '
								<li class="scroll-to-section"><a href="#top">'.$this->MenuItem_Home.'</a></li>
								<li class="scroll-to-section"><a href="#events">'.$this->MenuItem_Events.'</a></li>
								<li class="scroll-to-section"><a href="#konovicacup">'.$this->MenuItem_KonovicaCup.'</a></li>
								<li class="scroll-to-section"><a href="#pravoticecup">'.$this->MenuItem_PravoticeCup.'</a></li>
								<li class="scroll-to-section"><a href="#phbl">'.$this->MenuItem_PHBL.'</a></li>
								<li class="scroll-to-section"><a href="#contact">'.$this->MenuItem_About.'</a></li> 
								';
							}
							else
							{
								if ($this->app->CheckUserPermissions("loggedIn", true))
								{
									$text .= '<li class="has-sub"><a href="javascript:void(0)">Stadium</a>
										<ul class="sub-menu">';

										$text .= '<li><a href="stadiumControl?p=overview">Overview</a></li>';
										if ($this->app->CheckUserPermissions("elsockets", true, true, true, true))
											$text .= '<li><a href="stadiumControl?p=elsockets">El.sockets</a></li>';
										if ($this->app->CheckUserPermissions("soundfile", true, true, true, true))
											$text .= '<li><a href="stadiumControl?p=soundfile">Sound files</a></li>';
											
										$text .= '</ul>
									</li>';

									if ($this->app->CheckUserPermissions("game", true, true, true, true))
									{
									$text .= '
									<li class="has-sub"><a href="javascript:void(0)">Games</a>
										<ul class="sub-menu">
											<li><a href="stadiumControl?p=games">Games</a></li>
											<li><a href="stadiumControl?p=gamegroupstats">Stats</a></li>
										</ul>
									</li>
									';
									}

									if ($this->app->CheckUserPermissions("team", true, true, true, true))
									{
									$text .= '
									<li class="has-sub"><a href="javascript:void(0)">Teams</a>
										<ul class="sub-menu">
											<li><a href="stadiumControl?p=teams">Teams</a></li>
											<li><a href="stadiumControl?p=players">Players</a></li>
										</ul>
									</li>
									';
									}

									if ($this->app->CheckUserPermissions("timerevent", true, true, true, true))
									{
									$text .= '
									<li class="has-sub"><a href="javascript:void(0)">Timer</a>
										<ul class="sub-menu">';
										$text .= '<li><a href="stadiumControl?p=timerevent">Timer events</a></li>';
										if ($this->app->CheckUserPermissions("eventgroup", true, true, true, true))
											$text .= '<li><a href="stadiumControl?p=eventgroup">Event templates</a></li>';
										if ($this->app->CheckUserPermissions("user", true, true, true, true))
											$text .= '<li><a href="stadiumControl?p=user">Users</a></li>';
										$text .= '</ul>
									</li>';
									}

									if ($this->app->CheckUserPermissions("event", true, true, true, true))
									{
									$text .= '
										<li><a href="stadiumControl?p=events">Events</a></li>
									';
									}


									$text .= '
										<li><a href="stadiumControl?p=logout">Logout</a></li>
									';
								}
								else
								{
									// not logged in
									$text .= '
										<li><a href="stadiumControl">Login</a></li>
									';
								}
							}
							$text .= '
								</ul>        
							<a class="menu-trigger">
								<span>Menu</span>
							</a>
							<!-- ***** Menu End ***** -->
						</nav>
					</div>
				</div>
			</div>
		</header>
		<!-- ***** Header Area End ***** -->
		';
		return $text;
	}
}

?>