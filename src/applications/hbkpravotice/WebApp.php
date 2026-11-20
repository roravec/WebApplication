<?php
include_once __DIR__ . "/TextIdentifiers.php";

include_once __DIR__ . "/HtmlContentBuilder/HTMLContent.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Footer.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_MainMenu.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Banner.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_ContentHBK.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Events.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_StadiumControl.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Login.php";

include_once __DIR__ . "/WebAppStadiumControl.php";

#[\AllowDynamicProperties]
class WebApp   // contract of the oldest core: SetInitVariables() + GetHTML() - run by WebApplication\Legacy\OldCoreApplication
{
	public static $StadiumControlPageName = "StadiumControl";
  /* Public  */
	public function __construct()
	{
	}

	public function SetInitVariables($application, $applicationId, $applicationPath, $database, $languageCode)
	{
		$this->Database = $database;
		$this->LanguageCode = $languageCode;
		$this->App = $application;
		$this->AppId = $applicationId;
		$this->AppPath = $applicationPath;
		$this->AssetsPath = $this->AppPath."/assets";
		$this->HtmlContent = new HTMLContent($this->LanguageCode, 
							$this->App->GetTextFromDatabase(
								$this->LanguageCode, 
								TI_HEADER_TITLE),
								$this->AssetsPath
						);
	}

	public function GetHTML($pageName)
	{
		$page = $this->App->GetContentFilename($pageName, $this->defaultPage);
		if (strtolower(WebApp::$StadiumControlPageName) == strtolower($page)) 
		{
			$stadiumControl = new WebAppStadiumControl();
			$stadiumControl->SetInitVariables(
				$this->App, $this->AppId, $this->AppPath, $this->Database, $this->LanguageCode);
			return $stadiumControl->GetHTML($pageName);
		}
		else
		{
			$menuContent = $this->CreateMainMenu();
			$footerContent = $this->CreateFooter();
			
			$content = $this->BuildPageContent($pageName);
			
			$this->HtmlContent->AddToContent($menuContent);
			$this->HtmlContent->AddToContent($content);
			$this->HtmlContent->AddToContent($footerContent);

			return $this->HtmlContent->PageStart();
		}
	}
	
	public function GetTxt($code)
	{
		return $this->App->GetTextFromDatabase($this->LanguageCode, $code);
	}
  
