<?php

#[\AllowDynamicProperties]
class Module_Footer
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $Copyright;
	public $FollowUsLinks;

	public $AssetsPath = "assets";
  
	public function GetHTML()
	{
		return '
		<!-- Footer -->
		<section class="footer sub-header">
		  <div class="container">
			<div class="row">
			  <div class="col-lg-2 col-sm-2">
			  </div>
			  <div class="col-lg-8 col-sm-8">
				  <p>Copyright &copy;'.date("Y").' '.$this->Copyright.'</p>
			  </div>
			  <div class="col-lg-2 col-sm-2">
				<div class="right-icons">
				  <ul>
				  '.$this->FollowUsLinks.'
				  </ul>
				</div>
			  </div>
			</div>
		  </div>
		</section>
';
	}
}

?>