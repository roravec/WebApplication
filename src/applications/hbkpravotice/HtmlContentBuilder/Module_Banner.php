<?php

#[\AllowDynamicProperties]
class Module_Banner
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $SmallText;
	public $HeadlineText;
	public $LongText;
	public $AssetsPath = "assets";
  
	public function GetHTML()
	{
		return '
<!-- ***** Main Banner Area Start ***** -->
<section class="section main-banner" id="top" data-section="section1">
	<video autoplay muted loop id="bg-video" playsinline preload="yes" width="100%" height="auto" poster="'.$this->AssetsPath.'/images/konovicacup.jpg">
		<source src="'.$this->AssetsPath.'/images/course-video.mp4" type="video/mp4" />
	</video>

	<div class="video-overlay header-text">
		<div class="container">
		  <div class="row">
			<div class="col-lg-12">
			  <div class="caption">
			<h6>'.$this->SmallText.'</h6>
			<h2>'.$this->HeadlineText.'</h2>
			<p>'.$this->LongText.'</p>
			
		</div>
			</div>
		  </div>
		</div>
	</div>
</section>
<!-- ***** Main Banner Area End ***** -->
';
	}
}

?>