	public function CreateFooter()
	{
		$footer = new Module_Footer();
		$footer->AssetsPath = $this->AssetsPath;
		$footer->Copyright = $this->App->GetTextFromDatabase($this->LanguageCode, TI_FOOTER_COPYRIGHT);
		$footer->FollowUsLinks = $this->App->GetTextFromDatabase($this->LanguageCode, TI_FOOTER_FOLLOW_US_LINKS);
		return $footer->GetHTML();
	}
	public function CreateMainMenu()
	{
		$menu = new Module_MainMenu($this);
		$menu->AssetsPath = $this->AssetsPath;
		$menu->MenuItem_Home = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_ITEM_HOME);
		$menu->MenuItem_Events = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_ITEM_EVENTS);
		$menu->MenuItem_KonovicaCup = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_ITEM_KONOVICACUP);
		$menu->MenuItem_PravoticeCup = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_ITEM_PRAVOTICECUP);
		$menu->MenuItem_PHBL = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_ITEM_PHBL);
		$menu->MenuItem_About = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_ITEM_ABOUT);
		$menu->LogoText = $this->App->GetTextFromDatabase($this->LanguageCode, TI_MAINMENU_LOGOTEXT);
		return $menu->GetHTML();
	}
	public function CreateBanner()
	{
		$menu = new Module_Banner();
		$menu->AssetsPath = $this->AssetsPath;
		$menu->SmallText = $this->App->GetTextFromDatabase($this->LanguageCode, TI_BANNER_SMALLTEXT);
		$menu->HeadlineText = $this->App->GetTextFromDatabase($this->LanguageCode, TI_BANNER_HEADLINETEXT);
		$menu->LongText = $this->App->GetTextFromDatabase($this->LanguageCode, TI_BANNER_LONGTEXT);
		return $menu->GetHTML();
	}

	public function CreateBanner404()
	{
		$menu = new Module_Banner();
		$menu->AssetsPath = $this->AssetsPath;
		$menu->SmallText = "";
		$menu->HeadlineText = $this->App->GetTextFromDatabase($this->LanguageCode, TI_404_TITLE);
		$menu->LongText = $this->App->GetTextFromDatabase($this->LanguageCode, TI_404_TEXT);
		return $menu->GetHTML();
	}

	public function CreateEvents()
	{
		$module = new Module_Events();
		$module->AssetsPath = $this->AssetsPath;
		$module->SectionName = $this->App->GetTextFromDatabase($this->LanguageCode, TI_EVENTS_HEADLINE);
		$module->NoEvents = $this->App->GetTextFromDatabase($this->LanguageCode, TI_EVENTS_NOEVENTS);
		$result = $this->App->GetDatabase()->Query(
			"SELECT * FROM ".$this->App->GetDbPrefix()."event 
			WHERE active=1");
		while ($row = $result->fetch_assoc())
		{
			$event = new Event(
				$row["image"], 
				$row["year"], 
				$row["date"], 
				$row["name"], 
				$row["description"], 
				$row["url"]
			);
			$module->AddEvent($event);
		}
		return $module->GetHTML();
	}

	public function CreateContent_KonovicaCup()
	{
		$image = "images/konovicacup.jpg";
		$menuId = "konovicacup";
		return $this->CreateContentHBK(
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_KONOVICACUP_HEADLINE),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_KONOVICACUP_LEFT),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_KONOVICACUP_RIGHT),
			$image, $menuId, false, true, true
		);
	}
	public function CreateContent_PravoticeCup()
	{
		$image = "images/pravoticecup.jpg";
		$menuId = "pravoticecup";
		return $this->CreateContentHBK(
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_PRAVOTICECUP_HEADLINE),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_PRAVOTICECUP_LEFT),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_PRAVOTICECUP_RIGHT),
			$image, $menuId, false, true, true
		);
	}
	public function CreateContent_PHBL()
	{
		$image = "images/phbl.jpg";
		$menuId = "phbl";
		return $this->CreateContentHBK(
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_PHBL_HEADLINE),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_PHBL_LEFT),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_PHBL_RIGHT),
			$image, $menuId, false, true, true
		);
	}
	public function CreateContent_About()
	{
		$image = "images/hbkpravotice1.jpg";
		$imageAdd = "images/hbkpravotice2.jpg";
		$menuId = "contact";
		return $this->CreateContentHBK(
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_ABOUT_HEADLINE),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_ABOUT_LEFT),
			$this->App->GetTextFromDatabase($this->LanguageCode, TI_CONTENT_ABOUT_RIGHT),
			$image, $menuId, 
			//true, false, false
			false, true, false, true, "scroll", $imageAdd
		);
	}
	
	public function CreateContentHBK(
		$titleText, $leftText, $rightText, $imageSrc, $menuId, 
		$imageTop=false, $imageBottom=false, $borderBottom=false, $paddingBottom=false,
		$bgAttch="fixed", $imageSrcAdd=""
		)
	{
		$module = new Module_ContentHBK();
		$module->AssetsPath = $this->AssetsPath;
		$module->TitleText = $titleText;
		$module->LeftPanel = $leftText;
		$module->RightPanel = $rightText;
		$module->ImageSrc = $imageSrc;
		if ($imageSrcAdd == "")
			$module->ImageSrcAdd = $imageSrc;
		else
			$module->ImageSrcAdd = $imageSrcAdd;
		$module->MenuId = $menuId;
		$module->ImageTop = $imageTop;
		$module->ImageBottom = $imageBottom;
		$module->BorderBottom = $borderBottom;
		$module->PaddingBottom = $paddingBottom;
		$module->AddImgBgAttch = $bgAttch;
		return $module->GetHTML();
	}
	
	public function BuildPageContent($path)
	{
		$page = $this->App->GetContentFilename($path, $this->defaultPage);
		switch ($page)
		{
			case "": 	;
			case "index.php": 	;
			case "overview": 	return $this->BuildPage_Overview();
			case "404": 		;
			default: 			return $this->BuildPage_404();
		}
	}
	
	public function BuildPage_404()
	{
		return $this->CreateBanner404();
	}
	public function BuildPage_Overview()
	{
		return $this->CreateBanner().
		$this->CreateEvents().
		$this->CreateContent_KonovicaCup().
		$this->CreateContent_PravoticeCup().
		$this->CreateContent_PHBL().
		$this->CreateContent_About()
		;
	}

	/* Private */
	private $App;		// Application object
	private $AppId;		// Application object
	private $AppPath;		// Application object
	private $HtmlContent;
	private $Database; 		// DB object
	private $LanguageCode;    // language code
	
	private $pathPrefix = "";
	private $contentFileExt = "";
	private $defaultPage = "overview";
	private $page404 = "404";
	private $AssetsPath = "assets";
}
?>