<?php
#[\AllowDynamicProperties]
class HTMLContent
{
	/* Public  *****************************/
	public function __construct($languageCode = "en", $title = "", $assetsPath = "assets")
	{
		$this->languageCode = $languageCode;
		$this->titleText = $title;
		$this->AssetsPath = $assetsPath;
	}
  
	public function PageStart()
	{
		return '
<!doctype html>
<html lang="en">
	<head>
		'.$this->GetHead().'
	</head>
	<body class="body-bg">
	
		'.$this->GetBody().'
	</body>
</html>';
	}
  
	public function GetHead()
	{
	  return '
    <meta name="format-detection" content="telephone=no">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0, user-scalable=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta charset="utf-8">
    <!-- Stylesheets-->
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Kanit:300,400,500,500i,600,900%7CRoboto:400,900">
    <link rel="stylesheet" href="/'.$this->AssetsPath.'/css/bootstrap.css">
    <link rel="stylesheet" href="/'.$this->AssetsPath.'/css/fonts.css">
    <link rel="stylesheet" href="/'.$this->AssetsPath.'/css/style.css">
    <style>.ie-panel{display: none;background: #212121;padding: 10px 0;box-shadow: 3px 3px 5px 0 rgba(0,0,0,.3);clear: both;text-align:center;position: relative;z-index: 1;} html.ie-10 .ie-panel, html.lt-ie-10 .ie-panel {display: block;}</style>

		
		'.$this->GetTitle().'
		'.$this->GetFavicon().'
		'.$this->GetHeadDependencies().'
	  ';
	}
	
	public function GetBody()
	{
		return '
		'.$this->GetStartOfBodyDependencies().'
		'.$this->GetContent().'
		'.$this->GetEndOfBodyDependencies().'
		';
	}
  
	public function GetFavicon()
	{
		return '
	  <!-- Favicon -->
		<link rel="icon" href="/'.$this->AssetsPath.'/images/favicon.ico" type="image/x-icon">
	  ';
	}
	public function GetTitle()
	{
		return "<title>".$this->titleText."</title>";
	}
  
	public function GetHeadDependencies()
	{
		return '';
	}
	public function GetStartOfBodyDependencies()
	{
		return '
<div class="ie-panel"><a href="http://windows.microsoft.com/en-US/internet-explorer/"><img src="images/ie8-panel/warning_bar_0000_us.jpg" height="42" width="820" alt="You are using an outdated browser. For a faster, safer browsing experience, upgrade for free today."></a></div>
    <div class="preloader">
      <div class="preloader-body">
        <div class="preloader-item"></div>
      </div>
    </div>
    ';
	}
	public function GetEndOfBodyDependencies()
	{
		return '
		<div id="back-top" >
    <a title="Go to Top" href="#"> <i class="fas fa-level-up-alt"></i></a>
</div>

<!-- JS here -->
    <!-- Global Mailform Output-->
    <div class="snackbars" id="form-output-global"></div>
    <!-- Javascript-->
    <script src="/'.$this->AssetsPath.'/js/core.min.js"></script>
    <script src="/'.$this->AssetsPath.'/js/script.js"></script>
        ';
	}
	
	private function GetContent()
	{
        $lcontent = '
        <!-- Page-->
        <div class="page">
        '.$this->content.'
        </div>';
		return $lcontent;
	}
	
	public function ChangeTitle($newTitle)
	{
		$this->titleText = $newTitle;
	}
	public function AddToStartOfContent($additionalContent)
	{
		$this->content = $additionalContent.$this->content;
	}
	public function AddToContent($additionalContent)
	{
		$this->content = $this->content.$additionalContent;
	}
  
//	VARIABLES -----------------------------------------
	private $languageCode = "sk";
	private $AssetsPath = "assets";
	private $titleText = "BNHL";
	private $content = "";
	
}

?>