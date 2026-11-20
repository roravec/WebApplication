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
		<meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="">
    <meta name="author" content="ERMA Group s.r.o.">
    <link href="https://fonts.googleapis.com/css?family=Poppins:100,200,300,400,500,600,700,800,900" rel="stylesheet">

    '.$this->GetTitle().'
	<script src="'.$this->AssetsPath.'/js/timer.js"></script>
    <!-- Bootstrap core CSS -->
    <link href="'.$this->AssetsPath.'/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <!-- Additional CSS Files -->
    <link rel="stylesheet" href="'.$this->AssetsPath.'/css/fontawesome.css">
    <link rel="stylesheet" href="'.$this->AssetsPath.'/css/style.css">
    <link rel="stylesheet" href="'.$this->AssetsPath.'/css/owl.css">
    <link rel="stylesheet" href="'.$this->AssetsPath.'/css/lightbox.css">
	'.$this->GetFavicon().'
	'.$this->GetEndOfBodyDependencies().'
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
	  <link rel="apple-touch-icon" sizes="180x180" href="'.$this->AssetsPath.'/favicon/apple-touch-icon.png">
	  <link rel="icon" type="image/png" sizes="32x32" href="'.$this->AssetsPath.'/favicon/favicon-32x32.png">
	  <link rel="icon" type="image/png" sizes="16x16" href="'.$this->AssetsPath.'/favicon/favicon-16x16.png">
	  <link rel="manifest" href="'.$this->AssetsPath.'/favicon/site.webmanifest">
	  <link rel="mask-icon" href="'.$this->AssetsPath.'/favicon/safari-pinned-tab.svg" color="#5bbad5">
	  <meta name="msapplication-TileColor" content="#da532c">
	  <meta name="theme-color" content="#ffffff">
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
		return '';
	}
	public function GetEndOfBodyDependencies()
	{
		return '
		<!-- Scripts -->
  <!-- Bootstrap core JavaScript -->
    <script src="'.$this->AssetsPath.'/jquery/jquery.min.js"></script>
    <script src="'.$this->AssetsPath.'/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script src="'.$this->AssetsPath.'/js/isotope.min.js"></script>
    <script src="'.$this->AssetsPath.'/js/owl-carousel.js"></script>
    <script src="'.$this->AssetsPath.'/js/lightbox.js"></script>
    <script src="'.$this->AssetsPath.'/js/tabs.js"></script>
    <script src="'.$this->AssetsPath.'/js/video.js"></script>
    <script src="'.$this->AssetsPath.'/js/slick-slider.js"></script>
    <script src="'.$this->AssetsPath.'/js/custom.js"></script>
    <script>
	$(document).ready(function() {
        //according to loftblog tut
        $(\'.nav li:first\').addClass(\'active\');

        var showSection = function showSection(section, isAnimate) {
          var
          direction = section.replace(/#/, \'\'),
          reqSection = $(\'.section\').filter(\'[data-section="\' + direction + \'"]\'),
          reqSectionPos = reqSection.offset().top - 0;

          if (isAnimate) {
            $(\'body, html\').animate({
              scrollTop: reqSectionPos },
            800);
          } else {
            $(\'body, html\').scrollTop(reqSectionPos);
          }

        };

        var checkSection = function checkSection() {
          $(\'.section\').each(function () {
            var
            $this = $(this),
            topEdge = $this.offset().top - 80,
            bottomEdge = topEdge + $this.height(),
            wScroll = $(window).scrollTop();
            if (topEdge < wScroll && bottomEdge > wScroll) {
              var
              currentId = $this.data(\'section\'),
              reqLink = $(\'a\').filter(\'[href*=\\#\' + currentId + \']\');
              reqLink.closest(\'li\').addClass(\'active\').
              siblings().removeClass(\'active\');
            }
          });
        };

        $(\'.main-menu, .responsive-menu, .scroll-to-section\').on(\'click\', \'a\', function (e) {
          e.preventDefault();
          showSection($(this).attr(\'href\'), true);
        });

        $(window).scroll(function () {
          checkSection();
        });

		// Add event listeners to the buttons to submit the corresponding forms
		$(\'button[name^="Socket_"]\').on(\'click\', function() {
			var formId = $(this).attr(\'form\');
			$(\'#\' + formId).submit();
		});
	});
    </script>';
	}
	
	private function GetContent()
	{
		return $this->content;
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
	private $languageCode = "en";
	private $AssetsPath = "assets";
	private $titleText = "ERMA Group - Smart IoT solutions";
	private $content = "";
	
}

?>