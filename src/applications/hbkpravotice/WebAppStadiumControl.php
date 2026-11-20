<?php

include_once __DIR__ . "/TextIdentifiers.php";
include_once __DIR__ . "/HtmlContentBuilder/HTMLContent.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Footer.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_MainMenu.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_StadiumControl.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Login.php";
include_once __DIR__ . "/HtmlContentBuilder/Form.php";
include_once __DIR__ . "/HtmlContentBuilder/Table.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_GenericContent.php";
include_once __DIR__ . "/Timer.php";
include_once __DIR__ . "/Permissions.php";
include_once __DIR__ . "/Stats.php";

include_once __DIR__ . "/Database/DbObj.php";
include_once __DIR__ . "/Database/DbObj_Assistance.php";
include_once __DIR__ . "/Database/DbObj_Cmdqueue.php";
include_once __DIR__ . "/Database/DbObj_Config.php";
include_once __DIR__ . "/Database/DbObj_Game.php";
include_once __DIR__ . "/Database/DbObj_Elsocket.php";
include_once __DIR__ . "/Database/DbObj_Event.php";
include_once __DIR__ . "/Database/DbObj_Goal.php";
include_once __DIR__ . "/Database/DbObj_Gameroster.php";
include_once __DIR__ . "/Database/DbObj_Log.php";
include_once __DIR__ . "/Database/DbObj_Penalty.php";
include_once __DIR__ . "/Database/DbObj_Player.php";
include_once __DIR__ . "/Database/DbObj_Team.php";
include_once __DIR__ . "/Database/DbObj_Teamhasplayer.php";
include_once __DIR__ . "/Database/DbObj_Timer.php";
include_once __DIR__ . "/Database/DbObj_Timerevent.php";
include_once __DIR__ . "/Database/DbObj_EventGroup.php";
include_once __DIR__ . "/Database/DbObj_SoundFile.php";
include_once __DIR__ . "/Database/DbObj_EventGroupEvent.php";
include_once __DIR__ . "/Database/DbObj_Gameevent.php";
include_once __DIR__ . "/Database/DbObj_Gamegroupstats.php";
include_once __DIR__ . "/Database/DbObj_Ggsgame.php";

#[\AllowDynamicProperties]
class StadiumControl_Game
{
    public $id = 0;
    public $gameGroup = 0;
    public $refereeRating = 0;
    public $visitors = 0;
    public $name = "";
    public $referee = "";
    public $location = "";
    public $goals = array();
    public $penalties = array();
    public $rosterTeam1 = array();
    public $rosterTeam2 = array();
    public $startTime = ""; // datetime-local
    public $note = "";
    public $team1 = 0;
    public $team2 = 0;
    public $team1name = "";
    public $team2name = "";
    public $team1ImagePath = "";
    public $team2ImagePath = "";
    public $team1goalsCount = 0;
    public $team2goalsCount = 0;
    public $afterGameStatus = 0;
    public $eventGroup = 0;
}

#[\AllowDynamicProperties]
class WebAppStadiumControl   // contract of the oldest core: SetInitVariables() + GetHTML() - run by WebApplication\Legacy\OldCoreApplication
{
	/* Private variables *****************************************************/
	private $App;		// Application object
	private $AppId;		// Application object
	private $AppPath;		// Application object
	private $HtmlContent;
	private $Database; 		// DB object
	private $LanguageCode;    // language code
	
	private $pathPrefix = "";
	private $NoticeText = "";
	private $contentFileExt = "";
	private $defaultPage = "overview";
	private $page404 = "404";
	private $AssetsPath = "assets";
	private $CurrentPage = "overview";
	private $SiteName = "StadiumControl";
	public $ConfigID = 1;
	public $LocationConfig;
	public $LocationID = 1;
	public $LocationMaxSockets = 4;
	public $PenaltyEventType = 2;

	public $HomeHornHotkey = 'h';
	public $AwayHornHotkey = 'a';
	public $PAGE = array();
	private $FormError = "";
	private $PenaltyAnnounceSoundFile = "penaltyStart.mp3";
	private $PenaltyEndSoundFile = "penaltyEnd.mp3";
	private $EventEndAnnounceSoundFile = "startEventDefault.mp3";
	private $LastMinuteAnnounceSoundFile = "lastMinute.mp3";
	private $AvoidGETHandling = false;
	private $GameStatuses = array();
	private $Penalties = array();
	private $Commands = array();
	private $EventTypes = array();
	private $Permissions = array();
	private $Timer;
    private $_premusicDebouncerLimit = 3; // number of ticks to wait before starting music
    private $_musicPauseLimitTicks = 20; // number of ticks to wait before resetting music after pause
	public $MaxShowableGameEventType = 2; // all types under this number are used as game event
	/* Private variables END *****************************************************/

  /* Public  */
	public static $StadiumControlTickPage = "TICK";
	public static $StadiumControlStatsPage = "STATS";
	public static $TimerCountDownFetchPage = "GetTimerCountdown";
	public function __construct()
	{
	}

	public function SetInitVariables($application, $applicationId, $applicationPath, $database, $languageCode)
	{
		$this->SetInitValues();
		$this->Database = $database;
		$this->LanguageCode = $languageCode;
		$this->App = $application;
		$this->AppId = $applicationId;
		$this->AppPath = $applicationPath;
		$this->AssetsPath = $this->AppPath."/assets";
		$this->HtmlContent = new HTMLContent($this->LanguageCode, 
							$this->App->GetTextFromDatabase(
								$this->LanguageCode, 
								TI_HEADER_TITLE_STADIUM_CONTROL),
								$this->AssetsPath
						);
		$this->LocationConfig = new DbObj_Config($this->Database, $this->App->GetDbPrefix());
		$this->LocationConfig->id = $this->ConfigID;
		$this->LocationConfig->Read();
		$this->Timer = new Timer($this, $this->Database, $this->App->GetDbPrefix());

		$this->HandleHttpRequests();
	}
	public function Log($action, $note)
	{
		$dbobj = new DbObj_Log($this->Database, $this->App->GetDbPrefix());
		$dbobj->user = 		intval($this->GetUser()->id);
		$dbobj->action = 	$this->Database->EscapeString($action);
		$dbobj->note = 		$this->Database->EscapeString($note);
		$dbobj->Create();
	}

	private function GetUser()
	{
		return $this->App->GetUser();
	}

	private function HandleHttpRequests()
	{
		$this->HandleHttpPOST();
		$this->HandleHttpGET();
	}

    public function HandlePOST_ControlBox()
    {
        $expected_key = "ABC123";
        $api_key = isset($_POST["API_KEY"]) ? $_POST["API_KEY"] : '';
        $data = isset($_POST["DATA"]) ? $_POST["DATA"] : '';

        if ($api_key !== $expected_key) {
            http_response_code(403);
            echo "Invalid API key";
            return;
        }
        echo "Data received:".$data;
        $this->HandleButtonsControlBox($data);
        //$this->Database->Query("INSERT INTO hbkpravotice_buttons (buttons) VALUES ('".$this->Database->EscapeString($data)."')");
    }

	private function HandleHttpPOST()
	{
		if (!$this->App->IsLoggedIn())
		{
			//echo "Insufficient rights.";
			if (isset($_POST["action"]))
			{
				switch ($_POST["action"])
				{
					case "login": 	$this->HandlePOST_Login(); break;

                    case "submit_data":
                        $this->HandlePOST_ControlBox(); // Add this method to process ESP32 data
                        exit;
				}
			}
		}
		else
		{
			if (isset($_POST["action"]))
			{
				switch ($_POST["action"])
				{
					case "player":
						if ($this->CheckUserPermissions("player",true,true,true,true))
							$this->HandlePOST_Player(); 
						break;
					case "soundfile": 
						if ($this->CheckUserPermissions("soundfile",true,true,true,true))	
							$this->HandlePOST_SoundFile(); 
						break;
					case "team": 
						if ($this->CheckUserPermissions("team",true,true,true,true))	
							$this->HandlePOST_Team(); 
						break;
					case "event": 	
						if ($this->CheckUserPermissions("event",true,true,true,true))
							$this->HandlePOST_Event(); 
						break;
					case "game": 
						if ($this->CheckUserPermissions("game",true,true,true,true))	
							$this->HandlePOST_Game(); 
						break;
					case "fullgame": 	
						if ($this->CheckUserPermissions("fullgame",true,true,true,true))
							$this->HandlePOST_Game(); 
						break;
					case "eventgroup":
						if ($this->CheckUserPermissions("eventgroup",true,true,true,true))	
							$this->HandlePOST_EventGroup(); 
						break;
					case "gamestats": 
						if ($this->CheckUserPermissions("gamestats",true,true,true,true))	
							$this->HandlePOST_GameStats(); 
						break;
					case "activeGame": 	
						if ($this->CheckUserPermissions("activeGame",true,true,true,true))
							$this->HandlePOST_ActiveGame(); 
						break;
					case "sendCommand": 
						if ($this->CheckUserPermissions("sendCommand",true,true,true,true))
							$this->HandlePOST_SendCommand(); 
						break;
					case "elsocket": 
						if ($this->CheckUserPermissions("elsockets",true,true,true,true))
							$this->HandlePOST_Elsocket(); 
						break;
					case "eventtimer":
						if ($this->CheckUserPermissions("timerevent",true,true,true,true))
							$this->HandlePOST_EventTimer(); 
						break;
					case "timerStop": 
						if ($this->CheckUserPermissions("timerStop",true,true,true,true))
							$this->HandlePOST_TimerStop(); 
						break;
					case "user": 
						if ($this->CheckUserPermissions("user",true,true,true,true))
							$this->HandlePOST_User(); 
						break;
					case "gameeventControl": 
						if ($this->CheckUserPermissions("gameeventControl",true,true,true,true))
							$this->HandlePOST_GameEventControl(); 
						break;
					case "gamegroupstats": 
						if ($this->CheckUserPermissions("gamegroupstats",true,true,true,true))
							$this->HandlePOST_GameGroupStats(); 
						break;
					case "gameroster": 
						if ($this->CheckUserPermissions("gameroster",true,true,true,true))
							$this->HandlePOST_GameRoster(); 
						break;
					default: break;
				}
			}
		}
	}
	private function HandleHttpGET()
	{
		if (isset($_GET['p']))
		{
			$this->CurrentPage = htmlspecialchars(substr($_GET['p'], 0, 32));
			if ($this->CurrentPage == WebAppStadiumControl::$StadiumControlTickPage)
			{
				$this->HandleTickRequest();
				exit;
			}
			else if ($this->CurrentPage == WebAppStadiumControl::$TimerCountDownFetchPage)
			{
				$this->HandleTimerCountdownRequest();
				exit;
			}
			else if ($this->CurrentPage == WebAppStadiumControl::$StadiumControlStatsPage)
			{
				$this->HandleStatsRequest();
				exit;
			}
			if (isset($_GET['delete']) && $this->HttpGET('id', true) > 0)
			{
				$this->HandleDelete($this->Page($this->CurrentPage), $this->HttpGET('id', true));
			}
		}
        if (isset($_GET['api']))
        {
            $apiRequest = $_GET['api'];
            $params = $_GET;
            $this->HandleAPI_GET_Request($apiRequest, $params);
        }
		if (isset($_GET['notice']))
		{
			switch ($this->HttpGET("notice", true))
			{
				case 1: 
					$this->NoticeText = "Create of a record was successful.";
					break;
				case 2: 
					$this->NoticeText = "Update of a record was successful.";
					break;
			}
			
		}
	}
	private function HttpGET($index, $isNumber=false)
	{
		if (isset($_GET[$index]))
		{
			if ($isNumber)
			{
				return intval($_GET[$index]);
			}
			else
			{
				return $_GET[$index];
			}
		}
		else
		{
			return "";
		}
	}

	private function HandleTickRequest()
	{	
		if (isset($_POST['commandHandled']) && isset($_POST['deviceID']) &&
			isset($_POST['id']) && isset($_POST['type'])
			) // client is posting data
		{
			if ($this->DeviceIDValidator(intval($_POST['deviceID'])))
			{
				$this->HandleCommandAckPost($_POST['id'], $_POST['type']);
			}
			//echo "RASTO POST:";
			//print_r($_POST);

		}
		else // Just get data
		{
			/** Main Timer Tick function **/
			$this->Timer->Tick(); // should be called at least once a second

			if (isset($_GET['deviceLocation'])) // if device ID is set then use it, 
												// otherwise default location ID will be used
			{
				$this->LocationID = intval($_GET['deviceLocation']);
			}
			$this->PrintControlDataJson();
		}
		exit;
	}
	private function HandleTimerCountdownRequest()
	{
		$countdown = $this->Timer->GetCountdown();
		echo json_encode($countdown);
		exit;
	}

	private function ShowStatsTable($headline, $jsonData, $refreshTime=0)
	{
		// Decode the JSON data
		$playerStatsArray = json_decode($jsonData, true);

		// Check if decoding was successful
		if ($playerStatsArray === null) {
			die("Error decoding JSON data");
		}
		// Generate HTML table
		echo '<!DOCTYPE html>
<html>
<head>
    <title>'.$headline.'</title>';
	if ($refreshTime > 0)
    	echo '<meta http-equiv="refresh" content="'.$refreshTime.'">';
	echo '
	<style>*{
      font-family: Verdana, Geneva, Tahoma, sans-serif;
    }
	  td, th {padding: 3px;}
        </style>
	';
echo '</head>

<body>';
//print_r ($playerStatsArray);
echo '<h2>'.$headline.'</h2>';
		echo '<table border="1">
		<tr>
			<th></th>
			<th>Player Name</th>
			<th>Games</th>
			<th>Points</th>
			<th>Goals</th>
			<th>Assistances</th>
			<th>Penalty</th>
			<th>PPG</th>
			<th>Team Name</th>
			<th></th>
		</tr>';
		foreach ($playerStatsArray as $stats)
		{
			foreach ($stats as $playerStats)
			{
				if (isset($playerStats['playerName']))
				{
		echo '<tr>';
		echo '<td>' . $playerStats['playerImagePath'] . '</td>';
		echo '<td>' . $playerStats['playerName'] . '</td>';
		echo '<td>' . $playerStats['games'] . '</td>';
		echo '<td>' . $playerStats['totalPoints'] . '</td>';
		echo '<td>' . $playerStats['goals'] . '</td>';
		echo '<td>' . $playerStats['assistances'] . '</td>';
		echo '<td>' . $playerStats['penaltySeconds'] . '</td>';
		echo '<td>' . $playerStats['pointsPerGame'] . '</td>';
		echo '<td>' . $playerStats['teamName'] . '</td>';
		echo '<td><img src="' . $playerStats['teamImagePath'] . '" alt="' . $playerStats['teamName'] . '" width="50" /></td>';
		echo '</tr>';
				}
			}
		}

		echo '</table></body>
</html>';
	}
	private function HandleStatsRequest()
	{	
		$ggsid = intval($_GET['id']);
		$headline = $this->GetGGSName($ggsid);
		$showTable = intval($_GET['table']);
		$stats = new Stats($this, $this->Database, $this->App->GetDbPrefix(), $ggsid);
		$jsonData = $stats->ReadStats();
		if ($showTable == 0)
		{
			echo $jsonData; // just print json
		}
		else
		{
			// Show table
			$this->ShowStatsTable($headline, $jsonData, intval($_GET['refresh']));
		}
		exit;
	}
	private function HandleCommandAckPost($cmdID, $cmdType)
	{
		$cmd = new DbObj_Cmdqueue($this->Database, $this->App->GetDbPrefix());
		$cmd->id = intval($cmdID);
		$cmd->Read();
		if ($cmd->cmd == $cmdType)
		{
			$cmd->handled=1;
			$cmd->Update();
		}
	}

	public function PrintControlDataJson()
	{
		$data = array();
		$elsockets = array();
        $runMusicBreak = 0;
        // check if music break should be running
        // run music either when timer is stopped of music can run even when timer is running during BREAK and if it is enabled to run music
        if ($this->LocationConfig->musicBreak == 1 && $this->Timer->GetActiveGameTimerEvent()->disableMusic == 0 &&
            (!$this->Timer->IsTimerRunning() || $this->Timer->GetActiveGameTimerEvent()->type == 0)
        )
        {
            $runMusicBreak = 1;
        }
        $statusMsgs = array($runMusicBreak,
         intval($this->LocationConfig->soundVolume), 
         intval($this->LocationConfig->musicVolume),
         intval($this->_premusicDebouncerLimit),  // pause before music starts to play
         intval($this->_musicPauseLimitTicks), // after how many ticks, when music is paused, music will reset
         5); // example status messages
		$commandToSend = $this->GetCommandToSend();
		$command = array(
			intval($commandToSend->id), 
			$commandToSend->cmd, 
			$commandToSend->parameter0, 
			$commandToSend->parameter1, 
			$commandToSend->parameter2);
		$addStatement = " WHERE location=".$this->LocationID." ORDER BY socketId LIMIT ".$this->LocationMaxSockets."";
		$elsockets_res = DbObj_Elsocket::ReadAll(
			$this->Database, $this->App->GetDbPrefix(),$addStatement
	);
	    $i = 0;
		foreach ($elsockets_res as $elsocket)
		{
			$elsockets[] = $elsocket->state;
		}
		$data = array($command, $elsockets, $statusMsgs);
		$json = json_encode($data); 
		echo $json;
	}

	public function GetHTML($pageName)
	{
		$menuContent = $this->CreateMainMenu();
		$footerContent = $this->CreateFooter();
		
		$content = $this->BuildPageContent();
		
		$this->HtmlContent->AddToContent($menuContent);
		$this->HtmlContent->AddToContent($content);
		$this->HtmlContent->AddToContent($footerContent);

		return $this->HtmlContent->PageStart();
	}

