<?php

#[\AllowDynamicProperties]
class Module_ContentHBK
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $TitleText;
	public $LeftPanel;
	public $RightPanel;
	public $ImageSrc;
	public $ImageSrcAdd;
	public $ImageTop = false;
	public $ImageBottom = false;
	public $BorderBottom = false;
	public $PaddingBottom = false;
	public $AssetsPath = "assets";
	public $MenuId;
	public $AddImgBgAttch = "fixed";

	public function GetAddImgStyle()
	{
		$text = '';
		$text .= ' style="background: rgb(55,99,58) 
		url(\''.$this->AssetsPath.'/'.$this->ImageSrcAdd.'\') no-repeat center center '.$this->AddImgBgAttch.';
		-webkit-background-size: cover;
	  -moz-background-size: cover;
	  -o-background-size: cover;
	  background-size: cover;
		"
	';
		return $text;
	}

	public function GetImgStyle()
	{
		return '
		style="

		background: rgb(55,99,58) url(\''.$this->AssetsPath.'/'.$this->ImageSrc.'\') no-repeat center center fixed;
		background: -moz-linear-gradient(180deg, rgba(55,99,58,0) 5%, rgba(55,99,58,0.8) 23%, rgba(6,1,34,0.8) 82%, rgba(48,48,48,0) 100%),url(\''.$this->AssetsPath.'/'.$this->ImageSrc.'\') no-repeat center center fixed;
		background: -webkit-linear-gradient(180deg, rgba(55,99,58,0) 5%, rgba(55,99,58,0.8) 23%, rgba(6,1,34,0.8) 82%, rgba(48,48,48,0) 100%),url(\''.$this->AssetsPath.'/'.$this->ImageSrc.'\') no-repeat center center fixed;
		background: linear-gradient(180deg, rgba(55,99,58,0) 5%, rgba(55,99,58,0.8) 23%, rgba(6,1,34,0.8) 82%, rgba(48,48,48,0) 100%),url(\''.$this->AssetsPath.'/'.$this->ImageSrc.'\') no-repeat center center fixed;
		-webkit-background-size: cover;
		-moz-background-size: cover;
		-o-background-size: cover;
		background-size: cover;
"
		';
	}
	public function GetAddParams()
	{
		$text = '';
		if ($this->PaddingBottom)
		{
			$text .= ' padding_b-100';
		}
		if ($this->AddImgBgAttch == "scroll")
		{
			$text .= ' imgKeepRat';
		}
		return $text;
	}
  
	public function GetHTML()
	{
		$text = '';
		if ($this->ImageTop)
		{
			$text .= '
			<section class="main-page-article addImg '.$this->GetAddParams().'" '.$this->GetAddImgStyle().'>
		  <div class="container">
			  <div class="row">
				  <div class="col-lg-12">
					  <h2></h2>
					  <div class="image"></div>
				  </div>
			  </div>
		  </div>
		</section>
		
		';
		}

		$text .= '
		<section class="main-page-article" id="'.$this->MenuId.'" '.$this->GetImgStyle().'>
    <div class="container">
		<div class="row">
			<div class="col-lg-12">
				<h2>'.$this->TitleText.'</h2>
            </div>
		</div>
		<div class="row">
			<div class="col-lg-6">
			'.$this->LeftPanel.'
			</div>
		
		
			<div class="col-lg-6">
			'.$this->RightPanel.'
			</div>
			
		</div>
    </div>
  </section>
		';

		if ($this->ImageBottom)
		{
			$text .= '<section class="main-page-article addImg '.$this->GetAddParams().'';
			if ($this->BorderBottom)
			{
				$text .= ' margin_b-20';
			}
			$text .= '
			"  '.$this->GetAddImgStyle().'>
		  <div class="container">
			  <div class="row">
				  <div class="col-lg-12">
					  <h2></h2>
					  <div class="image"></div>
				  </div>
			  </div>
		  </div>
		</section>';
		}

		return $text;
	}
}
?>