	public function GetCommandToSend()
	{
		$addStatement = 
		" WHERE location=".$this->LocationConfig->location." AND handled=0
		AND (expiration > ".time()." OR expiration=0)
		ORDER BY added LIMIT 1";
		$cmdArr = DbObj_Cmdqueue::ReadAll(
			$this->Database, $this->App->GetDbPrefix(),$addStatement
		);
		if (count($cmdArr) > 0)
		{
			return $cmdArr[0];
		}
		else
		{
			return new DbObj_Cmdqueue($this->Database); // empty cmd
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
		$menu->stadiumControl = true;
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

	public function CreateLogin()
	{
		$module = new Module_Login();
		$module->AssetsPath = $this->AssetsPath;
		return $module->GetHTML();
	}


	
	function ContainsAlphabet($str) {
		// Use a regular expression to check if the string contains at least 3 alphabet characters
		return preg_match('/[a-zA-Z]{3,}/', $str);
	}

	public function BuildPage_404()
	{
		return $this->CreateBanner404();
	}

	public function BuildPageContent()
	{
		$html = "";
		if (!$this->App->IsLoggedIn() || !$this->CheckUserPermissions("loggedIn", true))
		{
			$html .= $this->CreateLogin();
			if ($this->CurrentPage == "logout")
			{
				$this->App->Logout();
			}
		}
		else
		{
			switch ($this->CurrentPage)
			{
				case "logout": $this->App->Logout();
					$html .= $this->CreateLogin();
					break;
				
				case "":;
				case "overview": $html .= $this->CreateControl_Overview();
					break;

				case "elsockets": $html .= $this->CreateControl_Lights();
					break;

				case "player":;
				case "players":
					$html .= $this->CreateControl_Dbobj("Players", "player", "DbObj_Player", $this->HttpGET("id", true));
					break;
				case "soundfile":
					$html .= $this->CreateControl_Dbobj("Sound files", "soundfile", "DbObj_SoundFile", $this->HttpGET("id", true));
					break;
				case "user":
					$html .= $this->CreateControl_Dbobj("Users", "user", "User", $this->HttpGET("id", true));
					break;
				case "eventgroup":;
				case "eventgroups":
					$html .= $this->CreateControl_Dbobj("Event group templates", "eventgroup", "DbObj_EventGroup", $this->HttpGET("id", true), 0, 0, 0, "EventFormCreator");
					break;
				case "gamegroupstats":
					$html .= $this->CreateControl_Dbobj("Game Group Stats", "gamegroupstats", "DbObj_Gamegroupstats", $this->HttpGET("id", true), 0, 0, 0, "GgsFormCreator");
					break;
				case "timerevent":
					$html .= $this->CreateControl_Dbobj("Timer events", "timerevent", "DbObj_Timerevent", $this->HttpGET("id", true), "EventFormCreator", 'CreateTable_Timerevents', 0, 0,4,8);
					break;

				case "team":;
				case "teams":
					$html .= $this->CreateControl_Dbobj("Teams", "team", "DbObj_Team", $this->HttpGET("id", true), 0, 0, 'TeamFormPlayersHandler');
					break;

				case "event":;
				case "events":
					$html .= $this->CreateControl_Dbobj("Events", "event", "DbObj_Event", $this->HttpGET("id", true));
					break;

				case "game":;
				case "games":
					$html .= $this->CreateControl_Games("Games", "game", "DbObj_Game", $this->HttpGET("id", true), 0,0,"GameFormItemsCorrector");
					break;

				case "fullgame":
					$html .= $this->CreateControl_FullGame($this->HttpGET("id", true));
					break;
                case "testButton":
                    $this->HandleButton_Press($this->HttpGET("id", true));
                    break;

				case "404":;
				default: $html .= $this->BuildPage_404();
			}
		}
		return $html;
	}

	public function DeviceIDValidator($deviceID)
	{
		return true;
	}

	/* Validate callback page after POST request */
	private function SetInitValues()
	{
		$this->GameStatuses[] = 'preGame';
		$this->GameStatuses[] = 'In progress';
		$this->GameStatuses[] = 'Home team won';
		$this->GameStatuses[] = 'Away team won';
		$this->GameStatuses[] = 'Home team won PP';
		$this->GameStatuses[] = 'Away team won PP';
		$this->GameStatuses[] = 'Tie';

		$this->Penalties[] = 'Other';
		$this->Penalties[] = 'Abuse of officials';
		$this->Penalties[] = 'Aggressor penalty';
		$this->Penalties[] = 'Attempt to injure';
		$this->Penalties[] = 'Boarding';
		$this->Penalties[] = 'Broken stick';
		$this->Penalties[] = 'Cross-checking';
		$this->Penalties[] = 'Delay of game';
		$this->Penalties[] = 'Elbowing';
		$this->Penalties[] = 'Fighting';
		$this->Penalties[] = 'Goaltender interference';
		$this->Penalties[] = 'Goaltender leaving the crease';
		$this->Penalties[] = 'Head-butting';
		$this->Penalties[] = 'High-sticking';
		$this->Penalties[] = 'Holding';
		$this->Penalties[] = 'Hooking';
		$this->Penalties[] = 'Illegal equipment';
		$this->Penalties[] = 'Interference';
		$this->Penalties[] = 'Kneeing';
		$this->Penalties[] = 'Roughing';
		$this->Penalties[] = 'Slashing';
		$this->Penalties[] = 'Throwing equipment';
		$this->Penalties[] = 'Too many men on the ice';
		$this->Penalties[] = 'Tripping';
		$this->Penalties[] = 'Unsportsmanlike conduct';

		// available pages
		$this->PAGE["player"] = "player";
		$this->PAGE["overview"] = "overview";
		$this->PAGE["team"] = "team";
		$this->PAGE["game"] = "game";
		$this->PAGE["goal"] = "goal";
		$this->PAGE["assistance"] = "assistance";
		$this->PAGE["event"] = "event";
		$this->PAGE["penalty"] = "penalty";
		$this->PAGE["teamhasplayer"] = "teamhasplayer";
		$this->PAGE["timerevent"] = "timerevent";
		$this->PAGE["user"] = "user";
		$this->PAGE["fullgame"] = "fullgame";
		$this->PAGE["eventgroup"] = "eventgroup";
		$this->PAGE["soundfile"] = "soundfile";
		$this->PAGE["elsockets"] = "elsockets";
		$this->PAGE["eventtimer"] = "eventtimer";
		$this->PAGE["gamegroupstats"] = "gamegroupstats";

		// we are using 5 level system
		// 0 - guests
		// 1 - logged in user - can see overview but cannot control anything
		// 2 - can control already created game, +el.sockets
		// 3 - can create game, teams, players, events and can edit timer events
		// 4 - can also create Game Events and Event Groups
		// 5 - All rights

		$this->Permissions["player"] = 		new Permissions(3,3,3,3);
		$this->Permissions["team"] = 		new Permissions(3,3,3,3);
		$this->Permissions["game"] = 		new Permissions(3,3,3,3);
		$this->Permissions["event"] = 		new Permissions(3,3,3,3);
		$this->Permissions["penalty"] = 	new Permissions(2,2,2,2);
		$this->Permissions["teamhasplayer"]=new Permissions(3,3,3,3);
		$this->Permissions["timerevent"] = 	new Permissions(2,2,2,2);
		$this->Permissions["user"] = 		new Permissions(5,5,5,5);
		$this->Permissions["fullgame"] = 	new Permissions(3,3,3,3);
		$this->Permissions["eventgroup"] = 	new Permissions(4,4,4,4);
		$this->Permissions["soundfile"] = 	new Permissions(4,4,4,4);
		$this->Permissions["elsockets"] = 	new Permissions(2,2,2,2);
		$this->Permissions["overview"] = 	new Permissions(1,2,2,2);
		$this->Permissions["gamestats"] = 	new Permissions(2,2,2,2);
		$this->Permissions["activeGame"] = 	new Permissions(2,2,2,2);
		$this->Permissions["sendCommand"] = new Permissions(2,2,2,2);
		$this->Permissions["timerStop"] = 	new Permissions(2,2,2,2);
		$this->Permissions["gameeventControl"]=new Permissions(2,2,2,2);
		$this->Permissions["loggedIn"] = 	new Permissions(1,1,1,1);
		$this->Permissions["gamegroupstats"] = 	new Permissions(3,3,3,3);
		$this->Permissions["gameroster"] = 	new Permissions(3,3,3,3);

		$this->Commands[] = 'play';
		$this->Commands[] = 'playrandom';
		$this->Commands[] = 'playrandomnb';
		$this->Commands[] = 'stop';
		//$this->Commands[] = 'volume';
		//$this->Commands[] = 'musicvolume';
		$this->Commands[] = 'get';
		$this->Commands[] = 'delete';

		$this->EventTypes[] = 'Break';
		$this->EventTypes[] = 'Game';
		$this->EventTypes[] = 'Penalty';
		$this->EventTypes[] = 'Void';
		$this->EventTypes[] = 'Reserved';
	}

	public function CheckUserPermissions($pagename="", 
			$c=false, $r=false, $u=false, $d=false
		)
	{
		if (isset($this->Permissions[$pagename]))
		{
			return $this->Permissions[$pagename]->UserHasPerm(
				$this->GetUser(), $c, $r, $u, $d
			);
		}
		return false;
	}

	private function Page($page)
	{
		if (isset($this->PAGE[$page]))
		{
			return $this->PAGE[$page];
		}
		else
		{
			return $this->page404;
		}
	}

	/* Static functions *****************************************************/
    private static function ParseFormValue($value, $type)
    {
        switch ($type) {
            case 'datetime-local':
                $date = new DateTime();
                $date->setTimestamp($value);
                $datetimeLocal = $date->format('Y-m-d\TH:i');
                // echo "Parsed datetime-local: " . $datetimeLocal . "\n"; // Debug output
                // exit;
                return $datetimeLocal;
            default:
                return $value;
        }
    }
	private static function GetInputType($value, $comment = "") 
	{
		$normalizedComment = strtolower(trim((string)$comment));
		// Supports: /** text */, /**\n * text\n */, /** @var string */
		if (preg_match('/@var\s+([a-z0-9_-]+)/i', $normalizedComment, $matches)) {
			$normalizedComment = strtolower(trim($matches[1]));
		} elseif (preg_match('/\/\*\*\s*([a-z0-9_-]+)\s*\*\//i', $normalizedComment, $matches)) {
			$normalizedComment = strtolower(trim($matches[1]));
		} elseif (preg_match('/\/\*\*\s*\n\s*\*\s*([a-z0-9_-]+)\s*\n/i', $normalizedComment, $matches)) {
			$normalizedComment = strtolower(trim($matches[1]));
		}

		$comment = $normalizedComment;
        if ($comment === "password") {
            return 'password';
        } elseif ($comment === "textarea") {
            return 'textarea';
        } elseif ($comment === "datetime") {
            return 'datetime-local';
        } elseif ($comment === "date") {
            return 'date';
        } 
        elseif ($comment === "time") 
        {
            return 'time';
        }
        elseif ($comment === "datetime-local") 
        {
            return 'datetime-local';
        }
		elseif ($comment === "text" || $comment === "string" || $comment === "varchar") {
			return 'text';
		}
        elseif ($comment === "email") {
            return 'email';
        } elseif ($comment === "url") {
            return 'url';
        } elseif ($comment === "color") {
            return 'color';
        } elseif ($comment === "file") {
            return 'file';
        } elseif ($comment === "checkbox") {
            return 'checkbox';
        } elseif ($comment === "hidden") {
            return 'hidden';
        } elseif ($comment === "select") {
            return 'select';
        } elseif (is_bool($value)) {
            return 'checkbox';
        } elseif (is_int($value)) {
            return 'number';
        } elseif (is_float($value)) {
            return 'number';
        } 

        if (is_numeric($value)) {
            return 'number';
        } else {
            return 'text';
        }
    }

	private static function GetNonStaticVars($instance) {
		// Get non-static properties from the instance itself (child class)
		$childProperties = array_keys(get_object_vars($instance));

		// Get non-static properties from DbObj class
		$dbObjProperties = array_keys(get_class_vars('DbObj'));
	
		// Compute the difference
		$nonStaticVars = array_diff($childProperties, $dbObjProperties);
	
		return $nonStaticVars;
	}
	/* Static functions END *****************************************************/

	/* Setters ************************************************************************/
	public function Config_ChangeTeamsMirror()
	{
		$this->LocationConfig->teamsMirror = 
		($this->LocationConfig->teamsMirror == 0 ? 1 : 0);
		$this->LocationConfig->Update();
	}
	public function Config_ChangeActiveGame($gameid)
	{
		$this->LocationConfig->activeGame = $gameid;
		$this->LocationConfig->Update();
	}

	/* Getters ************************************************************************/
	public function GetPlayers()
	{
		$players = array();
		$players = DbObj_Player::ReadAll($this->Database, $this->App->GetDbPrefix());
		return $players;
	}
	public function GetPlayerNumberInTeam($playerid, $teamid)
	{
		$dbobj = new DbObj_Teamhasplayer($this->Database, $this->App->GetDbPrefix());
		$dbobj->Find($playerid, $teamid);
		return $dbobj->number;
	}
	public function GetPlayer($id)
	{
		$player = new DbObj_Player($this->Database, $this->App->GetDbPrefix());
		$player->id = intval($id);
		$player->Read();
		return $player;
	}
	public function GetTeams()
	{
		$players = array();
		$players = DbObj_Team::ReadAll($this->Database, $this->App->GetDbPrefix());
		return $players;
	}
	public function GetEventGroups()
	{
		$players = array();
		$players = DbObj_EventGroup::ReadAll($this->Database, $this->App->GetDbPrefix());
		return $players;
	}
	public function GetEventGroupsEvents($eventgroup)
	{
		$eventgroup = intval($eventgroup);
		$events = array();
		$addCond = " WHERE eventgroup=".$eventgroup.' ORDER BY orderingroup';
		$events = DbObj_EventGroupEvent::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
		return $events;
	}
	public function GetggsgamesOfGroup($ggsid)
	{
		$ggsid = intval($ggsid);
		$ggsgames = array();
		$addCond = " WHERE ggs=".$ggsid.' ';
		$ggsgames = DbObj_Ggsgame::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
		return $ggsgames;
	}
	public function GetGGSName($ggsid)
	{
		$ggsid = intval($ggsid);
		$ggs = new DbObj_Gamegroupstats($this->Database, $this->App->GetDbPrefix());
		$ggs->id = $ggsid;
		$ggs->Read();
		if (isset($ggs->displayName) && trim($ggs->displayName) !== '')
			return $ggs->displayName;
		return $ggs->GetName();
	}
	public function GetGameGroupStats($ggsid)
	{
		$ggsid = intval($ggsid);
		$ggs = new DbObj_Gamegroupstats($this->Database, $this->App->GetDbPrefix());
		$ggs->id = $ggsid;
		$ggs->Read();
		return $ggs;
	}
	public function GetGameGroupStatsBySeason($seasonId)
	{
		$seasonId = $this->Database->EscapeString($seasonId);
		$addCond = ' WHERE seasonId="'.$seasonId.'" ORDER BY id';
		return DbObj_Gamegroupstats::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
	}
	public function GetGameGroupStatsBySeasonAndWebpage($seasonId, $webpageId)
	{
		$seasonId = $this->Database->EscapeString($seasonId);
		$webpageId = intval($webpageId);
		$addCond = ' WHERE seasonId="'.$seasonId.'" AND webpageId='.$webpageId.' ORDER BY id';
		return DbObj_Gamegroupstats::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
	}
	public function GetAllGameGroupStats()
	{
		return DbObj_Gamegroupstats::ReadAll($this->Database, $this->App->GetDbPrefix(), ' ORDER BY seasonId, id');
	}
	public function GetAllGameGroupStatsByWebpage($webpageId)
	{
		$webpageId = intval($webpageId);
		return DbObj_Gamegroupstats::ReadAll($this->Database, $this->App->GetDbPrefix(), ' WHERE webpageId='.$webpageId.' ORDER BY seasonId, id');
	}
	public function GetTimerEvents($gameid, $onlyShowableEvents = true)
	{
		$gameid = intval($gameid);
		$events = array();
		$addCond = " WHERE gameid=".$gameid.'';
		if ($onlyShowableEvents)
			$addCond .= ' AND type!='.$this->PenaltyEventType.'';
		$addCond .= ' ORDER BY orderingroup';
		$events = DbObj_Timerevent::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
		return $events;
	}
	public function GetGameeventPenalties($gameid)
	{
		$gameid = intval($gameid);
		$events = array();
		$addCond = " WHERE gameid=".$gameid.' AND paralelEvent=1 ORDER BY id';
		$events = DbObj_Gameevent::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
		return $events;
	}
	public function GetGameevents($gameid)
	{
		$gameid = intval($gameid);
		$events = array();
		$addCond = " WHERE gameid=".$gameid.' ORDER BY id';
		$events = DbObj_Gameevent::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
		return $events;
	}
	public function GetTimerevent($id)
	{
		$dbobj = new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($id);
		$dbobj->Read();
		return $dbobj;
	}
	public function GetSoundFiles()
	{
		$dbobjs = array();
		$dbobjs = DbObj_SoundFile::ReadAll($this->Database, $this->App->GetDbPrefix());
		return $dbobjs;
	}

	public function GetTeam($id)
	{
		$dbobj = new DbObj_Team($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($id);
		$dbobj->Read();
		return $dbobj;
	}
	public function GetGames($addCond = "")
	{
		$dbobjs = array();
		$dbobjs = DbObj_Game::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
        return $dbobjs;
	}
	public function GetGame($id)
	{
		$dbobj = new DbObj_Game($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($id);
		$dbobj->Read();
		return $dbobj;
	}
	public function GetGameScore($gameid)
	{
		$dbobj = new DbObj_Game($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($gameid);
		$dbobj->Read();
		$team1score = $this->GetTeamNumberOfGoalsInGame($dbobj->id, $dbobj->team1);
		$team2score = $this->GetTeamNumberOfGoalsInGame($dbobj->id, $dbobj->team2);
		return $team1score.":".$team2score;
	}
	public function GetGameEvent($id)
	{
		$dbobj = new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($id);
		$dbobj->Read();
		return $dbobj;
	}
	public function GetGameEventByGameid($gameid)
	{
		$dbobj = new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
		$dbobj->gameid = intval($gameid);
		$addCond = " AND paralelEvent=0 ";
		$dbobj->Find($addCond);
		return $dbobj;
	}
	public function GetSoundFile($id)
	{
		$dbobj = new DbObj_SoundFile($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($id);
		$dbobj->Read();
		return $dbobj;
	}
	private function GetAfterGameStatus($statusId)
	{
		if (isset($this->GameStatuses[$statusId]))
		{
			return $this->GameStatuses[$statusId];
		}
		return $this->GameStatuses[0];
	}
	private function GetPenalty($penaltyId)
	{
		if (isset($this->Penalties[$penaltyId]))
		{
			return $this->Penalties[$penaltyId];
		}
		return $this->Penalties[0];
	}
	private function GetPenaltyTypes()
	{
		$penalties = array();
		$penalties = $this->Penalties;
		return $penalties;
	}
	private function GetGoalTypes()
	{
		$goalTypes = array();
		$goalTypes[0] = 'Normal goal';
		$goalTypes[1] = 'Power-play goal';
		$goalTypes[2] = 'Short-handed goal';
		$goalTypes[3] = 'Empty-net goal';
		$goalTypes[4] = 'Penalty-shot goal';
		return $goalTypes;
	}
	private function GetCommands()
	{
		$commands = array();
		$commands = $this->Commands;
		return $commands;
	}
	private function IsValidCommand($incommand)
	{
		$commands = $this->GetCommands();
		foreach ($commands as $command)
		{
			if ($command == $incommand)
			{
				return true;
			}
		}
		return false;
	}
	private function GetEventTypes()
	{
		$eventtypes = array();
		$eventtypes = $this->EventTypes;
		return $eventtypes;
	}
	private function GetEventType($id)
	{
		if (isset($this->EventTypes[$id]))
		{
			return $this->EventTypes[$id];
		}
		return $this->EventTypes[0];
	}
	public function GetActiveGameevent($gameid)
	{
		$dbobj = new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
		$dbobj->gameid = $gameid;
		$addCond = " AND paralelEvent=0 ";
		$dbobj->Read($addCond);
		$dbobj->Read();
		return $dbobj;
	}
	private function GetGoalTimestampFromActiveEvent($gameid)
	{
		$gameid = intval($gameid);
		if ($gameid <= 0)
			return 0;

		$activeGameEvent = $this->GetGameEventByGameid($gameid);
		if (!isset($activeGameEvent->id) || intval($activeGameEvent->id) <= 0)
			return 0;

		$timerEvent = $this->GetTimerevent($activeGameEvent->event);
		if (!isset($timerEvent->type) || intval($timerEvent->type) !== 1)
			return 0;
		$currentOrderInGroup = intval($timerEvent->orderingroup);
		$currentTimerEventId = intval($timerEvent->id);

		$elapsed = 0;
		if (intval($activeGameEvent->eventTimeStart) > 0)
		{
			$referenceTime = intval($activeGameEvent->lastRecordedTime);
			if (intval($this->LocationConfig->timerActive) > 0)
			{
				$referenceTime = time();
			}
			if ($referenceTime <= 0)
				$referenceTime = time();

			$elapsed =
				$referenceTime -
				intval($activeGameEvent->eventTimeStart) -
				intval($activeGameEvent->eventTimeOffset);
			if ($elapsed < 0)
				$elapsed = 0;
		}

		$previousPeriodsDuration = 0;
		$timerEvents = $this->GetTimerEvents($gameid, false);
		foreach ($timerEvents as $te)
		{
			if (intval($te->type) !== 1)
				continue;

			$teOrderInGroup = intval($te->orderingroup);
			$teId = intval($te->id);

			if ($currentOrderInGroup > 0)
			{
				if ($teOrderInGroup < $currentOrderInGroup)
				{
					$previousPeriodsDuration += intval($te->duration);
				}
			}
			else if ($teId < $currentTimerEventId)
			{
				$previousPeriodsDuration += intval($te->duration);
			}
		}

		return intval($previousPeriodsDuration + $elapsed);
	}
	private function GetGoalPeriodFromActiveEvent($gameid)
	{
		$gameid = intval($gameid);
		if ($gameid <= 0)
			return 0;

		$activeGameEvent = $this->GetGameEventByGameid($gameid);
		if (!isset($activeGameEvent->id) || intval($activeGameEvent->id) <= 0)
			return 0;

		$timerEvent = $this->GetTimerevent($activeGameEvent->event);
		if (!isset($timerEvent->type) || intval($timerEvent->type) !== 1)
			return 0;

		$period = intval($timerEvent->periodNumber);
		if ($period <= 0)
			return 0;
		return $period;
	}
	public function GetTeamNumberOfGoalsInGame($gameid, $teamid)
	{
		$goalsCount = 0;
		$addCond = ' WHERE team='.$teamid.' AND game='.$gameid.' ';
		$goalsCount = DbObj_Goal::GetNumberOfGoals($this->Database, $this->App->GetDbPrefix(),$addCond);
		return $goalsCount;
	}
	public function GetTeamPenaltySeconds($gameid, $teamid)
	{
		$count = 0;
		$addCond = ' WHERE team='.$teamid.' AND game='.$gameid.' ';
		$count = DbObj_Penalty::GetNumberOfPenalties($this->Database, $this->App->GetDbPrefix(),$addCond);
		return $count;
	}
	public function IsQuietHour()
	{
		// Set the start and end times for the desired daytime range
		$start_time = strtotime($this->LocationConfig->quietHourStart); // 8:00 AM
		$end_time = strtotime($this->LocationConfig->quietHourEnd);  // 5:00 PM

		// Get the current time
		$current_time = strtotime(date('H:i'));

		//echo'Start:'.$start_time.' End:'.$end_time.' Current:'.$current_time;exit;

		// Check if the current time is within the desired range
		if ($current_time >= $start_time && $current_time <= $end_time)
		{
			return true;
		} 
		else
		{
			return false;
		}
	}
	/* Getters END *******************************************************************/
	public function GgsFormCreator($className, $pagename, $id=0)
	{
		// GetGames()
		// GetggsgamesOfGroup($ggsid)

		$queryString = $_SERVER['QUERY_STRING'];
		$html = '';
		$id = intval($id);

		$allGames = array();
		$ggsGamesOfGroup = array();

		$dbobj = new $className($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = $id;
		$dbobj->Read();
		
		if ($dbobj->GetName() != '')
		{
			$allGames = $this->GetGames();
			$ggsGamesOfGroup = $this->GetggsgamesOfGroup($id);
		}
		else // dont need to show form if GGS is not created
		{
			return '';
		}
		$formModule = new Form($this->SiteName."?".$queryString, "post");
		$formItemAction = new FormItem("hidden", "action", "gamegroupstats");
		$formItemFlag = new FormItem("hidden", "ggsgames", "1");
		$formItemRedirect = new FormItem("hidden", "redirect", $this->Page($pagename));
		$formItemId = new FormItem("hidden", "id", $id);

		$formModule->AddItem($formItemAction);
		$formModule->AddItem($formItemFlag);
		$formModule->AddItem($formItemRedirect);
		$formModule->AddItem($formItemId);

		$formModule->FormName = "Games in group";
		$formModule->ContentInsideStart = '<a href="'.$this->SiteName.'?p=STATS&id='.$id.'&table=1" target="_blank">Open Stats</a>';

		foreach ($allGames as $game)
		{
			$formItemCheckbox = new FormItem
			("checkbox", "ggsgames[]", $game->id, '', '<small>'.$game->GetName().'<small>');
			foreach ($ggsGamesOfGroup as $ggsgame)
			{
				if ($ggsgame->game == $game->id)
					$formItemCheckbox->Checked = true;
			}
			reset($ggsGamesOfGroup);
			$formModule->AddItem($formItemCheckbox);
		}
		$html .= $formModule->GetHTML();
		return $html;
	}
	public function EventFormCreator($className, $pagename, $id=0)
	{
		$queryString = $_SERVER['QUERY_STRING'];
		$html = '';
		$id = intval($id);
		$eventtemplate = 0; // eventgroup is eventtemplate, eventtimer is NOT event template
		if ($pagename == 'eventgroup')
		{
			if ($id == 0)
				return '';
			$className = 'DbObj_EventGroupEvent';
			$eventtemplate = $id;
			$id = $this->HttpGET("eid", true); // get ID if edited object
		}
		$dbobj = new $className($this->Database, $this->App->GetDbPrefix());
		if ($id > 0)
		{
			$dbobj->id = $id;
			$dbobj->Read();
		}
		$formModule = new Form($this->SiteName."?".$queryString, "post");
		$formItemAction = new FormItem("hidden", "action", "eventtimer");
		$formItemRedirect = new FormItem("hidden", "redirect", $this->Page($pagename));
		$formItemId = new FormItem("hidden", "id", $id);
		$formItemTemplate = new FormItem("hidden", "template", $eventtemplate);
		$formModule->FormName = "Event";
		$formItemOrder = new FormItem("number","order", $dbobj->orderingroup, "Order", "Order");
		$formItemPeriod = new FormItem("number","periodNumber", $dbobj->periodNumber, "Period", "Period");
		$formItemDuration = new FormItem("number","duration", $dbobj->duration, "Duration[s]", "Duration[s]");
		$formItemType = new FormItem("select","type", "", "Event type", "Event type");
		$formItemName = new FormItem("text","name", $dbobj->name, "Name", "Name");
		$formItemNote = new FormItem("text","note", $dbobj->note, "Note", "Note");
		$formItemSoundStart = new FormItem("select","soundStart", "", "Start sound", "Start sound");
		$formItemSoundEnd = new FormItem("select","soundEnd", "", "End sound", "End sound");
		$formItemStopTimerAfter = new FormItem
			("checkbox", "stopTimerAfter", '1', 'Stop timer after', 'Stop timer after');
		if ($dbobj->stopTimerAfter > 0)
		{
			$formItemStopTimerAfter->Checked = true;
		}
        $formItemDisableMusic = new FormItem
            ("checkbox", "disableMusic", '1', 'Disable music', 'Disable music');
		if ($dbobj->disableMusic > 0)
		{
			$formItemDisableMusic->Checked = true;
		}
		
		$formItemOrder->ClassName='narrow';
		$formItemPeriod->ClassName='narrow';
		$formItemDuration->ClassName='narrow';

		$eventtypes = $this->GetEventTypes();
		foreach ($eventtypes as $index => $eventtype)
		{
			$option = new SelectOption($eventtype, $index);
			if ($dbobj->type == $index)
				$option->Selected = true;
			$formItemType->SelectOptions[] = $option;
		}

		$formItemGame = new FormItem("select","gameid", "", 'Game', 'Game');
		$formItemSoundAlmostEnd = new FormItem("number","almostEndSound", 0, "Hurry up", "AES");
		if ($eventtemplate == 0)
		{
			$games = $this->GetGames();
			$formItemSoundAlmostEnd->Value = $dbobj->almostEndSound;
			foreach ($games as $game)
			{
				$option = new SelectOption($game->GetName(), $game->id);
				if ($dbobj->gameid == $game->id) 
					$option->Selected = true;
				$formItemGame->SelectOptions[] = $option;
			}
		}

		$soundFiles = $this->GetSoundFiles();
		$emptySoundFile = new DbObj_SoundFile($this->Database, $this->App->GetDbPrefix());
		$emptySoundFile->id = 0;
		$emptySoundFile->name = '- nothing -';
		array_unshift( $soundFiles, $emptySoundFile);
		foreach ($soundFiles as $soundFile)
		{
			$option = new SelectOption($soundFile->GetName(), $soundFile->id);
			if ($dbobj->soundStart == $soundFile->id)
				$option->Selected = true;
			$formItemSoundStart->SelectOptions[] = $option;
		}
		reset($soundFiles);
		foreach ($soundFiles as $soundFile)
		{
			$option = new SelectOption($soundFile->GetName(), $soundFile->id);
			if ($dbobj->soundEnd == $soundFile->id)
				$option->Selected = true;
			$formItemSoundEnd->SelectOptions[] = $option;
		}
		reset($soundFiles);

		$formModule->AddItem($formItemAction);
		$formModule->AddItem($formItemRedirect);
		$formModule->AddItem($formItemId);
		$formModule->AddItem($formItemTemplate);
		$formModule->AddItem($formItemOrder);
		$formModule->AddItem($formItemPeriod);
		if ($eventtemplate == 0)
			$formModule->AddItem($formItemGame);
		$formModule->AddItem($formItemDuration);
		$formModule->AddItem($formItemType);
		$formModule->AddItem($formItemName);
		$formModule->AddItem($formItemNote);
		$formModule->AddItem($formItemSoundStart);
		$formModule->AddItem($formItemSoundEnd);
		if ($eventtemplate == 0)
			$formModule->AddItem($formItemSoundAlmostEnd);
		$formModule->AddItem($formItemStopTimerAfter);
        $formModule->AddItem($formItemDisableMusic);

		if ($eventtemplate != 0)
		{
			$html .= $this->CreateTable_EventsInGroup($eventtemplate).'<br>';
		}
		$html .= $formModule->GetHTML();

		return $html;
	}
	public function CreateTable_EventsInGroup($eventgroupid, $dbAddCond="", $tableOverhead=true)
	{
		$pageName = "eventgroup";
		$eventgroupid = intval($eventgroupid);
		$dbAddCond = "WHERE eventgroup=".$eventgroupid.' ORDER BY orderingroup';
		$rows = DbObj_EventGroupEvent::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		$table = new Table();
		$table->FontSize = 10;
		$table->AddContentBefore = '<h4 style="color:#0c0249;">Events in group</h4>';
		$table->Overhead = $tableOverhead;

		$tableHead = new TableRow("Order", "Type", "Duration", "Name", "Note", "Period",
		"Sound start", "Sound end", "Music", "Stop", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		foreach ($rows as $row)
		{
			$editLink =
			'<a href="?p='.$this->Page($pageName).'&id='.$eventgroupid.'&eid='.$row->id.'">
			'.$row->name.'</a>';
			if ($this->HttpGET("eid", true) == $row->id)
			{
				$editLink = '<b>> '.$editLink.'</b>';
			}
			$deleteLink = 
			'[<a href="?p='.$this->Page($pageName).'&id='.$eventgroupid.'&delete=1&eve='.$row->id.'"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';
			$table->AddRow(new TableRow(
				' '.$row->orderingroup, 
				' '.$this->GetEventType($row->type),
				' '.$row->duration, 
				' '.$editLink, $row->note, $row->periodNumber,
				' '.$this->GetSoundFile($row->soundStart)->GetName(),
				' '.$this->GetSoundFile($row->soundEnd)->GetName(),
                ' '.($row->disableMusic ? 'No' : 'Yes'),
				' '.$row->stopTimerAfter, 
				' '.$deleteLink));
		}
		return $table->GetHTML();
	}
	public function CreateTable_Timerevents($className, $pageName, $id, $dbAddCond="", $tableOverhead=true)
	{
		$pageName = "timerevent";
		$newEntryLink = '<a href="?p='.$this->Page($pageName).'&id=0">New entry</a><br><br>'.PHP_EOL;
		$dbAddCond = "ORDER BY gameid DESC, orderingroup";
		$rows = DbObj_Timerevent::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		$table = new Table();
		$table->FontSize = 10;
		$table->AddContentBefore = '<h4 style="color:#0c0249;">Events in group</h4>'.
		$newEntryLink;
		$table->Overhead = $tableOverhead;

		$tableHead = new TableRow("ID", "Game", "Order", "Type", "Period", "Duration", "Name", "Note", 
		"Sound start", "Sound end", "Music", "Stop", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		foreach ($rows as $row)
		{
            if ($this->GetGame($row->gameid)->afterGameStatus > 1) {
                continue; // this game has ended, skip
            }
			$editLink = 
			'<a href="?p='.$this->Page($pageName).'&id='.$row->id.'">'.$row->GetName().'</a>';
			$deleteLink = 
			'[<a href="?p='.$this->Page($pageName).'&id='.$row->id.'&delete=1"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';

			$gameName = '<a href="?p='.$this->Page("game").'&id='.$row->gameid.'">'.$this->GetGame($row->gameid)->name.'</a>';
			$table->AddRow(new TableRow(
				' '.$row->id, 
				' '.$gameName,
				' '.$row->orderingroup, 
				' '.$this->GetEventType($row->type),
				' '.$row->periodNumber,
				' '.$row->duration, 
				' '.$editLink, 
				' '.$row->note, 
				' '.$this->GetSoundFile($row->soundStart)->GetName(),
				' '.$this->GetSoundFile($row->soundEnd)->GetName(),
                ' '.($row->disableMusic ? 'No' : 'Yes'),
				' '.$row->stopTimerAfter, 
				' '.$deleteLink));
		}
		return $table->GetHTML();
	}
	public function TeamFormPlayersHandler($formModule, $id=0)
	{
		//echo "Function:"; exit;
		$dbAddCond = 'WHERE team='.intval($id). "";

		$soundFiles = $this->GetSoundFiles();
		foreach ($formModule->Items as $item)
		{
			if ($item->Name == 'nameSoundFile' || $item->Name == 'goalHorn' || $item->Name == 'songFile')
			{
				$item->Type = 'select';
				$item->SelectOptions = array();
				foreach ($soundFiles as $soundFile)
				{
					$option = new SelectOption($soundFile->GetName(), $soundFile->id);
					if ($id > 0 && $item->Value == $option->Value)
					{
						$option->Selected = true;
					}
					$item->SelectOptions[] = $option;
				}
				reset($soundFiles);
			}
		}

		if ($id > 0)
		{
			$formModule->ContentInsideEnd = 
			'<div class="listTable">Players in team:<br>'.
			$this->CreateTable_DynamicPlayersInTeam($formModule, $id, $dbAddCond, false).
			'</div>';
		}
	}
	public function CompareByName($a, $b) 
	{
		return strcmp($a->GetName(), $b->GetName());
	}
	public function THP_CompareByName($a, $b) 
	{
		return strcmp($this->GetPlayer($a->player)->GetName(), $this->GetPlayer($b->player)->GetName());
	}
	public function THP_CompareByNumber($a, $b) 
	{
		// If $x is equal to $y it returns 0 
        if ($a->number == $b->number) 
            return 0; 
      
        // if x is less than y then it returns -1 
        // else it returns 1     
        if ($a->number < $b->number) 
            return -1; 
        else
            return 1; 
	}
	public function CreateTable_DynamicPlayersInTeam($formModule, $teamid, $dbAddCond="", $tableOverhead=false)
	{
		$pageName = "team"; // set page for links inside
		$teamid = intval($teamid);

		/* Read players */
		$playersInTeam = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		$allExistingPlayers = $this->GetPlayers(); // read all existing players
		usort($allExistingPlayers, array($this, "CompareByName")); // sort players by name

		$formItemNewPlayerAddButton = new FormItem("button","addPlayer", "Add player");
		$formItemNewPlayerAddButton->TableCompatible = false;
		$formItemNewPlayerAddButton->Onclick = "addNewPlayer()";

		/* Create Table */
		$table = new Table();
		$table->Id = "players-container";
		$table->Overhead = $tableOverhead;
		$tableHead = new TableRow("#", "Name", $formItemNewPlayerAddButton->GetHTML());
		$tableHead->Thead = true;
		$table->AddRow($tableHead); // add head
		/* Table is created */

		/* Prepare new dynamic item */
		$formItemNewPlayers = new FormItem("select","players[]", "");
		foreach ($allExistingPlayers as $player)
		{
			$formItemNewPlayers->SelectOptions[] = new SelectOption($player->GetName(), $player->id);
		}
		reset($allExistingPlayers); // reset pointer back to start of array
		$formItemNewPlayerNum = new FormItem("number","numbers[]", "", "#");
		$formItemNewPlayerNum->ClassName='narrow';
		$newDynamicTableRow = new TableRow();
		$formItemPlayerRemoveButton = new FormItem("button","", "Remove");
		$formItemPlayerRemoveButton->Onclick = "removePlayer(this)";
		$newDynamicTableRow->RawRow = 
			$formItemNewPlayerNum->GetHTML().
			$formItemNewPlayers->GetHTML()
			.$formItemPlayerRemoveButton->GetHTML()
			;
		//$table->AddRow($newDynamicTableRow);

		/* Prepare existing players in team */
		usort($playersInTeam, array($this, "THP_CompareByName"));
		foreach ($playersInTeam as $playerInTeam)
		{
			$player = $this->GetPlayer($playerInTeam->player);
			$idFormItem = new FormItem("hidden", "teamHasPlayer[]", $playerInTeam->id);
			$idFormItem->TableCompatible=false;
			$pidFormItem = new FormItem("hidden", "players[]", $player->id);
			$pidFormItem->TableCompatible=false;
			$formItemPlayers = new FormItem("select","players[]", "");
			foreach ($allExistingPlayers as $existingPlayer)
			{
				$playerOptionItem = new SelectOption($existingPlayer->GetName(), $existingPlayer->id);
				if ($playerInTeam->player == $existingPlayer->id)
				{
					$playerOptionItem->Selected = true;
				}
				$formItemPlayers->SelectOptions[] = $playerOptionItem;
			}
			reset($allExistingPlayers); // reset pointer back to start of array
			$formItemPlayerNum = new FormItem("number","numbers[]", $playerInTeam->number, "#");
			$formItemPlayerNum->ClassName='narrow';
			$formItemPlayerRemoveButton = new FormItem("button","", "Remove");
			$formItemPlayerRemoveButton->Onclick = "removePlayer(this)";
			$tableRow = new TableRow();
			
			$editLink = 
			'<td><a href="?p='.$this->Page("player").'&id='.$player->id.'">'.$player->GetName().'</a></td>';
			$deleteLink = 
			'<td>[<a href="?p='.$this->Page($pageName).'&id='.$teamid.'&delete=1&ths='.$playerInTeam->id.'"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]</td>';
			$tableRow->RawRow = 
				$idFormItem->GetHTML()
				.$pidFormItem->GetHTML()
				.$formItemPlayerNum->GetHTML()
				//.$formItemPlayers->GetHTML()
				.$editLink
				.$deleteLink
				//.$formItemPlayerRemoveButton->GetHTML()
				;
			$table->AddRow($tableRow);
		}

		$formModule->ContentAfter .= $this->PlayersInTeam_JS($newDynamicTableRow->RawRow);
		//$formModule->ContentBefore .= $this->PlayersInTeam_JS($newDynamicTableRow->RawRow);
		return $table->GetHTML();
	}
	public function PlayersInTeam_JS($dynamicObjectHtml)
	{
		$jsScript = '
		<script>
    let itemCount = 1;

	function removePlayer(button)
	{
      	const container = document.getElementById("players-container");
      	const tdNode = button.parentNode;
      	const itemToRemove = tdNode.parentNode;
      	container.removeChild(itemToRemove);
    }

    function addNewPlayer()
	{
      	itemCount++;

      	const container = document.getElementById("players-container");
      	const newItem = document.createElement("tr");

      	newItem.innerHTML = `
		';
			$jsScript  .= $dynamicObjectHtml;
			//$html .= '<button type="button" onclick="removeItem(this)">Remove</button>';
			$jsScript .= '
		`;
    	container.appendChild(newItem);
    }
	</script>
		';
		return $jsScript;
	}
	public function CreateTable_PlayersInTeam($formModule, $teamid, $dbAddCond="", $tableOverhead=false)
	{
		$pageName = "team";
		$teamid = intval($teamid);
		$rows = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		$table = new Table();
		$table->Overhead = $tableOverhead;

		$formItemPlayers = new FormItem("select","players", "");
		$formItemPlayerNum = new FormItem("number","numbers", "", "#");
		$formItemPlayerNum->ClassName='narrow';
		$players = $this->GetPlayers();
		foreach ($players as $player)
		{
			$formItemPlayers->SelectOptions[] = new SelectOption($player->GetName(), $player->id);
		}
		$formModule->AddDynamicItem($formItemPlayerNum);
		$formModule->AddDynamicItem($formItemPlayers);
		$formModule->AddDynamicItemText = "Add player";

		$tableHead = new TableRow("#", "Name", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		foreach ($rows as $row)
		{
			$editLink = 
			$this->GetPlayer($row->player)->GetName().'';
			$deleteLink = 
			'[<a href="?p='.$this->Page($pageName).'&id='.$teamid.'&delete=1&ths='.$row->id.'"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';
			$table->AddRow(new TableRow($row->number, $editLink, $deleteLink));
		}
		return $table->GetHTML();
	}

	public function GameFormItemsCorrector($formModule, $id=0)
	{
		$formModule->FormName .= " (".$this->GetGameScore($id).")";
		$teams = $this->GetTeams();
		$eventGroups = $this->GetEventGroups();
		foreach ($formModule->Items as $item)
		{
			if ($item->Name == 'team1' || $item->Name == 'team2')
			{
				$item->Type = 'select';
				$item->SelectOptions = array();
				foreach ($teams as $team)
				{
					$option = new SelectOption($team->GetName(), $team->id);
					if ($id > 0 && $item->Value == $option->Value)
					{
						$option->Selected = true;
					}
					$item->SelectOptions[] = $option;
				}
				reset($teams);
			}
			else if ($item->Name == 'afterGameStatus')
			{
				$item->Type = 'select';
				$options = array();

				foreach ($this->GameStatuses as $index => $status) 
				{
					$options[] = new SelectOption($status, $index);
				}
				if ($id > 0 && $item->Value < count($this->GameStatuses) && $item->Value >= 0)
				{
					$options[$item->Value]->Selected = true;
				}
				$item->SelectOptions = $options;
			}
			else if ($item->Name == 'eventGroup')
			{
				$item->Type = 'select';
				$item->SelectOptions = array();
				if ($id>0)
				{
					$item->Disabled = true;
				}
				foreach ($eventGroups as $eventGroup)
				{
					$option = new SelectOption($eventGroup->GetName(), $eventGroup->id);
					if ($id > 0 && $item->Value == $option->Value)
					{
						$option->Selected = true;
					}
					$item->SelectOptions[] = $option;
				}
				reset($teams);
			}
		}
	}
	// Games controls
	public function CreateControl_FullGame($id=0)
	{
		$game = $this->GetGame($id);
		if ($game->name == '') // invalid game
		{
			return;
		}

		$module = new Module_GenericContent();
		$module->Headline = $game->GetName()." (".$this->GetGameScore($id).")";
		$module->NoticeText = $this->NoticeText;

		$formHtml = "";
		$team1Html = "";
		$team2Html = "";
		$formHtml = $this->CreateForm_Dbobj("DbObj_Game", "fullgame", $id, "GameFormItemsCorrector");
		$team1Html = $this->CreateTable_GameTeam($id, $game->team1);
		$team2Html = $this->CreateTable_GameTeam($id, $game->team2);

		$team1RosterHtml = $this->CreateTable_GameTeamRoster($id, $game->team1);
		$team2RosterHtml = $this->CreateTable_GameTeamRoster($id, $game->team2);

		$clearGameLink = 
			'<a href="?p='.$this->Page("fullgame").'&id='.$id.'&delete=1&clearGame=1"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>Delete all goals and penalties</a>';

		$module->TopContent = '<button class="collapsible">Game details</button>
		<div class="game-details">'.$formHtml.$clearGameLink."</div>";

		$module->AddContent($team1Html.'<br>'.$team1RosterHtml, 6, 
			$this->GetTeam($game->team1)->GetName().'
			 - '.$this->GetTeamNumberOfGoalsInGame($game->id, $game->team1).'');

		$module->AddContent($team2Html.'<br>'.$team2RosterHtml, 6, 
			$this->GetTeam($game->team2)->GetName().'
			 - '.$this->GetTeamNumberOfGoalsInGame($game->id, $game->team2).'');
		
		return $module->GetHTML();
	}

	private function GetPlayersFromTeam($id= 0)
	{
		$players = array();
		$dbAddCond = 'WHERE team='.intval($id);
		$ths = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		foreach($ths as $row)
		{
			$players[] = $this->GetPlayer($row->player);
		}
		return $players;
	}
	public function CreateTable_GameTeam($gameid=0, $teamid=0, $pagename='fullgame', $hornHotkey='')
	{
		$gameid = intval($gameid);
		$teamid = intval($teamid);
		$team = $this->GetTeam($teamid);
		$game = $this->GetGame($gameid);
		$html = "";
		$queryString = $_SERVER['QUERY_STRING'];

		/* GOALS */
		$html .= $this->PrintGameGoals_Table($gameid, $teamid, $pagename, $hornHotkey);
		$html .= '<br>';
		/* PENALTIES */
		$html .= $this->PrintGamePenalties_Table($gameid, $teamid, $pagename);

		return $html;
	}
	public function CreateTable_GameTeamRoster($gameid=0, $teamid=0, $pagename='fullgame', $hornHotkey='')
	{
		$gameid = intval($gameid);
		$teamid = intval($teamid);
		$team = $this->GetTeam($teamid);
		$game = $this->GetGame($gameid);
		$html = "";
		$queryString = $_SERVER['QUERY_STRING'];

		/* Roster */
		$html .= $this->PrintGameRoster_Table($gameid, $teamid, $pagename);

		return $html;
	}
	public function PrintGameGoals_Table($gameid, $teamid=0,$pagename="fullgame", $hornHotkey='')
	{
		$html = "";
		$gameid = intval($gameid);
		$teamid = intval($teamid);
		$teamObj = $this->GetTeam($teamid);
		$goalHornFile = $this->GetSoundFile($teamObj->goalHorn);
		$queryString = $_SERVER['QUERY_STRING'];

		$formButton = new Form($this->SiteName."?".$queryString, "post");
		$formButton->Overhead = false;
		$formButton->InternalTable = false;
		$formButton->SubmitBtn = false;
		$formButtonItems = array();
		$formButtonItems[] = new FormItem("hidden", "action", "sendCommand");
		$formButtonItems[] = new FormItem("hidden", "id", $gameid);
		$formButtonItems[] = new FormItem("hidden", "redirect", $this->Page($pagename));
		$formButtonItems[] = new FormItem("hidden", "command", "play");
		$formButtonItems[] = new FormItem("hidden", "parameter0", $goalHornFile->name);
		if ($pagename != 'fullgame')
		{
			$hotkeyLabel = ($hornHotkey!=""?' (ALT+'.strtoupper($hornHotkey).')':"");
			$buttonHorn = new FormItem("button","submit", 'GOAL HORN'.$hotkeyLabel);
			$buttonHorn->Accesskey = $hornHotkey;
			$formButtonItems[] = $buttonHorn;
		}
		foreach ($formButtonItems as $item)
		{
			$item->TableCompatible = false;
			$formButton->AddItem($item);
		}
		$formButton->ContentInsideStart = '<h5>';
		$formButton->ContentInsideEnd = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Goals</h5><br>';

		$goals = GameGoal::ReadAll($gameid, $teamid, $this->Database, $this->App->GetDbPrefix(), " ORDER BY goalid ASC");
		$table = new Table();
		$table->FontSize = 12;
		$table->AddContentBefore = $formButton->GetHTML();
		$table->Overhead = true;
		$table->ForceBorders=true;
		$tableHead = new TableRow("Time", "Players", "Type", " ", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		$table->AddRow($this->GetFormGameGoalPlayer($pagename, $gameid, $teamid));
		$html .= $table->GetHTML();
		if (count($goals) > 0)
		{
			$html .= '<br>';
			$html .= $this->GetFormGameGoalsBulkEdit($pagename, $gameid, $teamid, $goals);
		}
		return $html;
	}
	private function BuildGoalPlayerSelectHtml($name, $teamid, $selectedPlayerId)
	{
		$dbAddCond = 'WHERE team='.intval($teamid). "";
		$playersInTeam = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		usort($playersInTeam, array($this, "THP_CompareByNumber"));

		$html = '<select name="'.$name.'" class="option-small">';
		$html .= '<option value="0"'.(intval($selectedPlayerId) === 0 ? ' selected' : '').'>#00 UNKNOWN</option>';
		foreach ($playersInTeam as $playerIT)
		{
			$playerObj = $this->GetPlayer($playerIT->player);
			$selected = (intval($playerIT->player) === intval($selectedPlayerId)) ? ' selected' : '';
			$html .= '<option value="'.intval($playerIT->player).'"'.$selected.'>#'.intval($playerIT->number).' '.$playerObj->GetName().'</option>';
		}
		$html .= '</select>';
		return $html;
	}
	private function BuildGoalTypeSelectHtml($name, $selectedGoalType)
	{
		$goalTypes = $this->GetGoalTypes();
		$html = '<select name="'.$name.'" class="option-small">';
		foreach ($goalTypes as $index => $goalType)
		{
			$selected = ($index === $selectedGoalType) ? ' selected' : '';
			$html .= '<option value="'.intval($index).'"'.$selected.'>'.$goalType.'</option>';
		}
		$html .= '</select>';
		return $html;
	}
	private function BuildGoalPeriodInputHtml($name, $gameid, $selectedGoalPeriod)
	{
		if (intval($selectedGoalPeriod) <= 0)
		{
			$selectedGoalPeriod = $this->GetGoalPeriodFromActiveEvent($gameid);
			if (intval($selectedGoalPeriod) <= 0)
				$selectedGoalPeriod = 1;
		}
		return '<input type="number" class="narrow" min="1" step="1" name="'.$name.'" value="'.intval($selectedGoalPeriod).'">';
	}
	private function GetFormGameGoalsBulkEdit($pagename, $gameid, $teamid, $goals)
	{
		$queryString = $_SERVER['QUERY_STRING'];
		$html = '';
		$html .= '<div style="background:#fff; padding:10px; border:1px solid #ddd;">';
		$html .= '<h6>Edit existing goals and submit all at once</h6>';
		$html .= '<form method="post" action="'.$this->SiteName.'?'.$queryString.'">';
		$html .= '<input type="hidden" name="action" value="gamestats">';
		$html .= '<input type="hidden" name="bulkGoals" value="1">';
		$html .= '<input type="hidden" name="redirect" value="'.$this->Page($pagename).'">';
		$html .= '<input type="hidden" name="gameid" value="'.intval($gameid).'">';
		$html .= '<input type="hidden" name="teamid" value="'.intval($teamid).'">';
		$html .= '<table border="1" style="font-size:12px; width:100%; border-collapse: collapse; background:#fff;">';
		$html .= '<tr><th>Time</th><th>Players</th><th>Type</th><th>Period</th><th>Delete</th></tr>';

		foreach ($goals as $goal)
		{
			$goalid = intval($goal->goalid);
			$deleteLink =
			'[<a href="?p='.$this->Page($pagename).'&id='.$gameid.'&delete=1&goal='.$goalid.'" onclick="return confirm(\'Are you sure you want to delete the entry?\')">x</a>]';
			$playersColumn = '';
			$playersColumn .= '<div><strong>Goal:</strong><br>'.$this->BuildGoalPlayerSelectHtml('goals['.$goalid.']', $teamid, intval($goal->player_goal)).'</div>';
			$playersColumn .= '<div style="margin-top:4px;"><strong>Assist1:</strong><br>'.$this->BuildGoalPlayerSelectHtml('assist1['.$goalid.']', $teamid, intval($goal->player_assist1)).'</div>';
			$playersColumn .= '<div style="margin-top:4px;"><strong>Assist2:</strong><br>'.$this->BuildGoalPlayerSelectHtml('assist2['.$goalid.']', $teamid, intval($goal->player_assist2)).'</div>';

			$html .= '<tr>';
			$html .= '<td><input type="hidden" name="goalids[]" value="'.$goalid.'"><input class="narrow" type="number" name="timestamps['.$goalid.']" value="'.intval($goal->time).'"></td>';
			$html .= '<td>'.$playersColumn.'</td>';
			$html .= '<td>'.$this->BuildGoalTypeSelectHtml('goalTypes['.$goalid.']', intval($goal->goalType)).'</td>';
			$html .= '<td>'.$this->BuildGoalPeriodInputHtml('goalPeriods['.$goalid.']', $gameid, intval($goal->goalPeriod)).'</td>';
			$html .= '<td>'.$deleteLink.'</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		$html .= '<br><button type="submit" name="submit" value="SaveAllGoals">Save all goals</button>';
		$html .= '</form>';
		$html .= '</div>';
		return $html;
	}
	public function PrintGameRoster_Table($gameid, $teamid=0,$pagename="fullgame")
	{
		$html = "";
		$gameid = intval($gameid);
		$teamid = intval($teamid);
		$dbAddCond = 'WHERE team='.intval($teamid). "";
		$dbAddCondRoster = 'WHERE team='.intval($teamid). " AND game=".$gameid;
		$rows = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		$gameroster = DbObj_Gameroster::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCondRoster);
		$queryString = $_SERVER['QUERY_STRING'];

		$module = new Form($this->SiteName."?".$queryString, "post");
		$module->Overhead = true;
		$module->InternalTable = false;
		$module->SubmitBtn = true;
        //$module->ContentInsideStart = '<h5>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Game Roster</h5><br>';

		$table = new Table();
		$table->FontSize = 14;
		$table->AddContent = $module->GetHTML();
		$table->Overhead = false;
		$table->ForceBorders=true;
		$tableHead = new TableRow( "Player", "#", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);

		$formItems = array();
		$formItems[] = new FormItem("hidden", "action", "gameroster");
		$formItems[] = new FormItem("hidden", "redirect", $this->Page($pagename));
		$formItems[] = new FormItem("hidden", "gameid", $gameid);
		$formItems[] = new FormItem("hidden", "teamid", $teamid);

		foreach ($rows as $row)
		{
			$player = $this->GetPlayer($row->player);
			$idFormItem = new FormItem("hidden", "teamHasPlayer[]", $row->id);
			$idFormItem->TableCompatible=false;
			$pidFormItem = new FormItem("hidden", "players[]", $player->id);
			$pidFormItem->TableCompatible=false;
			
			$formItemPlayerNum = new FormItem("number","numbers[]", $row->number, "#");
			$formItemPlayerNum->ClassName='narrow';
			$formItemPlayerNum->TableCompatible=false;
			$formItemPlayerCheck = new FormItem("checkbox","playerAttend[]", $player->id);
			$formItemPlayerCheck->ClassName='narrow';
			$formItemPlayerCheck->TableCompatible=false;
			// $formItems[] = $idFormItem;
			// $formItems[] = $pidFormItem;
			// $formItems[] = $formItemPlayerNum;
			// $formItems[] = $formItemPlayerCheck;
            /* if current player is in gameroster then set checked */
            if (is_array($gameroster) && count($gameroster) > 0)
            {
                foreach ($gameroster as $roster)
                {
                    if ($roster->player == $row->player)
                    {
                        $formItemPlayerCheck->Checked = true;
                        break;
                    }
                }
            }
            reset($gameroster); // reset pointer back to start of array
            $tableRow = new TableRow();

            $tableRow->AddColumn($idFormItem->GetHTML().$pidFormItem->GetHTML().$formItemPlayerNum->GetHTML());
            $tableRow->AddColumn($player->GetName());
            $tableRow->AddColumn($formItemPlayerCheck->GetHTML());
			$table->AddRow($tableRow);
		}
        foreach ($formItems as $item)
		{
			$item->TableCompatible = false;
			$module->AddItem($item);
			/*if ($item->Type != 'hidden')
				$tableRow->AddColumn($item->GetHTML());*/
		}
		//$html .= $table->GetHTML();
        $module->ContentInsideStart = "<h5>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Game Roster</h5><br>".$table->GetHTML();
		$html .= $module->GetHTML();
		return $html;
	}
    
	// Creates TableRow with 6 cols (time, goal, a1, a2, button, delete) for underlying function
	public function GetFormGameGoalPlayer(
		$pagename, $gameid, $teamid, $goalid=0, 
		$time=0,$gid=0, $a1player=0, $a2player=0, $a1id=0, $a2id=0, $goalType=0, $goalPeriod=0)
	{
		$queryString = $_SERVER['QUERY_STRING'];
		$buttonText = ($goalid > 0) ? 'Edit' : 'Add';
		$formId = 'goalForm_'.intval($gameid).'_'.intval($teamid).'_'.intval($goalid);

		$hidden = '';
		$hidden .= '<input type="hidden" name="action" value="gamestats">';
		$hidden .= '<input type="hidden" name="redirect" value="'.$this->Page($pagename).'">';
		$hidden .= '<input type="hidden" name="gameid" value="'.intval($gameid).'">';
		$hidden .= '<input type="hidden" name="teamid" value="'.intval($teamid).'">';
		$hidden .= '<input type="hidden" name="goalid" value="'.intval($goalid).'">';
		$hidden .= '<input type="hidden" name="assist1id" value="'.intval($a1id).'">';
		$hidden .= '<input type="hidden" name="assist2id" value="'.intval($a2id).'">';

		$goalSelectHtml = str_replace('<select ', '<select form="'.$formId.'" ', $this->BuildGoalPlayerSelectHtml('goal', $teamid, intval($gid)));
		$assist1SelectHtml = str_replace('<select ', '<select form="'.$formId.'" ', $this->BuildGoalPlayerSelectHtml('assist1', $teamid, intval($a1player)));
		$assist2SelectHtml = str_replace('<select ', '<select form="'.$formId.'" ', $this->BuildGoalPlayerSelectHtml('assist2', $teamid, intval($a2player)));
		$goalTypeSelectHtml = str_replace('<select ', '<select form="'.$formId.'" ', $this->BuildGoalTypeSelectHtml('goalType', intval($goalType)));
		$goalPeriodInputHtml = str_replace('<input ', '<input form="'.$formId.'" ', $this->BuildGoalPeriodInputHtml('goalPeriod', $gameid, intval($goalPeriod)));

		$playersColumn = '';
		$playersColumn .= '<div><strong>Goal:</strong><br>'.$goalSelectHtml.'</div>';
		$playersColumn .= '<div style="margin-top:4px;"><strong>Assist1:</strong><br>'.$assist1SelectHtml.'</div>';
		$playersColumn .= '<div style="margin-top:4px;"><strong>Assist2:</strong><br>'.$assist2SelectHtml.'</div>';

		$deleteLink = '&nbsp;';
		if ($goalid > 0)
		{
			$deleteLink =
			'[<a href="?p='.$this->Page($pagename).'&id='.$gameid.'&delete=1&goal='.$goalid.'" onclick="return confirm(\'Are you sure you want to delete the entry?\')">x</a>]';
		}

		$tableRow = new TableRow();
		$tableRow->RawRow =
		'<td><form id="'.$formId.'" method="post" action="'.$this->SiteName.'?'.$queryString.'">'
		.$hidden
		.'</form><input form="'.$formId.'" class="narrow" type="number" name="timestamp" value="'.intval($time).'"></td>'
		.'<td>'.$playersColumn.'</td>'
		.'<td>'.$goalTypeSelectHtml.'<br><div style="margin-top:4px;"><strong>Period:</strong><br>'.$goalPeriodInputHtml.'</div></td>'
		.'<td><button form="'.$formId.'" type="submit" name="submit" value="'.$buttonText.'">'.$buttonText.'</button></td>'
		.'<td>'.$deleteLink.'</td>'
		;
		return $tableRow;
	}
	public function PrintGamePenalties_Table($gameid, $teamid=0,$pagename="fullgame")
	{
		$html = "";
		$gameid = intval($gameid);
		$teamid = intval($teamid);
		$queryString = $_SERVER['QUERY_STRING'];

		$teamObj = $this->GetTeam($teamid);
		$teamNameSoundFile = $this->GetSoundFile($teamObj->nameSoundFile);
		$penaltyAnnounceSoundFile = $this->PenaltyAnnounceSoundFile;

		$formButton = new Form($this->SiteName."?".$queryString, "post");
		$formButton->Overhead = false;
		$formButton->InternalTable = false;
		$formButton->SubmitBtn = false;
		$formButtonItems = array();
		$formButtonItems[] = new FormItem("hidden", "action", "sendCommand");
		$formButtonItems[] = new FormItem("hidden", "id", $gameid);
		$formButtonItems[] = new FormItem("hidden", "redirect", $this->Page($pagename));
		$formButtonItems[] = new FormItem("hidden", "command", "play");
		$formButtonItems[] = new FormItem("hidden", "parameter0", $teamNameSoundFile->name);
		$formButtonItems[] = new FormItem("hidden", "parameter1", $penaltyAnnounceSoundFile);
		if ($pagename != 'fullgame')
			$formButtonItems[] = new FormItem("button","submit", 'Announce penalty');
		foreach ($formButtonItems as $item)
		{
			$item->TableCompatible = false;
			$formButton->AddItem($item);
		}
		$formButton->ContentInsideStart = '<h5>';
		$formButton->ContentInsideEnd = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Penalties</h5><br>';

		$addCond = ' WHERE game='.$gameid. ' AND team='.$teamid. ' ORDER BY id ASC';
		$penalties = DbObj_Penalty::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
		$table = new Table();
		$table->FontSize = 12;
		$table->AddContentBefore = $formButton->GetHTML();
		$table->Overhead = true;
		$table->ForceBorders=true;
		$tableHead = new TableRow("Time", "Player", "Duration", "Reason", " ", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		$table->AddRow($this->GetFormGamePenaltyPlayer($pagename, $gameid, $teamid));
		$html .= $table->GetHTML();
		if (count($penalties) > 0)
		{
			$html .= '<br>';
			$html .= $this->GetFormGamePenaltiesBulkEdit($pagename, $gameid, $teamid, $penalties);
		}
		return $html;
	}

	private function GetFormGamePenaltiesBulkEdit($pagename, $gameid, $teamid, $penalties)
	{
		$queryString = $_SERVER['QUERY_STRING'];
		$html = '';
		$html .= '<div style="background:#fff; padding:10px; border:1px solid #ddd;">';
		$html .= '<h6>Edit existing penalties and submit all at once</h6>';
		$html .= '<form method="post" action="'.$this->SiteName.'?'.$queryString.'">';
		$html .= '<input type="hidden" name="action" value="gamestats">';
		$html .= '<input type="hidden" name="bulkPenalties" value="1">';
		$html .= '<input type="hidden" name="redirect" value="'.$this->Page($pagename).'">';
		$html .= '<input type="hidden" name="gameid" value="'.intval($gameid).'">';
		$html .= '<input type="hidden" name="teamid" value="'.intval($teamid).'">';
		$html .= '<table border="1" style="font-size:12px; width:100%; border-collapse: collapse; background:#fff;">';
		$html .= '<tr><th>Time</th><th>Player</th><th>Duration</th><th>Reason</th><th>Delete</th></tr>';

		foreach ($penalties as $penalty)
		{
			$penaltyid = intval($penalty->id);
			$player = $this->GetPlayer(intval($penalty->player));
			$playerDisplay = '#'.intval($penalty->player).' '.$player->GetName();
			$deleteLink =
			'[<a href="?p='.$this->Page($pagename).'&id='.$gameid.'&delete=1&penalty='.$penaltyid.'" onclick="return confirm(\"Are you sure you want to delete the entry?\")">x</a>]';

			$html .= '<tr>';
			$html .= '<td><input type="hidden" name="penaltyids[]" value="'.$penaltyid.'"><input class="narrow" type="number" name="timestamps['.$penaltyid.']" value="'.intval($penalty->timestamp).'"></td>';
			$html .= '<td>'.$this->BuildPenaltyPlayerSelectHtml('players['.$penaltyid.']', $teamid, intval($penalty->player)).'</td>';
			$html .= '<td><input class="narrow" type="number" name="durations['.$penaltyid.']" value="'.intval($penalty->duration).'"></td>';
			$html .= '<td>'.$this->BuildPenaltyTypeSelectHtml('penaltyTypes['.$penaltyid.']', intval($penalty->penType)).'</td>';
			$html .= '<td>'.$deleteLink.'</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		$html .= '<br><button type="submit" name="submit" value="SaveAllPenalties">Save all penalties</button>';
		$html .= '</form>';
		$html .= '</div>';
		return $html;
	}

	private function BuildPenaltyPlayerSelectHtml($name, $teamid, $selectedPlayerId)
	{
		$dbAddCond = 'WHERE team='.intval($teamid). "";
		$playersInTeam = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		usort($playersInTeam, array($this, "THP_CompareByNumber"));

		$html = '<select name="'.$name.'" class="option-small">';
		$html .= '<option value="0"'.(intval($selectedPlayerId) === 0 ? ' selected' : '').'>#00 UNKNOWN</option>';
		foreach ($playersInTeam as $playerIT)
		{
			$playerObj = $this->GetPlayer($playerIT->player);
			$selected = (intval($playerIT->player) === intval($selectedPlayerId)) ? ' selected' : '';
			$html .= '<option value="'.intval($playerIT->player).'"'.$selected.'>#'.intval($playerIT->number).' '.$playerObj->GetName().'</option>';
		}
		$html .= '</select>';
		return $html;
	}

	private function BuildPenaltyTypeSelectHtml($name, $selectedPenaltyType)
	{
		$penaltyTypes = $this->GetPenaltyTypes();
		$html = '<select name="'.$name.'" class="option-small">';
		foreach ($penaltyTypes as $index => $penaltyType)
		{
			$selected = ($index === $selectedPenaltyType) ? ' selected' : '';
			$html .= '<option value="'.intval($index).'"'.$selected.'>'.$penaltyType.'</option>';
		}
		$html .= '</select>';
		return $html;
	}

// Creates TableRow woth 6 cols (time, goal, a1, a2, button, delete) for underlying function
	public function GetFormGamePenaltyPlayer($pagename, $gameid, $teamid, $penid=0, $time=0,$playerId=0, $duration=0, $penaltyType=0)
	{
		$queryString = $_SERVER['QUERY_STRING'];

		$module = new Form($this->SiteName."?".$queryString, "post");
		$module->Overhead = false;
		$module->InternalTable = false;
		$module->SubmitBtn = false;

		$formItems = array();
		$formItems[] = new FormItem("hidden", "action", "gamestats");
		$formItems[] = new FormItem("hidden", "redirect", $this->Page($pagename));
		$formItems[] = new FormItem("hidden", "gameid", $gameid);
		$formItems[] = new FormItem("hidden", "teamid", $teamid);
		$formItems[] = new FormItem("hidden", "penaltyId", $penid);
		
		$playerSelect = new FormItem("select", "player", '', 'Player');
		$playerSelect->ClassName='option-small';
		$formItemDuration = new FormItem("number", "timestamp", $time, 'Time');
		$formItemDuration->ClassName='narrow';
		$formItems[] = $formItemDuration;
		$penaltyTypeSelect = new FormItem("select", "penaltyType", '', 'Penalty');
		$penaltyTypeSelect->ClassName= 'option-small';

		/* Get list of players */
		$dbAddCond = 'WHERE team='.intval($teamid). "";
		$playersInTeam = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		usort($playersInTeam, array($this, "THP_CompareByNumber"));

		$optionsPlayer = array();
		$optionsPenalties = array();

		$optionsPlayer[] = new SelectOption("#00 UNKNOWN", 0, true, true);
		foreach ($playersInTeam as $playerIT) 
		{
			$selected = ($playerIT->player == $playerId) ? true : false;
			$optionsPlayer[] = new SelectOption("#".
				$playerIT->number." ".$this->GetPlayer($playerIT->player)->GetName(), $playerIT->player, $selected, true);
		}
		$playerSelect->SelectOptions = $optionsPlayer;
		reset($playersInTeam);

		$penaltyTypes = $this->GetPenaltyTypes();
		foreach ($penaltyTypes as $index => $item) 
		{
			$selected = ($index == $penaltyType) ? true : false;
			$optionsPenalties[] = new SelectOption($item, $index, $selected, true);
		}
		$penaltyTypeSelect->SelectOptions = $optionsPenalties;

		$deleteLink = ' ';
		if ($penid > 0)
		{
			$deleteLink = 
			'[<a href="?p='.$this->Page($pagename).'&id='.$gameid.'&delete=1&penalty='.$penid.'"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';
		}

		$formItems[] = $playerSelect;

		$formItemTime = new FormItem("number", "duration", $duration, 'Duration');
		$formItemTime->ClassName='narrow';
		$formItems[] = $formItemTime;

		$formItems[] = $penaltyTypeSelect;
		$buttonText = ($penid > 0) ? 'Edit':'<big>Add</big>';
		$formItems[] = new FormItem("submit", "submit", $buttonText);
		$tableRow = new TableRow();
		foreach ($formItems as $item)
		{
			$item->TableCompatible = true;
			$module->AddItem($item);
			/*if ($item->Type != 'hidden')
				$tableRow->AddColumn($item->GetHTML());*/
		}
		//$tableRow->AddColumn($deleteLink);
		$tableRow->RawRow = $module->GetHTML().'<td>'.$deleteLink.'</td>';
		return $tableRow;
	}

	public function CreateControl_Games($headline, $pageName, $className, $id=0, $formHandler=0, $tableHandler=0, $dynamicFormItemsHandler=0)
	{
		$module = new Module_GenericContent();
		$module->Headline = $headline;
		$module->NoticeText = $this->NoticeText;

		$formHtml = "";
		$tableHtml = "";
		$formHtml = $this->CreateForm_Dbobj($className, $pageName, $id, $dynamicFormItemsHandler);
		$tableHtml = $this->CreateTable_Games($className, $pageName, true, "ORDER BY id DESC");

		$module->AddContent($formHtml, 6, "");
		$module->AddContent($tableHtml, 6, "");
		
		return $module->GetHTML();
	}

	public function CreateTable_Games($className, $pageName, $newEntryAllowed=true, $dbAddCond="", $tableOverhead=true)
	{
		$newEntryLink = '<a href="?p='.$this->Page($pageName).'&id=0">New entry</a><br><br>'.PHP_EOL;
		$rows = $className::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
		$table = new Table();
		$table->Overhead = $tableOverhead;
		if ($newEntryAllowed)
		{
			$table->AddContentBefore = $newEntryLink;
		}
		$tableHead = new TableRow(" ", "ID", "Name", "Team1", "Team2", "Status", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		foreach ($rows as $row)
		{
			$fulleditlink = '
			<a href="?p=fullgame&id='.$row->id.'">Open</a>
			';
			$editLink = 
			'<a href="?p='.$this->Page($pageName).'&id='.$row->id.'">'.$row->GetName().'</a>'." (".$this->GetGameScore($row->id).")";
			$deleteLink = 
			'[<a href="?p='.$this->Page($pageName).'&id='.$row->id.'&delete=1"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';
			$table->AddRow(new TableRow(
				$fulleditlink, $row->id, $editLink, 
				$this->GetTeam($row->team1)->GetName(), 
				$this->GetTeam($row->team2)->GetName(), 
				$this->GetAfterGameStatus($row->afterGameStatus),
				$deleteLink));
		}
		return $table->GetHTML();
	}
	public function CreateControl_Overview()
	{
		$html ='';
		$pageName = 'overview';
		$queryString = $_SERVER['QUERY_STRING'];
		$module = new Module_GenericContent();
		$module->Headline = ' ';
		$module->Style = 'style="padding-bottom:10px; padding-top:10px;"';
		$module->NoticeText = $this->NoticeText;
		$commandsControlHtml = '';

		/*$tableEvent = new Table();
		$tableEventRow = new TableRow();
		$tableEventRow->RawRow = $this->CreateOverview_TimerControl($pageName);
		$tableEvent->AddRow($tableEventRow);*/

		$tableCommand = new Table();
		$tableCommandRow = new TableRow();
		$tableCommandRow->RawRow = $this->CreateControlRow_SoundPlayer($pageName);
		$tableCommand->AddRow($tableCommandRow);

		//$stadiumControlHtml = $tableEvent->GetHTML();
		$stadiumControlHtml = $this->CreateOverview_TimerControl($pageName);
		
		$commandsControlHtml .= $tableCommand->GetHTML();
		
		$moduleGame = new Module_GenericContent();
		$moduleGame->Style = 'style="padding-top:0px;"';
		$moduleGame->Headline = '';

		if ($this->LocationConfig->activeGame > 0)
		{
			$gameid = $this->LocationConfig->activeGame;
			$game = $this->GetGame($gameid);

			$commandsControlHtml .= '
			<br>
			<h4><a href="?p='.$this->Page("fullgame").'&id='.$gameid.'" target="_blank">Edit game</a></h4>
			<br><h4>Penalties</h4>';
			$commandsControlHtml .= $this->CreateControl_TimerEventTable($pageName, $game, true, true);
			if ($this->LocationConfig->teamsMirror > 0)
			{
				$team1Html = $this->CreateTable_GameTeam($gameid, $game->team2, $pageName, $this->HomeHornHotkey);
				$team2Html = $this->CreateTable_GameTeam($gameid, $game->team1, $pageName, $this->AwayHornHotkey);
				$moduleGame->AddContent($team1Html, 6, $this->GetTeam($game->team2)->GetName().'
				- '.$this->GetTeamNumberOfGoalsInGame($game->id, $game->team2).'');
				$moduleGame->AddContent($team2Html, 6, $this->GetTeam($game->team1)->GetName().'
				- '.$this->GetTeamNumberOfGoalsInGame($game->id, $game->team1).'');
			}
			else
			{
				$team1Html = $this->CreateTable_GameTeam($gameid, $game->team1, $pageName, $this->HomeHornHotkey);
				$team2Html = $this->CreateTable_GameTeam($gameid, $game->team2, $pageName, $this->AwayHornHotkey);
				$moduleGame->AddContent($team1Html, 6, $this->GetTeam($game->team1)->GetName().'
				- '.$this->GetTeamNumberOfGoalsInGame($game->id, $game->team1).'');
				$moduleGame->AddContent($team2Html, 6, $this->GetTeam($game->team2)->GetName().'
				- '.$this->GetTeamNumberOfGoalsInGame($game->id, $game->team2).'');
			}
		}
		else
		{
			// no active game
			$moduleGame->AddContent('<h4>No active game</h4>', 12);
		}
		$module->AddContent($stadiumControlHtml, 6, 'Timer Control');
		$module->AddContent($commandsControlHtml, 6, 'Sound player');
		$html .= $module->GetHTML();
		$html .= $moduleGame->GetHTML();
		return $html;
	}
	private function CreateOverview_TimerControl($pageName, $eventSequence=true, $penaltiesControl=false)
	{
		$html = '';
		$queryString = $_SERVER['QUERY_STRING'];

		$form = new Form($this->SiteName."?".$queryString, "post");
		$form->Overhead = false;
		$form->InternalTable = false;
		$form->SubmitBtn = false;

		$gamesSelect = new FormItem("select", "activeGame", '', 'Active Game', 'Active Game');
		$teamsMirrorCheckbox = new FormItem("checkbox", "teamsMirror", '1', 'Switch sides', 'Switch sides');
		if ($this->LocationConfig->teamsMirror > 0)
			$teamsMirrorCheckbox->Checked = true;

		$optionsGames = array();

		$emptyGame = new DbObj_Game($this->Database, $this->App->GetDbPrefix());
		$emptyGame->name = "- No game -";
		$games = DbObj_Game::ReadAll($this->Database, $this->App->GetDbPrefix(),
		"WHERE afterGameStatus <= 1"
		);
		array_unshift($games, $emptyGame);
		foreach ($games as $game)
		{
			$selected = ($game->id == $this->LocationConfig->activeGame) ? true : false;
			$optionsGames[] = new SelectOption($game->GetName(), $game->id, $selected);
		}
		$gamesSelect->SelectOptions = $optionsGames;

		$formItems = array();
		$formItems[] = new FormItem("hidden", "action", "activeGame");
		$formItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formItems[] = $gamesSelect;
		$formItems[] = $teamsMirrorCheckbox;
		$formItems[] = new FormItem("button", "submit", "Submit");
		foreach ($formItems as $item)
		{
			$form->AddItem($item);
		}

		$html .= $form->GetHTML();

		$activeGame = $this->GetGame($this->LocationConfig->activeGame);
		
		$tableActiveGame = new Table();
		$tableActiveGameRow = new TableRow();
		$tableActiveGameRow->RawRow = $html;
		$tableActiveGame->AddRow($tableActiveGameRow);

		$tableTimerControls = new Table();
		$tableTimerControlsRow = new TableRow();
		$tableTimerControlsRow->RawRow = $this->CreateControl_Timer($pageName, $activeGame);
		$tableTimerControls->AddRow($tableTimerControlsRow);

		$html = $tableActiveGame->GetHTML();
		$html .= $tableTimerControls->GetHTML();
		if ($eventSequence)
			$html .= $this->CreateControl_TimerEventTable($pageName, $activeGame);
		if ($penaltiesControl)
			$html .= $this->CreateControl_TimerEventTable($pageName, $activeGame);
		return $html;
	}

	private function CreateControl_Timer($pageName, $activeGame)
	{
		$html = '';
		$queryString = $_SERVER['QUERY_STRING'];

		$formStop = new Form($this->SiteName."?".$queryString, "post");
		$formStop->Overhead = false;
		$formStop->InternalTable = false;
		$formStop->SubmitBtn = false;
		$formStop->SubmitBtnText = 'Stop';
		$formStop->ContentAfter = '';
		$formStopItems = array();
		$formStopItems[] = new FormItem("hidden", "action", "timerStop");
		$formStopItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$timerBtnText = ($this->LocationConfig->timerActive)?"STOP":"START";
		$submitBtn = new FormItem("submit", "submit", $timerBtnText);
		$submitBtn->Id = 'TimerControlBtn';
		$formStopItems[] = $submitBtn;

		foreach ($formStopItems as $item)
		{
			$item->TableCompatible = false;
			$formStop->AddItem($item);
		}
		
		$html .= '<span style="font-size:50px;">'.
		$formStop->GetHTML().'&nbsp;&nbsp;&nbsp;
		<div id="countdown" style="display: inline;"></div></span>'
		;
		return $html;
	}

	public function CreateControl_TimerEventTable($pageName, $activeGame, $tableOverhead=true, $penaltyControl=false)
	{
		$html = "";
		$queryString = $_SERVER['QUERY_STRING'];
		if ($activeGame->id > 0)
		{
			$eventgroupid = intval($activeGame->eventGroup);
			$gameevent = $this->GetActiveGameevent($activeGame->id);
			$table = new Table();
			$table->FontSize = 10;
			//$table->AddContentBefore = '<h4 style="color:#0c0249;">Events sequence</h4>';
			$table->Overhead = $tableOverhead;

			$dbAddCond = '';
			$rows = null;
			if ($penaltyControl) // Penalties
			{
				$dbAddCond = "WHERE gameid=".$activeGame->id.' AND type='.$this->PenaltyEventType;
				$dbAddCond .= ' ORDER BY duration';
				$table->Id = "eventPenalties";
				$tableHead = new TableRow("ID", "Time remaining", "Note");
				$penaltyGameEvents = $this->GetGameeventPenalties($activeGame->id);
			}
			else // Normal event sequence
			{
				$dbAddCond = "WHERE gameid=".$activeGame->id.' AND type!='.$this->PenaltyEventType;
				$dbAddCond .= ' ORDER BY orderingroup';
				$table->Id = "eventTableSequence";
				$tableHead = new TableRow("ID", "#", "Type", "Dur.", "Name", 
			"Sound start", "Sound end","Music", "Timer stop");
				$rows = DbObj_Timerevent::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
			}

			$tableHead->Thead = true;
			$table->AddRow($tableHead);
			if ($penaltyControl) // Penalties
			{
				foreach ($penaltyGameEvents as $penaltyGameEvent)
				{
					$penaltyTimerEvent = $this->GetTimerevent($penaltyGameEvent->event);
					$deleteLink = 
			'[<a href="?p='.$this->Page($pageName).'&id='.$penaltyTimerEvent->id.'&penaltyTimer=1&delete=1"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';
					$timeLeft = '00:00';
					$table->AddRow(new TableRow(
						$penaltyTimerEvent->id,
						$timeLeft, 
						$penaltyTimerEvent->name
						//$deleteLink
						)
					);
				}
			}
			else
			{
				foreach ($rows as $row)
				{
					$activeTagStart = '';
					$activeTagEnd = '';
					$activeTag = '';
					if ($gameevent->event == $row->id) // active event
					{
						/*$activeTagStart = '<b>';
						$activeTagEnd = '</b>';
						$activeTag = '> ';*/
					}
					$table->AddRow(new TableRow(
						$activeTagStart.$activeTag.$row->id.$activeTagEnd,
						$activeTagStart.$activeTag.$row->orderingroup.$activeTagEnd, 
						$activeTagStart.$this->GetEventType($row->type).$activeTagEnd,
						$activeTagStart.$row->duration.$activeTagEnd, 
						$activeTagStart.$row->name.$activeTagEnd, 
						' '.$activeTagStart.$this->GetSoundFile($row->soundStart)->GetName().$activeTagEnd,
						' '.$activeTagStart.$this->GetSoundFile($row->soundEnd)->GetName().$activeTagEnd,
                        ' '.$activeTagStart.($row->disableMusic ? "No" : "Yes").$activeTagEnd,
						$activeTagStart.$row->stopTimerAfter.$activeTagEnd)
					);
				}
			}
			$html = $table->GetHTML();

			// Show controls only for normal event sequence
			if (!$penaltyControl)
			{
				$formPrevious = new Form($this->SiteName."?".$queryString, "post");
				$formPrevious->Overhead = false;
				$formPrevious->InternalTable = false;
				$formPrevious->SubmitBtn = false;
				$formPrevious->SubmitBtnText = 'Stop';
				$formPrevious->ContentAfter = '';
				$formPreviousItems = array();
				$formPreviousItems[] = new FormItem("hidden", "action", "gameeventControl");
				$formPreviousItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
				$nextBtn = new FormItem("submit", "btnAction", "Next", '&nbsp;&nbsp;&nbsp;', '&nbsp;');
				$prevBtn = new FormItem("submit", "btnAction", "Previous", '&nbsp;&nbsp;&nbsp;', '&nbsp;&nbsp;');
				$resetBtn = new FormItem("submit", "btnAction", "Reset", '&nbsp;&nbsp;&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;');
				$timeinput = new FormItem("number", "timeAdd", 0, 'Time', '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;');
				$addtimeBtn = new FormItem("submit", "btnAction", "+Dur", '', '');
				$addRemtimeBtn = new FormItem("submit", "btnAction", "+RemTime", '', '');
				$timeinput->ClassName = "narrow";
				
				
				$formPreviousItems[] = $prevBtn;
				$formPreviousItems[] = $nextBtn;
				$formPreviousItems[] = $timeinput;
				$formPreviousItems[] = $addRemtimeBtn;
				$formPreviousItems[] = $addtimeBtn;
				$formPreviousItems[] = $resetBtn;

				foreach ($formPreviousItems as $item)
				{
					$item->TableCompatible = false;
					$formPrevious->AddItem($item);
				}
				$html .= ''.
				$formPrevious->GetHTML().'&nbsp;&nbsp;&nbsp;
				'
				;
			}
		}
		else
		{
			// No active game
		}
		return $html;
	}
	private function CreateControlRow_Command($pageName)
	{
		$html = '';
		$queryString = $_SERVER['QUERY_STRING'];

		$form = new Form($this->SiteName."?".$queryString, "post");
		$form->Overhead = false;
		$form->InternalTable = true;
		$form->SubmitBtn = true;

		$commandSelect = new FormItem("select", "command", '', 'Command', 'Command');
		$commandParam0 = new FormItem("text", "parameter0", '', 'Param_0', 'Param_0');
		$commandParam1 = new FormItem("text", "parameter1", '', 'Param_1', 'Param_1');
		$commandParam2 = new FormItem("text", "parameter2", '', 'Param_2', 'Param_2');

		$optionsCommands = array();
		$commands = $this->GetCommands();
		foreach ($commands as $command)
		{
			$optionsCommands[] = new SelectOption($command, $command);
		}
		$commandSelect->SelectOptions = $optionsCommands;

		$formItems = array();
		$formItems[] = new FormItem("hidden", "action", "sendCommand");
		$formItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formItems[] = $commandSelect;
		$formItems[] = $commandParam0;
		$formItems[] = $commandParam1;
		$formItems[] = $commandParam2;
		foreach ($formItems as $item)
		{
			$form->AddItem($item);
		}
		$html .= $form->GetHTML();
		
		return $html;
	}
	private function CreateControlRow_SoundPlayer($pageName)
	{
		$html = '';
		$queryString = $_SERVER['QUERY_STRING'];

		$formPlay = new Form($this->SiteName."?".$queryString, "post");
		$formPlay->Overhead = false;
		$formPlay->InternalTable = false;
		$formPlay->SubmitBtn = true;
		$formPlay->SubmitBtnText = 'Play';
		$formPlayItems = array();
		$formPlayItems[] = new FormItem("hidden", "action", "sendCommand");
		$formPlayItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formPlayItems[] = new FormItem("hidden", "command", "play");

		$formStop = new Form($this->SiteName."?".$queryString, "post");
		$formStop->Overhead = false;
		$formStop->InternalTable = false;
		$formStop->SubmitBtn = true;
		$formStop->SubmitBtnText = 'Stop';
		$formStop->ContentAfter = '';
		$formStopItems = array();
		$formStopItems[] = new FormItem("hidden", "action", "sendCommand");
		$formStopItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formStopItems[] = new FormItem("hidden", "command", "stop");
		

		$formVolume = new Form($this->SiteName."?".$queryString, "post");
		$formVolume->Overhead = false;
		$formVolume->InternalTable = false;
		$formVolume->SubmitBtn = true;
		$formVolume->SubmitBtnText = 'Set volume';
		$formVolumeItems = array();
		$formVolumeItems[] = new FormItem("hidden", "action", "sendCommand");
		$formVolumeItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formVolumeItems[] = new FormItem("hidden", "command", "volume");

		$soundFileSelect = new FormItem("select", "parameter0", '');
		$soundFiles = $this->GetSoundFiles();
		$optionsSoundFiles = array();
		foreach ($soundFiles as $soundFile)
		{
			$optionsSoundFiles[] = new SelectOption($soundFile->GetName(), $soundFile->GetName());
		}
		$soundFileSelect->SelectOptions = $optionsSoundFiles;

		$volumeInput = new FormItem("number", "parameter0", $this->LocationConfig->soundVolume, 'Volume');
		$volumeInput->Min = 0;
		$volumeInput->Max = 100;

        /** Music volume */
        $formMusicVolume = new Form($this->SiteName."?".$queryString, "post");
		$formMusicVolume->Overhead = false;
		$formMusicVolume->InternalTable = false;
		$formMusicVolume->SubmitBtn = true;
		$formMusicVolume->SubmitBtnText = 'Set music volume';
		$formMusicVolumeItems = array();
		$formMusicVolumeItems[] = new FormItem("hidden", "action", "sendCommand");
		$formMusicVolumeItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formMusicVolumeItems[] = new FormItem("hidden", "command", "musicvolume");

		$musicVolumeInput = new FormItem("number", "parameter0", $this->LocationConfig->musicVolume, 'Volume');
		$musicVolumeInput->Min = 0;
		$musicVolumeInput->Max = 100;

        /** Music break */
        $formBreakMusic = new Form($this->SiteName."?".$queryString, "post");
		$formBreakMusic->Overhead = false;
		$formBreakMusic->InternalTable = false;
		$formBreakMusic->SubmitBtn = true;
		$formBreakMusic->SubmitBtnText = 'Music break: '.$this->LocationConfig->musicBreak;
		$formBreakMusic->ContentAfter = '';
		$formBreakMusicItems = array();
		$formBreakMusicItems[] = new FormItem("hidden", "action", "sendCommand");
		$formBreakMusicItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
		$formBreakMusicItems[] = new FormItem("hidden", "command", "musicBreak");

		$formPlayItems[] = $soundFileSelect;
		$formVolumeItems[] = $volumeInput;
		$formMusicVolumeItems[] = $musicVolumeInput;

		foreach ($formPlayItems as $item)
		{
			$item->TableCompatible = false;
			$formPlay->AddItem($item);
		}

		foreach ($formStopItems as $item)
		{
			$item->TableCompatible = false;
			$formStop->AddItem($item);
		}
		
		foreach ($formVolumeItems as $item)
		{
			$item->TableCompatible = false;
			$formVolume->AddItem($item);
		}

        foreach ($formMusicVolumeItems as $item)
		{
			$item->TableCompatible = false;
			$formMusicVolume->AddItem($item);
		}

        foreach ($formBreakMusicItems as $item)
		{
			$item->TableCompatible = false;
			$formBreakMusic->AddItem($item);
		}
		
		$html .= 
		$formPlay->GetHTML().'&nbsp;&nbsp;&nbsp;'.
		$formStop->GetHTML().'<br><br>'.
		$formVolume->GetHTML().''
        .'<br><br>'.
        $formMusicVolume->GetHTML().''
        .'&nbsp;&nbsp;&nbsp;'.
        $formBreakMusic->GetHTML()
		;
		return $html;
	}


	public function CreateControl_Lights()
	{
		$html ='';
		$module = new Module_GenericContent();
		$module->Headline = ' ';
		$module->NoticeText = $this->NoticeText;

		$tableSockets = new Table();
		$lightsForms = $this->CreateLightsControls('elsockets');
		$tableSockets->AddContentBefore = "<b>Electric sockets</b>".$lightsForms;
		$tableSocketsRow = new TableRow();
		$tableSocketsRow->RawRow = $this->CreateLightsControls('elsockets', 2);
		$tableSockets->AddRow($tableSocketsRow);

		$tableCommand = new Table();
		$tableCommandRow = new TableRow();
		$tableCommandRow->RawRow = $this->CreateControlRow_Command('elsockets');
		$tableCommand->AddRow($tableCommandRow);

		$stadiumControlHtml = $tableSockets->GetHTML();
		//$stadiumControlHtml = $this->CreateLightsControls('elsockets');
		$commandsControlHtml = $tableCommand->GetHTML();
		$module->AddContent($stadiumControlHtml, 6, 'Electric sockets');
		$module->AddContent($commandsControlHtml, 6, 'Commands');

		$moduleGame = new Module_GenericContent();
		$moduleGame->Headline = '';

		$html .= $module->GetHTML();
		$html .= $moduleGame->GetHTML();
		return $html;
	}

	private function CreateLightsControls($pageName, $buttons=0)
	{
		$html = '';
		$queryString = $_SERVER['QUERY_STRING'];

		$socketsAddCond = "WHERE location=".intval($this->LocationConfig->location.
		' LIMIT '.intval($this->LocationConfig->sockets).';');

		$sockets = DbObj_Elsocket::ReadAll($this->Database, $this->App->GetDbPrefix(), $socketsAddCond);
		$socketsCount = count($sockets);
		if ($socketsCount < $this->LocationConfig->sockets) // not all sockets are in DB, create them
		{
			for (; $socketsCount < $this->LocationConfig->sockets; $socketsCount++)
			{
				$newelsocket = DbObj_Elsocket($this->Database, $this->App->GetDbPrefix());
				$newelsocket->location = $this->LocationConfig->location;
				$newelsocket->socketId = $socketsCount;
				$newelsocket->Create();
			}
		}

		$sockets = DbObj_Elsocket::ReadAll($this->Database, $this->App->GetDbPrefix(), $socketsAddCond);
		$form_sockets = array();
		$i=0;
		foreach ($sockets as $socket)
		{
			$form = new Form($this->SiteName."?".$queryString, "post");
			$form->Overhead = false;
			$form->InternalTable = false;
			$form->SubmitBtn = false;
			$form->Id = 'socket_'.$i;
			$form_sockets[] = $form;
			$formItems = array();
			$socketStatus = ($socket->state > 0) ? "OFF" : "ON";
			$socketNewStatus = ($socket->state > 0) ? 0 : 1;
			$formItems[] = new FormItem("hidden", "action", "elsocket");
			$formItems[] = new FormItem("hidden", "redirect", $this->Page($pageName));
			$formItems[] = new FormItem("hidden", "socketid", ''.$i.'');
			$formItems[] = new FormItem("hidden", "socketNewStatus", ''.$socketNewStatus.'');
			if ($buttons > 0)
			{
				$formItemButton = new FormItem("button", "Socket_".$i, "Socket_".$i.": ".$socketStatus);
				$formItemButton->Form = $form->Id;
				$formItemButton->TableCompatible = true;
				$formItems[] = $formItemButton;
			}
			foreach ($formItems as $item)
			{
				$form->AddItem($item);
			}
			if ($buttons == 2) // print only buttons
			{
				$html .= PHP_EOL.
				'<td><button type="button" 
				name="Socket_'.$i.'"
				form="socket_'.$i.'"
				id="Socket_'.$i.'"
				value="Socket_'.$i.': '.$socketStatus.'">
				Socket_'.$i.': '.$socketStatus.'
				</button></td>'
				.PHP_EOL;
			}
			else
			{
				$html .= PHP_EOL.$form->GetHTML().PHP_EOL;
			}
			$i++;
		}
		
		return $html;
	}

	/* UNIVERSAL START ****************************************************************/
	public function CreateControl_Dbobj(
		$headline, $pageName, $className, $id=0, $formHandler=null, 
		$tableHandler=null, $dynamicFormItemsHandler=null, $additionalLeftContentHandler=null, 
		$sizeLeft=6, $sizeRight=6
		)
	{
		/* If this is form after deletion, reset id */
		if ($this->HttpGET("delete", true) == 1)
		{
			$id = 0;
		}
		$module = new Module_GenericContent();
		$module->Headline = $headline;
		$module->NoticeText = $this->NoticeText;

		$formHtml = "";
		$tableHtml = "";

		if ($formHandler != null)
		{
			if (method_exists($this, $formHandler)) {
				$formHtml .= $this->$formHandler($className, $pageName, $id);
			} else {
				echo "Error: Method $formHandler not found in class.";
			}
		}
		else
		{
			$formHtml = $this->CreateForm_Dbobj($className, $pageName, $id, $dynamicFormItemsHandler);
		}

		// Call function to handle dynamic data	
		if ($additionalLeftContentHandler != null)
		{
			if (method_exists($this, $additionalLeftContentHandler)) {
				$formHtml .= $this->$additionalLeftContentHandler($className, $pageName, $id);
			} else {
				echo "Error: Method $additionalLeftContentHandler not found in class.";
			}
		}

		if ($tableHandler != null)
		{
			if (method_exists($this, $tableHandler)) {
				$tableHtml .= $this->$tableHandler($className, $pageName, $id);
			} else {
				echo "Error: Method $tableHandler not found in class.";
			}
		}
		else
		{
			$tableHtml = $this->CreateTable_Dbobj($className, $pageName);
		}

		$module->AddContent($formHtml, $sizeLeft, "");
		$module->AddContent($tableHtml, $sizeRight, "");
		
		return $module->GetHTML();
	}

	public function CreateForm_Dbobj($className, $pageName, $id=0, $dynamicFormItemsHandler=0)
	{
		$queryString = $_SERVER['QUERY_STRING'];
		$module = new Form($this->SiteName."?".$queryString, "post");
		$module->FormName = "New";
		$module->SubmitBtnText = "Create";
		$dbobj = new $className($this->Database, $this->App->GetDbPrefix());
		if ($id > 0)
		{
			$dbobj->id = $id;
			$dbobj->Read();
			$module->FormName = $dbobj->GetName();
			$module->SubmitBtnText = "Save changes";
		}
		// Handle form error if any occured
		if ($this->FormError != "")
		{
			$module->Notice = $this->FormError;
		}
		$formItems = array();
		$formItems[] = new FormItem("hidden", "action", $this->Page($pageName));
		$formItems[] = new FormItem("hidden", "id", $id);

		// Get non-static properties
		$nonStaticProperties = array();
		$nonStaticProperties = self::GetNonStaticVars($dbobj);

		foreach ($nonStaticProperties as $property)
		{
			if ($property == "id") 
				continue; // skip ID, already added as hidden
            $reflection = new ReflectionProperty($className, $property);
            // if ($property == "startTime")
            // {
            //     echo $property;
            //     echo $reflection->getDocComment();
            //     echo ini_get('opcache.save_comments');
            //     exit;
            // }
            $inputType = self::GetInputType($dbobj->$property, $reflection->getDocComment());
			// seasonId is varchar in gamegroupstats and must stay a text field
			// even when the current value is numeric-looking (e.g. "26").
			if ($className === "DbObj_Gamegroupstats" && $property === "seasonId") {
				$inputType = "text";
			}
            $value = (isset($_POST[$property]) ? $_POST[$property] : $dbobj->$property);
            $value = self::ParseFormValue($value, $inputType);
			$formItems[] = new FormItem(
				$inputType, 
				$property, 
				($value), 
				ucfirst($property), 
				ucfirst($property)
			);
		}

		foreach ($formItems as $item)
		{
			$module->AddItem($item);
		}
		// Call function to handle dynamic data	
		if ($dynamicFormItemsHandler != null)
		{
			if (method_exists($this, $dynamicFormItemsHandler)) {
				$this->$dynamicFormItemsHandler($module, $id);
			} else {
				echo "Error: Method $dynamicFormItemsHandler not found in class.";
			}
		}

		return $module->GetHTML();
	}

	public function CreateTable_Dbobj($className, $pageName, $newEntryAllowed=true, $dbAddCond="", $tableOverhead=true)
	{
		$newEntryLink = '<a href="?p='.$this->Page($pageName).'&id=0">New entry</a><br><br>'.PHP_EOL;
		$rows = $className::ReadAll($this->Database, $this->App->GetDbPrefix());
		$table = new Table();
		$table->Overhead = $tableOverhead;
		if ($newEntryAllowed)
		{
			$table->AddContentBefore = $newEntryLink;
		}
		$tableHead = new TableRow("ID", "Name", " ");
		$tableHead->Thead = true;
		$table->AddRow($tableHead);
		foreach ($rows as $row)
		{
			$editLink = 
			'<a href="?p='.$this->Page($pageName).'&id='.$row->id.'">'.$row->GetName().'</a>';
			$deleteLink = 
			'[<a href="?p='.$this->Page($pageName).'&id='.$row->id.'&delete=1"
			onclick="return confirm(\'Are you sure you want to delete the entry?\')"
			>x</a>]';
			$table->AddRow(new TableRow($row->id, $editLink, $deleteLink));
		}
		return $table->GetHTML();
	}
	/* UNIVERSAL END *******************************************************************************/
	/* HANDLE POST START ***************************************************************************/
	public function HandlePOST_Login()
	{
		$login = $this->Database->EscapeString($_POST['email']);
		$password = $this->Database->EscapeString($_POST['password']);
		$storeLogin = (isset($_POST['remember'])) ? true : false;
		$this->App->Login($login, $password, $storeLogin);
		header("Location: ".$this->SiteName."?p=".$this->Page("overview"));
		exit;
	}
	public function HandlePOST_EventGroup()
	{
		$dbobj = new DbObj_EventGroup($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->groupname = $this->Database->EscapeString($_POST['groupname']);
		$dbobj->penaltySmall = intval($_POST['penaltySmall']);
		$dbobj->penaltyMedium = intval($_POST['penaltyMedium']);
		$dbobj->penaltyLarge = intval($_POST['penaltyLarge']);
		$notice = 1; // creation notice
		if (!$this->ContainsAlphabet($dbobj->groupname))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2; // update notice
				$dbobj->Update();
				$this->Log("update", 'eventGroup:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$this->Log("update", 'eventGroup:'.$dbobj->groupname);
			}
			header("Location: ".$this->SiteName."?p=".$this->Page("eventgroup").($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_GameGroupStats()
	{
		$dbobj = new DbObj_Gamegroupstats($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$ggsflag = isset($_POST['ggsgames']) ? intval($_POST['ggsgames']) : 0;
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->displayName = isset($_POST['displayName']) ? $this->Database->EscapeString($_POST['displayName']) : '';
		$dbobj->seasonId = isset($_POST['seasonId']) ? $this->Database->EscapeString($_POST['seasonId']) : '';
		$dbobj->webpageId = isset($_POST['webpageId']) ? intval($_POST['webpageId']) : 0;
		$dbobj->pointsWin = intval($_POST['pointsWin']);
		$dbobj->pointsLost = intval($_POST['pointsLost']);
		$dbobj->pointsWinPp = intval($_POST['pointsWinPp']);
		$dbobj->pointsLostPp = intval($_POST['pointsLostPp']);
		$dbobj->pointsTie = intval($_POST['pointsTie']);
        $dbobj->penaltySmall = intval($_POST['penaltySmall']);
		$dbobj->penaltyMedium = intval($_POST['penaltyMedium']);
		$dbobj->penaltyLarge = intval($_POST['penaltyLarge']);
		if ($ggsflag > 0 && $dbobj->id > 0)
		{
			$dbobjGame = new DbObj_Ggsgame($this->Database, $this->App->GetDbPrefix());
			$dbobjGame->ggs = intval($dbobj->id);
			$dbobjGame->DeleteOccurences();
			if (isset($_POST['ggsgames']))
			{
				foreach ($_POST['ggsgames'] as $index => $item) 
				{
					$gameid = intval($item);
					$dbobjGame->game = $gameid;
					$dbobjGame->Create();
				}
			}
			$this->Log("update", 'gameGroupStatsGames:'.$dbobj->id);
		}
		else if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$dbobj->Update();
				$this->Log("update", 'gameGroupStats:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$this->Log("create", 'gameGroupStats:'.$dbobj->name);
			}
			header("Location: ".$this->SiteName."?p=".$this->Page("gamegroupstats").($dbobj->id>0?"&id=".$dbobj->id:""));
			exit;
		}
	}
	public function HandlePOST_Player()
	{
		$dbobj = new DbObj_Player($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->imagePath = $this->Database->EscapeString($_POST['imagePath']);
		$dbobj->notes = $this->Database->EscapeString($_POST['notes']);
		$dbobj->dateOfBirth = intval($_POST['dateOfBirth']);
		$dbobj->skillLevel = intval($_POST['skillLevel']);
		$notice = 1;
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2;
				$dbobj->Update();
				$this->Log("update", 'player:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$this->Log("create", 'player:'.$dbobj->name);
			}
			header("Location: ".$this->SiteName."?p=".$this->Page("player").($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_SoundFile()
	{
		$dbobj = new DbObj_SoundFile($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$notice = 1;
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$dbobj->Update();
				$this->Log("update", 'soundFile:'.$dbobj->id);
				$notice = 2;
			}
			else
			{
				$dbobj->Create();
				$this->Log("create", 'soundFile:'.$dbobj->name);
			}
			header("Location: ".$this->SiteName."?p=".$this->Page("soundfile").($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_Team()
	{
		$dbobj = new DbObj_Team($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->shortName = $this->Database->EscapeString($_POST['shortName']);
		$dbobj->nameSoundFile = $this->Database->EscapeString($_POST['nameSoundFile']);
		$dbobj->songFile = $this->Database->EscapeString($_POST['songFile']);
		$dbobj->goalHorn = $this->Database->EscapeString($_POST['goalHorn']);
		$dbobj->imagePath = $this->Database->EscapeString($_POST['imagePath']);
		$notice = 1;
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2;
				$dbobj->Update();
				if (isset($_POST['players']) && is_array($_POST['players']) &&
				isset($_POST['numbers']) && is_array($_POST['numbers'])) 
				{
					// Process each item
					foreach ($_POST['players'] as $index => $item) 
					{
						$teamHasPlayer = new DbObj_Teamhasplayer($this->Database, $this->App->GetDbPrefix());
						$teamHasPlayer->team = $dbobj->id;
						$teamHasPlayer->player = intval($item);
						$teamHasPlayer->number = intval($_POST['numbers'][$index]);
						if (isset($_POST['teamHasPlayer'][$index])) // player in team already existed
						{
							$teamHasPlayer->id = intval($_POST['teamHasPlayer'][$index]);
							$teamHasPlayer->Update();
						}
						else if (!$teamHasPlayer->DuplicateExists())
						{
							$teamHasPlayer->Create();
						}
					}
				}
			}
			else
			{
				$dbobj->Create();
				$dbobj->id = $this->Database->GetLastInsertID();
				$this->Log("create", 'team:'.$dbobj->name);
			}
			header("Location: ".$this->SiteName."?p=".$this->Page("team").($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_GameRoster()
	{
		$gameid = intval($_POST['gameid']);
		$teamid = intval($_POST['teamid']);
        $notice = 1;
		$ids = $_POST['players'];
        $numbers = $_POST['numbers'];
        $attendance = isset($_POST['playerAttend']) ? $_POST['playerAttend'] : [];
        $players = [];

        foreach ($ids as $index => $id)
        {
            $players[] = 
            [
                'id' => $id,
                'number' => $numbers[$index],
                'attended' => in_array($id, $attendance)
            ];
        }
        // First delete all existing occurences for this game and team
        $dbObj_Gameroster = new DbObj_Gameroster($this->Database, $this->App->GetDbPrefix());
        $dbObj_Gameroster->game = $gameid;
        $dbObj_Gameroster->team = $teamid;
        $dbObj_Gameroster->DeleteGameTeamOccurences();
        foreach ($players as $player)
        {
            if ($player['attended'])
            {
                $dbobj = new DbObj_Gameroster($this->Database, $this->App->GetDbPrefix());
                $dbobj->game = $gameid;
                $dbobj->team = $teamid;
                $dbobj->player = intval($player['id']);
                $dbobj->number = intval($player['number']);
                $dbobj->Create();
                $this->Log("create", 'gameRoster:'.$gameid.':'.$teamid.':'.$dbobj->player);
            }
            $teamHasPlayer = new DbObj_Teamhasplayer($this->Database, $this->App->GetDbPrefix());
            $teamHasPlayer->Find(intval($player['id']), $teamid);
            $teamHasPlayer->number = intval($player['number']);
            $teamHasPlayer->Update();
        }

        $retPage = "fullgame";
        // echo 'gameid: '.$gameid;
        // echo 'teamid: '.$teamid;
        // echo 'retPage: '.$retPage;
        // // print whole players array
        // print_r($players);
        header("Location: ".$this->SiteName."?p=".$this->Page($retPage).($gameid>0?"&id=".$gameid:"")."&notice=".$notice);
        exit;
	}
	
	public function HandlePOST_Event()
	{
		$dbobj = new DbObj_Event($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->active = intval($_POST['active']);
		$dbobj->image = $this->Database->EscapeString($_POST['image']);
		$dbobj->year = $this->Database->EscapeString($_POST['year']);
		$dbobj->date = $this->Database->EscapeString($_POST['date']);
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->description = $this->Database->EscapeString($_POST['description']);
		$dbobj->url = $this->Database->EscapeString($_POST['url']);
		$notice = 1;
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2;
				$dbobj->Update();
				$this->Log("update", 'event:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$this->Log("create", 'event:'.$dbobj->name);
			}
			header("Location: ".$this->SiteName."?p=".$this->Page("event").($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_Game()
	{
		$dbobj = new DbObj_Game($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->gameGroup = intval($_POST['gameGroup']);
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->referee = $this->Database->EscapeString($_POST['referee']);
        $dbobj->location = $this->Database->EscapeString($_POST['location']);
        $dbobj->startTime = intval(strtotime($_POST['startTime']));
		$dbobj->note = $this->Database->EscapeString($_POST['note']);
		$dbobj->team1 = intval($_POST['team1']);
		$dbobj->team2 = intval($_POST['team2']);
		$dbobj->visitors = intval($_POST['visitors']);
		$dbobj->refereeRating = intval($_POST['refereeRating']);
		$dbobj->afterGameStatus = intval($_POST['afterGameStatus']);
		$dbobj->eventGroup = intval($_POST['eventGroup']);
		$notice = 1;
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2;
				$dbobj->Update();
				$this->Log("update", 'game:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$gameid = $this->Database->GetLastInsertID();
				$this->Log("create", 'game:'.$gameid);
				$this->CreateGameTimerEvents($gameid, $dbobj->eventGroup);
			}
			$retPage = ($_POST['action']=="game")?"game":"fullgame";
			header("Location: ".$this->SiteName."?p=".$this->Page($retPage).($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_GameStats()
	{
		$opengameid = 0; // if game is open in special window then redirect to actual game
		$redirect = $_POST['redirect'];

		/* Game is open in its own windows so redirect to game page after */
		if ($redirect == 'fullgame')
		{
			$opengameid = intval($_POST['gameid']);
		}

		if (isset($_POST['bulkPenalties']) && intval($_POST['bulkPenalties']) === 1) // handle bulk penalty edit
		{
			$gameid = intval($_POST['gameid']);
			$team = intval($_POST['teamid']);
			$penaltyids = isset($_POST['penaltyids']) ? $_POST['penaltyids'] : array();
			$timestamps = isset($_POST['timestamps']) ? $_POST['timestamps'] : array();
			$players = isset($_POST['players']) ? $_POST['players'] : array();
			$durations = isset($_POST['durations']) ? $_POST['durations'] : array();
			$penaltyTypes = isset($_POST['penaltyTypes']) ? $_POST['penaltyTypes'] : array();

			foreach ($penaltyids as $penaltyidRaw)
			{
				$penaltyid = intval($penaltyidRaw);
				if ($penaltyid <= 0)
					continue;

				$timestamp = isset($timestamps[$penaltyid]) ? intval($timestamps[$penaltyid]) : 0;
				$playerid = isset($players[$penaltyid]) ? intval($players[$penaltyid]) : 0;
				$duration = isset($durations[$penaltyid]) ? intval($durations[$penaltyid]) : 0;
				$penaltyType = isset($penaltyTypes[$penaltyid]) ? intval($penaltyTypes[$penaltyid]) : 0;

				$dbobj = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
				$dbobj->id = $penaltyid;
				$dbobj->Read(); // Read existing to preserve timerid
				$dbobj->timestamp = $timestamp;
				$dbobj->game = $gameid;
				$dbobj->team = $team;
				$dbobj->player = $playerid;
				$dbobj->duration = $duration;
				$dbobj->penType = $penaltyType;
				$dbobj->Update();
			}
			$this->Log("update", 'penalty_bulk:game='.$gameid.':team='.$team.':count='.count($penaltyids));
		}
		else if (isset($_POST['bulkGoals']) && intval($_POST['bulkGoals']) === 1) // handle bulk goal edit
		{
			$gameid = intval($_POST['gameid']);
			$team = intval($_POST['teamid']);
			$goalids = isset($_POST['goalids']) ? $_POST['goalids'] : array();
			$timestamps = isset($_POST['timestamps']) ? $_POST['timestamps'] : array();
			$goals = isset($_POST['goals']) ? $_POST['goals'] : array();
			$assist1 = isset($_POST['assist1']) ? $_POST['assist1'] : array();
			$assist2 = isset($_POST['assist2']) ? $_POST['assist2'] : array();
			$goalTypes = isset($_POST['goalTypes']) ? $_POST['goalTypes'] : array();
			$goalPeriods = isset($_POST['goalPeriods']) ? $_POST['goalPeriods'] : array();

			foreach ($goalids as $goalidRaw)
			{
				$goalid = intval($goalidRaw);
				if ($goalid <= 0)
					continue;

				$timestamp = isset($timestamps[$goalid]) ? intval($timestamps[$goalid]) : 0;
				$goalPlayer = isset($goals[$goalid]) ? intval($goals[$goalid]) : 0;
				$a1player = isset($assist1[$goalid]) ? intval($assist1[$goalid]) : 0;
				$a2player = isset($assist2[$goalid]) ? intval($assist2[$goalid]) : 0;
				$goalType = isset($goalTypes[$goalid]) ? intval($goalTypes[$goalid]) : 0;
				$goalPeriod = isset($goalPeriods[$goalid]) ? intval($goalPeriods[$goalid]) : 0;
				if ($goalPeriod <= 0)
					$goalPeriod = $this->GetGoalPeriodFromActiveEvent($gameid);

				$dbobj_g = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
				$dbobj_g->id = $goalid;
				$dbobj_g->timestamp = $timestamp;
				$dbobj_g->game = $gameid;
				$dbobj_g->team = $team;
				$dbobj_g->player = $goalPlayer;
				$dbobj_g->goalType = $goalType;
				$dbobj_g->goalPeriod = $goalPeriod;
				$dbobj_g->Update();

				$dbobj_assist = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
				$dbobj_assist->goal = $goalid;
				$dbobj_assist->DeleteGoalOccurences();

				if ($a1player > 0)
				{
					$dbobj_a1 = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
					$dbobj_a1->goal = $goalid;
					$dbobj_a1->player = $a1player;
					$dbobj_a1->assistOrder = 0;
					$dbobj_a1->game = $gameid;
					$dbobj_a1->Create();
				}

				if ($a2player > 0)
				{
					$dbobj_a2 = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
					$dbobj_a2->goal = $goalid;
					$dbobj_a2->player = $a2player;
					$dbobj_a2->assistOrder = 1;
					$dbobj_a2->game = $gameid;
					$dbobj_a2->Create();
				}
			}
			$this->Log("update", 'goal_bulk:game='.$gameid.':team='.$team.':count='.count($goalids));
		}
		else if (isset($_POST['gameid']) && isset($_POST['goal'])) // handle goal
		{
			$gameid = intval($_POST['gameid']);
			$goalid = intval($_POST['goalid']);
			$team = intval($_POST['teamid']);
			$timestamp = intval($_POST['timestamp']);
			if ($timestamp === 0)
			{
				$timestamp = $this->GetGoalTimestampFromActiveEvent($gameid);
			}
			$goal = intval($_POST['goal']);
			$a1player = intval($_POST['assist1']);
			$a2player = intval($_POST['assist2']);
			$a1id = intval($_POST['assist1id']);
			$a2id = intval($_POST['assist2id']);
			$goalType = isset($_POST['goalType']) ? intval($_POST['goalType']) : 0;
			$goalPeriod = isset($_POST['goalPeriod']) ? intval($_POST['goalPeriod']) : 0;
			if ($goalPeriod <= 0)
			{
				$goalPeriod = $this->GetGoalPeriodFromActiveEvent($gameid);
			}
			$submit = $_POST['submit'];

			$game = $this->GetGame($gameid);
			if ($game->name == '') die("The game doesn't exist.");

			$dbobj_g = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
			$dbobj_a1 = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
			$dbobj_a2 = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());

			$dbobj_g->timestamp = $timestamp;
			$dbobj_g->game = $gameid;
			$dbobj_g->team = $team;
			$dbobj_g->player = $goal;
			$dbobj_g->goalType = $goalType;
			$dbobj_g->goalPeriod = $goalPeriod;

			$dbobj_a1->player = $a1player;
			$dbobj_a1->assistOrder = 0;
			$dbobj_a1->game = $gameid;
			$dbobj_a1->goal = $goalid;

			$dbobj_a2->player = $a2player;
			$dbobj_a2->assistOrder = 1;
			$dbobj_a2->game = $gameid;
			$dbobj_a2->goal = $goalid;

			if (strpos($submit, "Add") !== false)
			{
				$dbobj_g->Create();
				$goalid = $this->Database->GetLastInsertID();
				$dbobj_a1->goal = $goalid;
				$dbobj_a2->goal = $goalid;
				if ($dbobj_a1->player > 0)
					$dbobj_a1->Create();
				if ($dbobj_a2->player > 0)
					$dbobj_a2->Create();
				$this->Log("create", 'goal:'.$goalid);
			}
			else if ($submit == 'Edit')
			{
				$dbobj_g->id = $goalid;
				$dbobj_g->Update();

				if ($a1id > 0) // assist already existed
				{
					$dbobj_a1->id = $a1id;
					if ($dbobj_a1->player > 0)
						$dbobj_a1->Update();
					else
						$dbobj_a1->Delete();
				}
				else // assist doesnt exist yet
				{
					if ($dbobj_a1->player > 0)
						$dbobj_a1->Create();
				}

				if ($a2id > 0) // assist already existed
				{
					$dbobj_a2->id = $a2id;
					if ($dbobj_a2->player > 0)
						$dbobj_a2->Update();
					else
						$dbobj_a2->Delete();
				}
				else // assist doesnt exist yet
				{
					if ($dbobj_a2->player > 0)
						$dbobj_a2->Create();
				}
				$this->Log("update", 'goal:'.$goalid);
			}
		}
		else if (isset($_POST['gameid']) && isset($_POST['penaltyType'])) // handle penalty
		{
			$gameid = intval($_POST['gameid']);
			$penaltyid = intval($_POST['penaltyId']);
			$timestamp = intval($_POST['timestamp']);
			if ($timestamp === 0)
			{
				$timestamp = $this->GetGoalTimestampFromActiveEvent($gameid);
			}
			$player = intval($_POST['player']);
			$team = intval($_POST['teamid']);
			$duration = intval($_POST['duration']);
			$penaltyType = intval($_POST['penaltyType']);
			$submit = $_POST['submit'];

			$dbobj = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
			$dbobj->timestamp = $timestamp;
			$dbobj->game = $gameid;
			$dbobj->team = $team;
			$dbobj->player = $player;
			$dbobj->duration = $duration;
			$dbobj->penType = $penaltyType;

			if (strpos($submit, "Add") !== false)
			{
				$penaltyTimerId = $this->AddPenaltyTimer($gameid, $player, $team, $penaltyType, $duration);
				$dbobj->timerid = $penaltyTimerId;
				$dbobj->Create();
				$this->Log("create", 'penalty_player:'.$player);
			}
			else if ($submit == 'Edit')
			{
				$dbobj->id = $penaltyid;
				$dbobj->Read();
				$oldPenaltyTimerId = $dbobj->timerid;

				// Re-apply posted values after Read(), because Read() loads old DB values.
				$dbobj->timestamp = $timestamp;
				$dbobj->game = $gameid;
				$dbobj->team = $team;
				$dbobj->player = $player;
				$dbobj->duration = $duration;
				$dbobj->penType = $penaltyType;

				// remove penalty timer
				$this->DeletePenaltyEvents($oldPenaltyTimerId);
				$penaltyTimerId = $this->AddPenaltyTimer($gameid, $player, $team, $penaltyType, $duration);
				$dbobj->timerid = $penaltyTimerId;
				$this->Log("edit", 'penalty:'.$dbobj->id);
				$dbobj->Update();
			}
		}
		header("Location: ".$this->SiteName."?p=".$this->Page($_POST['redirect']).($opengameid>0?"&id=".$opengameid:""));
		exit;
	}

	public function HandlePOST_ActiveGame()
	{
		$this->LocationConfig->activeGame = intval($_POST['activeGame']);
		if (isset($_POST['teamsMirror']))
			$this->LocationConfig->teamsMirror = intval($_POST['teamsMirror']);
		else
		$this->LocationConfig->teamsMirror = 0;
		$this->LocationConfig->Update();
		$this->Event_GameChange($this->LocationConfig->activeGame);

		$retPage = $_POST['redirect'];
		header("Location: ".$this->SiteName."?p=".$this->Page($retPage));
		exit;
	}
	public function HandlePOST_SendCommand()
	{
		$param0 = $this->Database->EscapeString($_POST['parameter0']);
		$param1 = $this->Database->EscapeString($_POST['parameter1']);
		$param2 = $this->Database->EscapeString($_POST['parameter2']);
		$command = $this->Database->EscapeString($_POST['command']);
		$this->SendCommand($command, $param0, $param1, $param2);
		$this->Log("command", $command.':'.$param0.':'.$param1.':'.$param2);
		$retPage = $_POST['redirect'];
		header("Location: ".$this->SiteName."?p=".$this->Page($retPage));
		exit;
	}
	public function HandlePOST_Elsocket()
	{
		if (isset($_POST["socketid"]) && isset($_POST["socketNewStatus"]))
		{
			$elsocketid = intval($_POST['socketid']);
			$newstatus = intval($_POST['socketNewStatus']);
			$this->SetElsocket($elsocketid, $newstatus);
			$retPage = $_POST['redirect'];
			header("Location: ".$this->SiteName."?p=".$this->Page($retPage));
		exit;
		}
	}
	public function HandlePOST_EventTimer()
	{
		$dbobj = 0;
		$notice = 1;
		$templateEvent = intval($_POST["template"]);
		if ($templateEvent > 0)
		{
			$dbobj = new DbObj_EventGroupEvent($this->Database, $this->App->GetDbPrefix());
			$dbobj->id = intval($_POST['id']);
			$dbobj->eventgroup = $templateEvent;
		}
		else
		{
			$dbobj = new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix());
			$dbobj->id = intval($_POST['id']);
			$dbobj->gameid = intval($_POST['gameid']);
			$dbobj->almostEndSound = intval($_POST['almostEndSound']);
		}
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->note = $this->Database->EscapeString($_POST['note']);
		$dbobj->orderingroup = intval($_POST['order']);
		$dbobj->duration = intval($_POST['duration']);
		$dbobj->type = intval($_POST['type']);
		$dbobj->periodNumber = intval($_POST['periodNumber']);
		$dbobj->soundStart = intval($_POST['soundStart']);
		$dbobj->soundEnd = intval($_POST['soundEnd']);
		$dbobj->stopTimerAfter = intval($_POST['stopTimerAfter']);
        $dbobj->disableMusic = intval($_POST['disableMusic']);
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2;
				$dbobj->Update();
				$this->Log("update", 'eventtimer:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$this->Log("create", 'eventtimer:'.$dbobj->name);
			}
			$retPage = $_POST['redirect'];
			if ($templateEvent > 0)
				header("Location: ".$this->SiteName."?p=".$this->Page($retPage).($dbobj->eventgroup>0?"&id=".$dbobj->eventgroup:"").($dbobj->id>0?"&eid=".$dbobj->id:"")."&notice=".$notice);
			else
				header("Location: ".$this->SiteName."?p=".$this->Page($retPage).($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	public function HandlePOST_TimerStop()
	{
		$retPage = $_POST['redirect'];
		$this->Timer->StartStop();
		header("Location: ".$this->SiteName."?p=".$this->Page($retPage));
		exit;
	}
	public function HandlePOST_GameEventControl()
	{
		$action = $this->Database->EscapeString($_POST['btnAction']);
		$timeAdd = intval($_POST["timeAdd"]);
		switch ($action)
		{
			case 'Next':
				$this->Timer->NextEvent();
				break;
			case 'Previous':
				$this->Timer->PreviousEvent();
				break;
			case 'Reset':
				$this->Timer->ResetEventSequence();
				break;
			case 'Add':
				$this->Timer->AddEvent();
				break;
			case '+Dur':
				$this->Timer->AddTime($timeAdd);
				break;
			case '+RemTime':
				$this->Timer->AddRemainingTime($timeAdd);
				break;
			default: break;
		}

		$retPage = $_POST['redirect'];
		header("Location: ".$this->SiteName."?p=".$this->Page($retPage));
		exit;
	}
	public function HandlePOST_User()
	{
		$dbobj = new User($this->Database, $this->App->GetDbPrefix());
		$dbobj->id = intval($_POST['id']);
		$dbobj->login = $this->Database->EscapeString($_POST['login']);
		// hashing is handled later
		$dbobj->password = $this->Database->EscapeString($_POST['password']);
		$dbobj->name = $this->Database->EscapeString($_POST['name']);
		$dbobj->rights = intval($_POST['rights']);
		$notice = 1;
		if (!$this->ContainsAlphabet($dbobj->name))
		{
			// name doesnt meet criteria
			$this->FormError = "Name doesn't meet criteria.";
		}
		else
		{
			if ($dbobj->id > 0)
			{
				$notice = 2;
				$dbobj->Update();
				$this->Log("update", 'user:'.$dbobj->id);
			}
			else
			{
				$dbobj->Create();
				$this->Log("create", 'user:'.$dbobj->login);
			}
			//$retPage = $_POST['redirect'];
			header("Location: ".$this->SiteName."?p=".$this->Page("user").($dbobj->id>0?"&id=".$dbobj->id:"")."&notice=".$notice);
			exit;
		}
	}
	/* HANDLE POST END ******************************************************************/
	private function HandleDelete($page, $id)
	{
		if (!$this->App->IsLoggedIn())
		{
			//echo "Insufficient rights.";
		}
		else
		{
			$dbobj = 0;
			switch ($page)
			{
				case 'player':
					if (!$this->CheckUserPermissions("player",true,true,true,true)) break;
					$dbobj = new DbObj_Player($this->Database, $this->App->GetDbPrefix());
					$dbobj_pht = new DbObj_Teamhasplayer($this->Database, $this->App->GetDbPrefix());
					$dbobj->id = intval($id);
					$dbobj_pht->player = intval($id);
					$dbobj_pht->DeleteOccurences();
					$dbobj->Delete();
					$this->Log("delete", 'player:'.$dbobj->id);
					break;
				case 'team': 
					if (!$this->CheckUserPermissions("team",true,true,true,true)) break;
					$dbobj = new DbObj_Team($this->Database, $this->App->GetDbPrefix());
					$dbobj_pht = new DbObj_Teamhasplayer($this->Database, $this->App->GetDbPrefix());
					if ($this->HttpGET('ths', true) > 0) // delete THS
					{
						$dbobj_pht->id = intval($this->HttpGET('ths', true));
						$dbobj_pht->Delete();
						$this->Log("delete", 'ths:'.$dbobj_pht->id);
					}
					else if (!isset($_GET["ths"]))// delete team
					{
						$dbobj->id = intval($id);
						$dbobj_pht->team = intval($id);
						$dbobj_pht->DeleteOccurences();
						$dbobj->Delete();
						$this->Log("delete", 'team:'.$dbobj->id);
					}
					break;
				case 'event': 
					if (!$this->CheckUserPermissions("event",true,true,true,true)) break;
					$dbobj = new DbObj_Event($this->Database, $this->App->GetDbPrefix()); 
					$dbobj->id = intval($id);
					$dbobj->Delete();
					$this->Log("delete", 'event:'.$dbobj->id);
					break;
				case 'user': 
					if (!$this->CheckUserPermissions("user",true,true,true,true)) break;
					$dbobj = new User($this->Database, $this->App->GetDbPrefix());
					if ($this->GetUser()->id != $id) 
					{
						$dbobj->id = intval($id);
						$dbobj->Delete();
						$this->Log("delete", 'user:'.$dbobj->id);
					}
					break;
				case 'timerevent': 
					if (!$this->CheckUserPermissions("timerevent",true,true,true,true)) break;
					$dbobj = new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix()); 
					$dbobj->id = intval($id);
					$dbobj->Delete();
					$this->Log("delete", 'timerevent:'.$dbobj->id);
					break;
				case 'eventgroup': 
					if (!$this->CheckUserPermissions("eventgroup",true,true,true,true)) break;
					$dbobj = new DbObj_EventGroup($this->Database, $this->App->GetDbPrefix()); 
					$dbobjEve = new DbObj_EventGroupEvent($this->Database, $this->App->GetDbPrefix()); 
					if ($this->HttpGET('eve', true) > 0) // delete EVE
					{
						$dbobjEve->id = intval($this->HttpGET('eve', true));
						$dbobjEve->Delete();
						$this->Log("delete", 'eve:'.$dbobjEve->id);
					}
					else
					{
						$dbobj->id = intval($id);
						$dbobjEve->eventgroup = intval($id);
						$dbobjEve->DeleteOccurences();
						$dbobj->Delete();
						$this->Log("delete", 'eventgroup:'.$dbobj->id);
					}
					
					break;
				case 'gamegroupstats': 
					if (!$this->CheckUserPermissions("gamegroupstats",true,true,true,true)) break;
					$dbobj = new DbObj_Gamegroupstats($this->Database, $this->App->GetDbPrefix()); 
					$dbobjGame = new DbObj_Ggsgame($this->Database, $this->App->GetDbPrefix()); 
					{
						$dbobj->id = intval($id);
						$dbobjGame->ggs = intval($id);
						$dbobjGame->DeleteOccurences();
						$dbobj->Delete();
						$this->Log("delete", 'gamegroupstats:'.$dbobj->id);
					}
					break;
				case 'game': 
					if (!$this->CheckUserPermissions("game",true,true,true,true)) break;
					$dbobj = new DbObj_Game($this->Database, $this->App->GetDbPrefix());
					$dbobj_goal = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
					$dbobj_assist = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
					$dbobj_pens = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
					$dbobj->id = intval($id);

					$dbobj_goal->game = intval($id);
					$dbobj_assist->game = intval($id);
					$dbobj_pens->game = intval($id);

					$dbobj_goal->DeleteOccurences();
					$dbobj_assist->DeleteGameOccurences();
					$dbobj_pens->DeleteOccurences();
					$this->ClearGameEvents(intval($id));
					$dbobj->Delete();
					$this->Log("delete", 'game:'.$dbobj->id);
					break;
				case 'overview':
				case 'fullgame':
					if (!$this->CheckUserPermissions("fullgame",true,true,true,true)) break;
					if ($this->HttpGET('goal', true) > 0) // delete goal
					{
						$dbobj_goal = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
						$dbobj_assist = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
						$dbobj_goal->id = intval($this->HttpGET('goal', true));
						$dbobj_assist->goal = intval($dbobj_goal->id);
						$dbobj_assist->DeleteGoalOccurences();
						$dbobj_goal->Delete();
						$this->Log("delete", 'goal:'.$dbobj_goal->id);
					}
					else if ($this->HttpGET('penalty', true) > 0) // delete penalty
					{
						$dbobj_pens = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
						$dbobj_pens->id = intval($this->HttpGET('penalty', true));
						$dbobj_pens->Read();
						$this->DeletePenaltyEvents($dbobj_pens->timerid);
						$dbobj_pens->Delete();
						$this->Log("delete", 'penalty:'.$dbobj_pens->id);
					}
					else if ($this->HttpGET('penaltyTimer', true) > 0) // delete penalty timer
					{
						$this->DeletePenaltyEvents(intval($id));
						$this->Log("delete", 'penaltyTimer:'.intval($id));
					}
					else if ($this->HttpGET('clearGame', true) > 0) /* delete all game goal, assists and penalties */
					{
						$dbobj = new DbObj_Game($this->Database, $this->App->GetDbPrefix());

						$dbobj_goal = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
						$dbobj->id = intval($id);
						$dbobj_assist = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
						$dbobj_pens = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
						$dbobj_goal->game = intval($id);
						$dbobj_assist->game = intval($id);
						$dbobj_pens->game = intval($id);
						$dbobj_goal->DeleteOccurences();
						$dbobj_assist->DeleteGameOccurences();
						$dbobj_pens->DeleteOccurences();
						$this->Log("delete", 'clearGame:'.$dbobj->id);
					}
					break; 
				default: break;
			}
			if ($dbobj != 0)
			{
				$this->NoticeText = "Deletion of a entry was successful.";
			}
		}
	}
    public function AddGoal($gameid, $teamid, $playerid, $timestamp, $assist1=0, $assist2=0, $goalPeriod=0)
    {
        $dbobj = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
        $dbobj->game = $gameid;
        $dbobj->team = $teamid;
        $dbobj->player = $playerid;
        $dbobj->timestamp = $timestamp;
        $dbobj->goalPeriod = $goalPeriod;
        $dbobj->Create();
        $goalid = $this->Database->GetLastInsertID();
        if ($assist1 > 0)
        {
            $assistObj = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
            $assistObj->game = $gameid;
            $assistObj->goal = $goalid;
            $assistObj->player = $assist1;
            $assistObj->assistOrder = 0; // first assist
            $assistObj->Create();
        }
        if ($assist2 > 0)
        {
            $assistObj = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
            $assistObj->game = $gameid;
            $assistObj->goal = $goalid;
            $assistObj->player = $assist2;
            $assistObj->assistOrder = 1; // second assist
            $assistObj->Create();
        }
        $this->Log("create", 'goal:'.$goalid);
        return $goalid;
    }
    /* create function to remove a goal from game for team, remove a goal with highest id.
    I know just gameid and teamid */
    public function RemoveGoal($gameid, $teamid)
    {
        $dbobj = new DbObj_Goal($this->Database, $this->App->GetDbPrefix());
        $dbobj->game = $gameid;
        $dbobj->team = $teamid;
        $dbobj->ReadLastGoalInGame();
        if ($dbobj->id > 0)
        {
            $assistObj = new DbObj_Assistance($this->Database, $this->App->GetDbPrefix());
            $assistObj->goal = $dbobj->id;
            $assistObj->DeleteGoalOccurences();
            $dbobj->Delete();
            $this->Log("delete", 'goal:'.$dbobj->id);
        }
    }

	public function SetElsocket($socketid, $state)
	{
		$state = intval($state);
		if ($socketid < $this->LocationConfig->sockets)
		{
			$dbobj = new DbObj_Elsocket($this->Database, $this->App->GetDbPrefix());
			$dbobj->socketId = $socketid;
			$dbobj->location = $this->LocationConfig->location;
			$dbobj->ReadSocketIDinLocation();
			if ($dbobj->id > 0) // socket exists
			{
				$dbobj->state = $state;
				$dbobj->Update();
			}
			else // socket doesn't exist
			{

			}
		}
	}
	public function SoundPlayFile($filename, $filename2='')
	{
		$this->SendCommand('play', $filename, $filename2);
	}
    public function SoundPlayRandomMusic()
	{
		$this->SendCommand('playrandom', "", "");
	}
	public function SoundStop()
	{
		$this->SendCommand('stop');
	}
	public function SoundSetVolume($volume)
	{
		$volume = intval($volume);
		if ($volume >= 0 && $volume <= 100) {}
		else $volume = 100;
		$this->SendCommand('volume', $volume);
	}
    public function MusicSetVolume($volume)
	{
		$volume = intval($volume);
		if ($volume >= 0 && $volume <= 100) {}
		else $volume = 100;
		$this->SendCommand('musicvolume', $volume);
	}
	public function SendCommand($cmd, $par0='', $par1='', $par2='')
	{
		if ($this->IsValidCommand($cmd))
		{
			if ($cmd == "play" && $this->IsQuietHour())
				return;
			$commandObj = new DbObj_Cmdqueue($this->Database, $this->App->GetDbPrefix());
			$commandObj->location = $this->LocationConfig->location;
			$commandObj->cmd = $cmd;
			$commandObj->parameter0 = $par0;
			$commandObj->parameter1 = $par1;
			$commandObj->parameter2 = $par2;
			$commandObj->added = time();
			$commandObj->expiration = time()+$this->LocationConfig->commandExpirationTime;

			$commandObj->Create();
		}
        if ($cmd == "musicBreak")
        {
            // toggle music break mode
            $this->LocationConfig->musicBreak = ($this->LocationConfig->musicBreak==1)?0:1;
			$this->LocationConfig->Update();
        }
        if ($cmd == 'volume')
        {
            $this->LocationConfig->soundVolume = intval($par0);
            $this->LocationConfig->Update();
        }
        if ($cmd == 'musicvolume')
        {
            $this->LocationConfig->musicVolume = intval($par0);
            $this->LocationConfig->Update();
        }
	}
	public function CreateGameTimerEvents($gameid, $eventGroupId)
	{
		$events = $this->GetEventGroupsEvents($eventGroupId);
		$i=0;
		foreach ($events as $event)
		{
			$eventObj = new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix());
			$eventObj->gameid = $gameid;
			$eventObj->orderingroup = $event->orderingroup;
			$eventObj->duration = $event->duration;
			$eventObj->type = $event->type;
			$eventObj->name = $event->name;
			$eventObj->note = $event->note;
			$eventObj->periodNumber = $event->periodNumber;
			$eventObj->soundStart = $event->soundStart;
			$eventObj->soundEnd = $event->soundEnd;
			$eventObj->stopTimerAfter = $event->stopTimerAfter;
            $eventObj->disableMusic = $event->disableMusic;
			$eventObj->Create();
			if ($i == 0)
			{
				$gameeventObj = new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
				$gameeventObj->gameid = $gameid;
				$gameeventObj->event = $this->Database->GetLastInsertID();
				$gameeventObj->Create();
			}
			$i++;
		}
	}

	public function Event_GameChange($newGameId)
	{
		// Notify timer that active game has changed
		$this->Timer->ActiveGameChanged($newGameId);
	}
	public function AddPenaltyTimer($gameid, $playerid, $teamid, $penaltyid, $duration)
	{
		$dbobjTimer = 	new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix());
		$dbobjGameEv = 	new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
		$player = 	$this->GetPlayer($playerid);
		$team = 	$this->GetTeam($teamid);
		$penaltyName = $this->GetPenalty($penaltyid);
		$playerNumber = $this->GetPlayerNumberInTeam($playerid, $teamid);

		$dbobjTimer->name = '#'.$playerNumber.' '.$player->name.'('.$team->name.') - '.$penaltyName;
		$dbobjTimer->gameid = $gameid;
		$dbobjTimer->duration = $duration;
		$dbobjTimer->type = $this->PenaltyEventType;
		$dbobjTimer->soundStart = $team->nameSoundFile; // team name
		$dbobjTimer->soundEnd = $team->nameSoundFile; // team name
		$dbobjTimer->Create();

		$dbobjGameEv->event = $this->Database->GetLastInsertID();
		$dbobjGameEv->gameid = $gameid;
		$dbobjGameEv->paralelEvent = 1;
		$dbobjGameEv->Create();
		return $dbobjGameEv->event;
	}
	public function ClearGameEvents($gameid, $justPenalties = false)
	{
		$dbobj_timerevent = new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix());
		$dbobj_gameevent = new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
		$dbobj_timerevent->gameid = $gameid;
		$dbobj_gameevent->gameid = $gameid;
		$dbobj_timerevent->DeleteGameOccurences($justPenalties);
		$dbobj_gameevent->DeleteGameOccurences($justPenalties);
	}

	public function DeletePenaltyEvents($penaltyTimerId)
	{
		$dbobj_timerevent = new DbObj_Timerevent($this->Database, $this->App->GetDbPrefix());
		$dbobj_gameevent = new DbObj_Gameevent($this->Database, $this->App->GetDbPrefix());
		$dbobj_timerevent->id = $penaltyTimerId;
		$dbobj_timerevent->Delete();
		$dbobj_gameevent->DeleteEventOccurences($penaltyTimerId);
	}

    private $prevState = [
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0'
];
private $lastChangeTime = [
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0',
    '0','0','0','0','0','0','0','0'
];

private $buttonAction = [
    0,1,2,3,4,5,6,7,8,9,
    10,11,12,13,14,15,16,17,18,19,
    20,21,22,23,24,25,26,27,28,29,
    30,31,32,33,34,35,36,37,38,39,
    40,41,42,43,44,45,46,47,48,49,
    50,51,52,53,54,55,56,57,58,59,
    60,61,62,63
];

 private $buttonAddSeconds = 0;
 private$buttonAdd10Seconds = 1;
 private $buttonAddMinutes = 2;

 private $controlBox_modifierId = 11;
 private $controlBox_modifier = 0; // 0 - no modifier, 1 - modifier pressed
 private $controlBox_timeAddSelector = 0; // 0 - seconds, 1 - 10 seconds, 2 - minutes

private $controlBox_soundFile1 = '0_horn.mp3';
private $controlBox_soundFile2 = '0_startEventDefault.mp3';
private $controlBox_soundFile3 = 'iihf.mp3';
private $controlBox_soundFile4 = 'iihfintro.mp3';
private $controlBox_soundFile5 = 'hurryUp.mp3';
private $controlBox_soundFile6 = 'lastMinute.mp3';


// Define list of button indexes to ignore
private $ignoreButtons = []; // Example — add any button indexes you want to skip
    function HandleButtonsControlBox($buttonSetup)
{
    // Extract binary string between hashes
    preg_match('/#(.*?)#/', $buttonSetup, $matches);
    $currentState = isset($matches[1]) ? $matches[1] : '';

    if (strlen($currentState) !== 64) {
        die("Error: Expected 64 bits, got " . strlen($currentState));
    }
    /* DEBUG - enable this to see pressed buttons */
    //$this->Database->Query("INSERT INTO hbkpravotice_buttons (buttons) VALUES ('".$this->Database->EscapeString($currentState)."')");
    $currentTime = round(microtime(true) * 1000); // Current time in ms

    $this->controlBox_modifier = $currentState[$this->controlBox_modifierId]; // Modifier state update

    if ($currentState[6] == 1)
        $this->controlBox_timeAddSelector = 0;
    else if ($currentState[7] == 1)
        $this->controlBox_timeAddSelector = 2;
    else
        $this->controlBox_timeAddSelector = 1;

    for ($i = 0; $i < 64; $i++)
    {
        // Skip ignored buttons
        if (in_array($i, $this->ignoreButtons)) {
            continue;
        }

        $elapsed = $currentTime - ($currentTime-200);

        if ($elapsed >= 100) // Debounce threshold
        {
            $action = '';
            // Detect direction of change
            if ($currentState[$i] === '1') {
                $action = 'Pressed'; // 0 → 1
                $this->HandleButton_Press($this->buttonAction[$i]);
                /* DEBUG - enable this to see pressed button */
                //$this->Database->Query("INSERT INTO hbkpravotice_buttons (buttons) VALUES ('".$this->Database->EscapeString($action.":".$i)."')");
            } else {
                $action = 'Released'; // 1 → 0
                $this->HandleButton_Release($this->buttonAction[$i]);
                // Optionally handle release
            }
            // $this->Database->Query("INSERT INTO hbkpravotice_buttons (buttons) VALUES ('".$this->Database->EscapeString($action.":".$i)."')");
        }
    }
}

    function HandleButton_Press($button)
    {
        switch ($button) 
        {
        case 0:
            $this->HandleButton_TimerNextEvent();
            break;
        case 1: /* remove time */
            //handleButton(2);
            break;
        case 2:
            //handleButton(3);
			// remove time
            $timeValue = 1;
            if ($this->controlBox_timeAddSelector == 0)
            {
                /* seconds */
            }
            else if ($this->controlBox_timeAddSelector == 1)
            {
                /* 10 seconds */
                $timeValue = 10;
            }
            else if ($this->controlBox_timeAddSelector == 2)
            {
                /* minutes */
                $timeValue = 60;
            }
            $this->HandleButton_TimerAddRemainingTime(-$timeValue);
            break;
        case 3:
            //handleButton(4);
            break;
        case 4:
            //handleButton(5);
            break;
        case 5: /* add time */
            //handleButton(6);
            // add time
            $timeValue = 1;
            if ($this->controlBox_timeAddSelector == 0)
            {
                /* seconds */
            }
            else if ($this->controlBox_timeAddSelector == 1)
            {
                /* 10 seconds */
                $timeValue = 10;
            }
            else if ($this->controlBox_timeAddSelector == 2)
            {
                /* minutes */
                $timeValue = 60;
            }
            $this->HandleButton_TimerAddRemainingTime($timeValue);
            break;
        case 6:
            //handleButton(7);
            break;
        case 7:
            //handleButton(8);
            break;
        case 8:
            //handleButton(9);
            break;
        case 9:
            //handleButton(10);
            break;
        case 10: /* MODIFIER */
            break;
        case 11:
            //handleButton(12);
            break;
        case 12: // TIME RESET
            //handleButton(13);
            break;
        case 13:
            $this->HandleButton_StopTimer();
            break;
        case 14:
            $this->HandleButton_StartTimer();
            break;
        case 15:
            $this->HandleButton_TimerPreviousEvent();
            break;
        case 16: // penalty TR
            $this->HandleButton_RemoveLastAddedTeamPenalty(1);
            break;
        case 17: // penalty cancel home
            $this->HandleButton_RemoveSmallPenaltyWithLowestDuration(1);
            break;
        case 18: // penalty LARGE home
            $this->HandleButton_AddPenalty(1, $this->GetPenaltySizeFromActiveGame('Large'));
            break;
        case 19: // penalty MEDIUM home
            $this->HandleButton_AddPenalty(1, $this->GetPenaltySizeFromActiveGame('Medium'));
            break;
        case 20: // penalty SMALL home
            $this->HandleButton_AddPenalty(1, $this->GetPenaltySizeFromActiveGame('Small'));
            break;
        case 21:
            $this->HandleButton_RemoveGoal(1);
            break;
        case 22:
            $this->HandleButton_AddGoal(1);
            break;
        case 23:
            $this->HandleButton_StartGoalHorn(1);
            break;
        case 24:
            //handleButton(25); 
            //$this->HandleButton_AddGoal(1);
            break;
        case 25:
            //handleButton(26);
            break;
        case 26:
            //handleButton(27); 
            break;
        case 27: // TIMEOUT AWAY
            $this->HandleButton_TimeoutSound(1);
            break;
        case 28:
            $this->HandleButton_PenaltyEndSound(1);
            break;
        case 29:
            $this->HandleButton_PenaltyStartSound(1);
            break;
        case 30: // TEAM SOUND AWAY
            $this->HandleButton_StartTeamSound(1);
            break;
        case 31: // TO AWAY
            //handleButton(32);
            break;
        case 32: // penalty delete TR HOME
            $this->HandleButton_RemoveLastAddedTeamPenalty(0);
            break;
        case 33: // penalty delete HOME - delete last penalty for home team
            $this->HandleButton_RemoveSmallPenaltyWithLowestDuration(0);
            break;
        case 34: // penalty LARGE home
            $this->HandleButton_AddPenalty(0, $this->GetPenaltySizeFromActiveGame('Large'));
            break;
        case 35: // penalty MEDIUM home
            $this->HandleButton_AddPenalty(0, $this->GetPenaltySizeFromActiveGame('Medium'));
            break;
        case 36: // penalty SMALL home
            $this->HandleButton_AddPenalty(0, $this->GetPenaltySizeFromActiveGame('Small'));
            break;
        case 37:
            $this->HandleButton_RemoveGoal(0);
            break;
        case 38: /* add goal HOME */
            $this->HandleButton_AddGoal(0);
            break;
        case 39:
            $this->HandleButton_StartGoalHorn(0);
            break;
        case 40:
            break;
        case 41:
            break;
        case 42:
            break;
        case 43: // TIMEOUT HOME
            $this->HandleButton_TimeoutSound(0);
            break;
        case 44:
            $this->HandleButton_PenaltyEndSound();
            break;
        case 45:
            $this->HandleButton_PenaltyStartSound();
            break;
        case 46: // TEAM SOUND HOME
            $this->HandleButton_StartTeamSound(0);
            break;
        case 47: // TO HOME
            //handleButton(48);
            break;
        case 48:
            //handleButton(49);
            break;
        case 49:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                //$this->ControlBox_ElSocketToggle(0);
                $this->SendCommand("musicBreak");
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                //$this->HandleButton_StartSound(6);
                $this->HandleButton_StartMusic();
            }
            break;
        case 50:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->ControlBox_ElSocketToggle(2);
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_StartSound(3);
            }
            break;
        case 51:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                //$this->ControlBox_ElSocketToggle(0);
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_StartSound(5);
            }
            break;
        case 52:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->ControlBox_ElSocketToggle(1);
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_StartSound(2);
            }
            break;
        case 53:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->ControlBox_ElSocketToggle(3);
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_StartSound(4);
            }
            break;
        case 54:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->ControlBox_ElSocketToggle(0);
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_StartSound(1);
            }
            break;
        case 55:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->HandleButton_TimerResetMatch();
            }
            else
            {
                $this->HandleButton_StopSound();
            }
            
            break;
        case 56:
            //handleButton(57);
            break;
        case 57:
            //handleButton(58);
            break;
        case 58:
            //handleButton(59);
            break;
        case 59:
            //handleButton(60);
            break;
        case 60:
            //handleButton(61);
            break;
        case 61:
            //handleButton(62);
            break;
        case 62:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->HandleButton_MusicVolumeDown();
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_SoundVolumeDown();
            }
            break;
        case 63:
            if ($this->ControlBox_GetModifierState() == 1)
            {
                // if modifier is pressed, start goal horn
                $this->HandleButton_MusicVolumeUp();
            }
            else
            {
                // if modifier is not pressed, start penalty start sound
                $this->HandleButton_SoundVolumeUp();
            }
            break;
        default: break;
        }   
    }
    function HandleButton_Release($button)
    {
        switch ($button) 
        {
        default: break;
        }   
    }

    function HandleButton_StopSound()
    {
        $this->SendCommand('stop');
    }
    function HandleButton_SoundVolumeUp()
    {
        if ($this->LocationConfig->soundVolume >= 100) return; // max volume reached
        $this->SoundSetVolume($this->LocationConfig->soundVolume+5);
    }
    function HandleButton_SoundVolumeDown()
    {
        $newVOlume = $this->LocationConfig->soundVolume-5;
        if ($newVOlume < 0) $newVOlume = 0;
        $this->SoundSetVolume($newVOlume);
    }
    function HandleButton_MusicVolumeUp()
    {
        if ($this->LocationConfig->musicVolume >= 100) return; // max volume reached
        $this->MusicSetVolume($this->LocationConfig->musicVolume+5);
    }
    function HandleButton_MusicVolumeDown()
    {
        $newVOlume = $this->LocationConfig->musicVolume-5;
        if ($newVOlume < 0) $newVOlume = 0;
        $this->MusicSetVolume($newVOlume);
    }
    function HandleButton_StartTeamSound($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $game = $this->GetGame($gameid);
        $team = $this->GetTeam($away == 0 ? $game->team1 : $game->team2);
        $nameFilename = $this->GetSoundFile($team->songFile)->name;
        $this->SoundPlayFile($nameFilename);
    }
    function HandleButton_StartSound($soundNumber)
    {
        switch ($soundNumber)
        {
            case 1:
                $this->SoundPlayFile($this->controlBox_soundFile1);
                break;
            case 2:
                $this->SoundPlayFile($this->controlBox_soundFile2);
                break;
            case 3:
                $this->SoundPlayFile($this->controlBox_soundFile3);
                break;
            case 4:
                $this->SoundPlayFile($this->controlBox_soundFile4);
                break;
            case 5:
                $this->SoundPlayFile($this->controlBox_soundFile5);
                break;
            case 6:
                $this->SoundPlayFile($this->controlBox_soundFile6);
                break;
            default:
                break;
        }
    }
    function HandleButton_StartMusic()
    {
        $this->SoundPlayRandomMusic();
    }
    function HandleButton_StartGoalHorn($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
		$game = $this->GetGame($gameid);
        if ($away == 0)
        {
            $team = $this->GetTeam($game->team1);
            $soundFilename = $this->GetSoundFile($team->goalHorn)->name;
            $this->SoundPlayFile($soundFilename);
        }
        else
        {
            $team = $this->GetTeam($game->team2);
            $soundFilename = $this->GetSoundFile($team->goalHorn)->name;
            $this->SoundPlayFile($soundFilename);
        }
    }
    function HandleButton_PenaltyStartSound($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $soundFilename = "penaltyStart.mp3"; // default sound for penalty start
        $game = $this->GetGame($gameid);
        if ($away == 0)
        {
            $team = $this->GetTeam($game->team1);
            $nameFilename = $this->GetSoundFile($team->nameSoundFile)->name;
            $this->SoundPlayFile($nameFilename, $soundFilename);
        }
        else
        {
            $team = $this->GetTeam($game->team2);
            $nameFilename = $this->GetSoundFile($team->nameSoundFile)->name;
            $this->SoundPlayFile($nameFilename, $soundFilename);
        }
    }
    function HandleButton_PenaltyEndSound($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $game = $this->GetGame($gameid);
        $soundFilename = "penaltyEnd.mp3"; // default sound for penalty end
        if ($away == 0)
        {
            $team = $this->GetTeam($game->team1);
            $nameFilename = $this->GetSoundFile($team->nameSoundFile)->name;
            $this->SoundPlayFile($nameFilename, $soundFilename);
        }
        else
        {
            $team = $this->GetTeam($game->team2);
            $nameFilename = $this->GetSoundFile($team->nameSoundFile)->name;
            $this->SoundPlayFile($nameFilename, $soundFilename);
        }
    }
    function HandleButton_TimeoutSound($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $soundFilename = "timeout.mp3"; // default sound for timeout
        $game = $this->GetGame($gameid);
        if ($away == 0)
        {
            $team = $this->GetTeam($game->team1);
            $nameFilename = $this->GetSoundFile($team->nameSoundFile)->name;
            $this->SoundPlayFile($nameFilename, $soundFilename);
        }
        else
        {
            $team = $this->GetTeam($game->team2);
            $nameFilename = $this->GetSoundFile($team->nameSoundFile)->name;
            $this->SoundPlayFile($nameFilename, $soundFilename);
        }
    }
    function HandleButton_AddGoal($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
		$game = $this->GetGame($gameid);
        $timestamp = $this->GetGoalTimestampFromActiveEvent($gameid);
        $goalPeriod = $this->GetGoalPeriodFromActiveEvent($gameid);
        $this->AddGoal($gameid, $away == 0 ? $game->team1 : $game->team2, 0, $timestamp, 0, 0, $goalPeriod);
        $this->Log("create", 'goal:'.$gameid.' away:'.$away);
    }
    function HandleButton_RemoveGoal($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
		$game = $this->GetGame($gameid);
        $this->RemoveGoal($gameid, $away == 0 ? $game->team1 : $game->team2);
    }
    function HandleButton_StartTimer()
    {
        $this->Timer->Start();
    }
    function HandleButton_StopTimer()
    {
        $this->Timer->Stop();
    }
    function HandleButton_TimerNextEvent()
    {
        $this->Timer->NextEvent();
    }
    function HandleButton_TimerPreviousEvent()
    {
        $this->Timer->PreviousEvent();
    }
    function HandleButton_TimerResetMatch()
    {
        $this->Timer->ResetEventSequence();
    }
    function HandleButton_TimerAddRemainingTime($timeVal=0)
    {

        $this->Timer->AddRemainingTime($timeVal);

    }

    private function GetPenaltySizeFromActiveGame($type)
    {
        $gameid = intval($this->LocationConfig->activeGame);
        $ggsgames = DbObj_Ggsgame::ReadAll($this->Database, $this->App->GetDbPrefix(), 'WHERE game='.$gameid.' LIMIT 1');
        if (empty($ggsgames)) return 0;
        $ggs = $this->GetGameGroupStats($ggsgames[0]->ggs);
        if ($type === 'Small')  return intval($ggs->penaltySmall);
        if ($type === 'Medium') return intval($ggs->penaltyMedium);
        if ($type === 'Large')  return intval($ggs->penaltyLarge);
        return 0;
    }

    function HandleButton_AddPenalty($away=0, $penaltySize=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $timestamp = $this->GetGoalTimestampFromActiveEvent($gameid);
        // DEBUG
        $gameid_int = intval($gameid);
        $ggsgames = DbObj_Ggsgame::ReadAll($this->Database, $this->App->GetDbPrefix(), 'WHERE game='.$gameid_int.' LIMIT 1');
        $ggsid = empty($ggsgames) ? 'NO GGS FOUND' : $ggsgames[0]->ggs;
        error_log("HandleButton_AddPenalty: gameid=$gameid_int away=$away penaltySize=$penaltySize ggsid=$ggsid");
        // END DEBUG
        $penaltyId = $this->AddPenalty($gameid, $away, $penaltySize, $timestamp);
        $this->Log("create", 'penalty:'.$penaltyId.' away:'.$away.' size:'.$penaltySize);
    }

    function HandleButton_RemoveLastAddedTeamPenalty($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $this->RemoveLastAddedTeamPenalty($gameid, $away);
    }

    function HandleButton_RemoveSmallPenaltyWithLowestDuration($away=0)
    {
        $gameid = $this->LocationConfig->activeGame;
        $this->RemoveSmallPenaltyWithLowestDuration($gameid, $away);
    }

    function ControlBox_GetButtonState($buttonId)
    {
        $buttonId = intval($buttonId);
        if ($buttonId < 0 || $buttonId > 63) return '0';
        if (isset(self::$prevState[$buttonId]))
            return self::$prevState[$buttonId];
        else
            return '0';
    }
    function ControlBox_GetModifierState()
    {
        return $this->controlBox_modifier;
    }
    function ControlBox_ElSocketToggle($socketId)
    {
        $socketId = intval($socketId);
        if ($socketId < 0 || $socketId >= $this->LocationConfig->sockets) return;
        $dbobj = new DbObj_Elsocket($this->Database, $this->App->GetDbPrefix());
        $dbobj->location = $this->LocationConfig->location;
        $dbobj->socketId = $socketId;
        $dbobj->ReadSocketIDinLocation();
        if ($dbobj->id > 0) // socket exists
        {
            $dbobj->state = !$dbobj->state; // toggle state
            $this->SetElsocket($socketId, $dbobj->state);
        }
    }

    public function AddPenalty($gameid, $away=0, $penaltyStandardSize=0, $timestamp=0)
    {
        $game = $this->GetGame($gameid);
        $team = $away == 0 ? $game->team1 : $game->team2;
        $duration = intval($penaltyStandardSize);
        $penaltyType = 0; // default: Minor

        $penaltyTimerId = $this->AddPenaltyTimer($gameid, 0, $team, $penaltyType, $duration);

        $dbobj = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
        $dbobj->timestamp = $timestamp;
        $dbobj->game = $gameid;
        $dbobj->team = $team;
        $dbobj->player = 0;
        $dbobj->duration = $duration;
        $dbobj->penType = $penaltyType;
        $dbobj->timerid = $penaltyTimerId;
        $dbobj->Create();

        $penaltyId = $this->Database->GetLastInsertID();
        $this->Log("create", 'penalty:'.$penaltyId);
        return $penaltyId;
    }
    public function RemoveSmallPenaltyWithLowestDuration($gameid, $away=0)
    {
        $game = $this->GetGame($gameid);
        $dbobj = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
        $dbobj->game = $gameid;
        $dbobj->team = $away == 0 ? $game->team1 : $game->team2;
        $dbobj->ReadSmallestPenalty();
        if ($dbobj->id > 0)
        {
            $this->DeletePenaltyEvents($dbobj->timerid);
            $dbobj->Delete();
            $this->Log("delete", 'penalty:'.$dbobj->id);
        }
    }
    public function RemoveLastAddedTeamPenalty($gameid, $away=0)
    {
        $game = $this->GetGame($gameid);
        $dbobj = new DbObj_Penalty($this->Database, $this->App->GetDbPrefix());
        $dbobj->game = $gameid;
        $dbobj->team = $away == 0 ? $game->team1 : $game->team2;
        $dbobj->ReadLastPenaltyInGame();
        if ($dbobj->id > 0)
        {
            $this->DeletePenaltyEvents($dbobj->timerid);
            $dbobj->Delete();
            $this->Log("delete", 'penalty:'.$dbobj->id);
        }
    }

    public function ConvertGameToStadiumGame($game)
    {
        if ($game->id > 0)
        {
            $stadiumGame = new StadiumControl_Game();
            $stadiumGame->id = $game->id;
            $stadiumGame->referee = $game->referee;
            $stadiumGame->refereeRating = $game->refereeRating;
            $stadiumGame->visitors = $game->visitors;
            $stadiumGame->team1 = $game->team1;
            $stadiumGame->team2 = $game->team2;
            $stadiumGame->team1name = $this->GetTeam($game->team1)->name;
            $stadiumGame->team2name = $this->GetTeam($game->team2)->name;
            $stadiumGame->team1ImagePath = $this->GetTeam($game->team1)->imagePath;
            $stadiumGame->team2ImagePath = $this->GetTeam($game->team2)->imagePath;
            $stadiumGame->team1goalsCount = $this->GetTeamNumberOfGoalsInGame($game->id, $game->team1);
            $stadiumGame->team2goalsCount = $this->GetTeamNumberOfGoalsInGame($game->id, $game->team2);
            $stadiumGame->name = $game->name;
            $stadiumGame->location = $game->location;
            $stadiumGame->startTime = $game->startTime;
            $stadiumGame->note = $game->note;
            $stadiumGame->afterGameStatus = $game->afterGameStatus;
            $stadiumGame->gameGroup = $game->gameGroup;
            $stadiumGame->eventGroup = $game->eventGroup;

            return $stadiumGame;
        }
        return null;
    }
    public function ConvertGamesToStadiumGames($games)
    {
        $stadiumGames = [];
        foreach ($games as $game)
        {
            $stadiumGame = $this->ConvertGameToStadiumGame($game);
            if ($stadiumGame != null)
            {
                $stadiumGames[] = $stadiumGame;
            }
        }
        return $stadiumGames;
    }

    public function CreateJson($object)
    {
        $json = json_encode($object);
        $data = json_decode($json, true); // Convert to associative array
        unset($data['Database'], $data['ReadableIdentifier']); // Remove unwanted keys
        $filteredJson = json_encode($data); // Re-encode
        return $filteredJson;
    }
    public function HandleAPI_GET_Request($endpoint, $parameters)
    {
        $endpoint = strtolower($endpoint);
        /* parse parameters into array */
        $queryString = http_build_query($parameters);
        $rawParams = explode('&', $queryString);
        $params = [];
        foreach ($rawParams as $pair) 
        {
            list($key, $value) = explode('=', $pair);
            $params[$key] = $value;
        }
        /* echo array of parameters */
        //print_r($params);
        switch ($endpoint) 
        {
            case 'games':
                $eventGroup = array_key_exists('eventgroup', $params) ? intval($params['eventgroup']) : 0;
                $webpageId = array_key_exists('webpageid', $params) ? intval($params['webpageid']) : 0;
                $gameGroupId = array_key_exists('gamegroup', $params) ? intval($params['gamegroup']) : 0;
                $seasonId = array_key_exists('seasonid', $params) ? $params['seasonid'] : '';

                $conditions = array();

                if ($webpageId > 0) {
                    // Ak je zadaný webpageid, filtrujeme hry cez ggsgame tabuľku
                    $ggsCond = "webpageId = " . $webpageId;
                    if ($seasonId !== '') {
                        $ggsCond .= " AND seasonId = '" . $this->Database->EscapeString($seasonId) . "'";
                    }
                    $conditions[] = "id IN (SELECT game FROM " . $this->App->GetDbPrefix() . "ggsgame WHERE ggs IN (SELECT id FROM " . $this->App->GetDbPrefix() . "gamegroupstats WHERE " . $ggsCond . "))";
                } else {
                    // Inak použijeme pôvodné filtre
                    if ($eventGroup > 0) {
                        $conditions[] = "eventGroup=" . $eventGroup;
                    }
                    if ($gameGroupId > 0) {
                        $conditions[] = "id IN (SELECT game FROM " . $this->App->GetDbPrefix() . "ggsgame WHERE ggs = " . $ggsId . ")";
                    }
                }

                $addCond = (count($conditions) > 0) ? " WHERE " . implode(" AND ", $conditions) : "";
                
                $games_dbobj = $this->GetGames($addCond);
                $games = $this->ConvertGamesToStadiumGames($games_dbobj);
                echo $this->CreateJson($games);
                break;
            case 'game':
                if (array_key_exists('id', $params))
                {
                    $game_dbobj = $this->GetGame(intval($params['id']));
                    $game = $this->ConvertGameToStadiumGame($game_dbobj);
                    echo $this->CreateJson($game);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing game id']);
                }
                
                break;
			case 'gamegroupstats':
				$webpageId = array_key_exists('webpageid', $params) ? intval($params['webpageid']) : 0;
				if (array_key_exists('id', $params) && intval($params['id']) > 0)
				{
					$ggs = $this->GetGameGroupStats(intval($params['id']));
					echo $this->CreateJson($ggs);
				}
				else if (array_key_exists('seasonid', $params))
				{
					if ($webpageId > 0)
						$ggs = $this->GetGameGroupStatsBySeasonAndWebpage($params['seasonid'], $webpageId);
					else
						$ggs = $this->GetGameGroupStatsBySeason($params['seasonid']);
					echo $this->CreateJson($ggs);
				}
				else
				{
					if ($webpageId > 0)
						echo $this->CreateJson($this->GetAllGameGroupStatsByWebpage($webpageId));
					else
						echo $this->CreateJson($this->GetAllGameGroupStats());
				}
				break;
            case 'gameteamgoalscount':
                if (array_key_exists('id', $params) && array_key_exists('team', $params))
                {
                    $goals = $this->GetTeamNumberOfGoalsInGame(intval($params['id']), intval($params['team']));
                    echo $this->CreateJson($goals);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing game id or team id']);
                }
                break;
            case 'gameteamgoals':
                if (array_key_exists('id', $params) && array_key_exists('team', $params))
                {
                    $goals = GameGoal::ReadAll(intval($params['id']), intval($params['team']), $this->Database, $this->App->GetDbPrefix());
                    echo $this->CreateJson($goals);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing game id or team id']);
                }
                break;
            case 'gameteampenalties':
                if (array_key_exists('id', $params) && array_key_exists('team', $params))
                {
                    $gameid = intval($params['id']);
                    $teamid = intval($params['team']);
                    $addCond = ' WHERE game='.$gameid. ' AND team='.$teamid;
		            $penalties = DbObj_Penalty::ReadAll($this->Database, $this->App->GetDbPrefix(), $addCond);
                    echo $this->CreateJson($penalties);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing game id or team id']);
                }
                break;
            case 'gameteamroster':
                if (array_key_exists('id', $params) && array_key_exists('team', $params))
                {
                    $gameid = intval($params['id']);
                    $teamid = intval($params['team']);
                    $dbAddCond = 'WHERE team='.$teamid. " AND game=".$gameid;
                    $gameroster = DbObj_Gameroster::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
                    echo $this->CreateJson($gameroster);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing game id or team id']);
                }
                break;
            case 'teamroster':
                if (array_key_exists('team', $params))
                {
                    $teamid = intval($params['team']);
                    $dbAddCond = 'WHERE team='.intval($teamid). "";
                    $gameroster = DbObj_Teamhasplayer::ReadAll($this->Database, $this->App->GetDbPrefix(), $dbAddCond);
                    echo $this->CreateJson($gameroster);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing team id']);
                }
                break;
            case 'teams':
                $teams = $this->GetTeams();
                echo $this->CreateJson($teams);
                break;
            case 'team':
                if (array_key_exists('id', $params))
                {
                    $team_dbobj = $this->GetTeam(intval($params['id']));
                    echo $this->CreateJson($team_dbobj);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing team id']);
                }
                break;
            case 'players':
                if (array_key_exists('team', $params))
                {
                    $players = $this->GetPlayer(intval($params['team']));
                }
                else
                {
                    $players = $this->GetPlayers(0);
                }
                echo $this->CreateJson($players);
                break;
            case 'player':
                if (array_key_exists('id', $params))
                {
                    $player_dbobj = $this->GetPlayer(intval($params['id']));
                    echo $this->CreateJson($player_dbobj);
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing player id']);
                }
                break;
            case 'playerstats':
                if (array_key_exists('id', $params))
                {
                    $ggsid = intval($_GET['id']);
                    $stats = new Stats($this, $this->Database, $this->App->GetDbPrefix(), $ggsid);
		            $jsonData = $stats->ReadStats_Players();
                    echo $jsonData;
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing season id']);
                }
                break;
            case 'teamstats':
                if (array_key_exists('id', $params))
                {
                    $ggsid = intval($_GET['id']);
                    $stats = new Stats($this, $this->Database, $this->App->GetDbPrefix(), $ggsid);
		            $jsonData = $stats->ReadStats_Teams();
                    echo $jsonData;
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing season id']);
                }
                break;
            case 'gamedetails':
                if (array_key_exists('id', $params))
                {
                    $gameid = intval($params['id']);
                    $game = $this->GetGame($gameid);
                    if ($game->id > 0)
                    {
                        $stadiumGame = $this->ConvertGameToStadiumGame($game);
                        $stadiumGame->goals = GameGoal::ReadAll($gameid, 0, $this->Database, $this->App->GetDbPrefix());
                        $stadiumGame->penalties = DbObj_Penalty::ReadAll($this->Database, $this->App->GetDbPrefix(), ' WHERE game='.$gameid);
                        $stadiumGame->rosterTeam1 = DbObj_Gameroster::ReadAll($this->Database, $this->App->GetDbPrefix(), ' WHERE game='.$gameid.' AND team='.$game->team1);
                        $stadiumGame->rosterTeam2 = DbObj_Gameroster::ReadAll($this->Database, $this->App->GetDbPrefix(), ' WHERE game='.$gameid.' AND team='.$game->team2);
                        // add player details to roster
                        foreach ($stadiumGame->rosterTeam1 as $index => $rosterEntry)
                        {
                            $player = $this->GetPlayer($rosterEntry->player);
                            if ($player->id > 0)
                            {
                                $stadiumGame->rosterTeam1[$index]->playerName = $player->name;
                                $stadiumGame->rosterTeam1[$index]->playerImage = $player->imagePath;
                                $stadiumGame->rosterTeam1[$index]->playerDateOfBirth = $player->dateOfBirth;
                                $stadiumGame->rosterTeam1[$index]->playerSkillLevel = $player->skillLevel;
                            }
                        }
                        foreach ($stadiumGame->rosterTeam2 as $index => $rosterEntry)
                        {
                            $player = $this->GetPlayer($rosterEntry->player);
                            if ($player->id > 0)
                            {
                                $stadiumGame->rosterTeam2[$index]->playerName = $player->name;
                                $stadiumGame->rosterTeam2[$index]->playerImage = $player->imagePath;
                                $stadiumGame->rosterTeam2[$index]->playerDateOfBirth = $player->dateOfBirth;
                                $stadiumGame->rosterTeam2[$index]->playerSkillLevel = $player->skillLevel;
                            }
                        }

                        echo $this->CreateJson($stadiumGame);
                    }
                    else
                    {
                        echo $this->CreateJson(['error' => 'Game not found']);
                    }
                }
                else
                {
                    echo $this->CreateJson(['error' => 'Missing game id']);
                }
                break;
            default:
                echo $this->CreateJson(['error' => 'Unknown endpoint']);
                break;
        }
        exit;
    }
}
?>