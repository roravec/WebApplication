<?php

#[\AllowDynamicProperties]
class StadiumControlApi
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public static $ApiUrl = "https://hbkpravotice.sk/StadiumControl";
  
	public static function Get($endpoint, $parameters)
	{
        // add all parameters to URL as "id" and separate them with |
        $url = self::$ApiUrl."?api=".$endpoint;
        foreach($parameters as $key => $value)
        {
            $url .= "&".$key."=".$value;
        }
        // if ($endpoint === "team")
        // {
        //     echo "\n".$url."\n";
        //     exit;
        // }
		$json = file_get_contents($url);
        return $json; // or use cURL for more control
	}

    public static function GetGames($parameters = array())
    {
        $games = array();
        $json = self::Get("games", $parameters);
        $data = json_decode($json, true);
        foreach($data as $item)
        {
            $games[] = StadiumControl_Game::fromJson(json_encode($item));
        }
        return $games;
    }
    public static function GetPlayers($parameters = array())
    {
        $players = array();
        $json = self::Get("players", $parameters);
        $data = json_decode($json, true);
        foreach($data as $item)
        {
            $players[] = StadiumControl_Player::fromJson(json_encode($item));
        }
        return $players;
    }
    public static function GetTeams($parameters = array())
    {
        $teams = array();
        $json = self::Get("teams", $parameters);
        $data = json_decode($json, true);
        foreach($data as $item)
        {
            $teams[] = StadiumControl_Team::fromJson(json_encode($item));
        }
        return $teams;
    }
    public static function GetGame($id)
    {
        return StadiumControl_Game::fromJson(self::Get("game", array("id" => $id)));
    }
    public static function GetPlayer($id)
    {
        return StadiumControl_Player::fromJson(self::Get("player", array("id" => $id)));
    }
    public static function GetTeam($id)
    {
        return StadiumControl_Team::fromJson(self::Get("team", array("id" => $id)));
    }
    public static function GetGameTeamGoalCount($gameId, $teamId)
    {
        $json = self::Get("gameteamgoalscount", array("id" => $gameId, "team" => $teamId));
        $data = json_decode($json, true);
        return $data;
    }
    public static function GetGameTeamGoals($gameId, $teamId)
    {
        $json = self::Get("gameteamgoals", array("id" => $gameId, "team" => $teamId));
        $data = json_decode($json, true);
        return $data;
    }
    public static function GetGameTeamPenalties($gameId, $teamId)
    {
        $json = self::Get("gameteampenalties", array("id" => $gameId, "team" => $teamId));
        $data = json_decode($json, true);
        return $data;
    }
    public static function GetGameTeamRoster($gameId, $teamId)
    {
        $json = self::Get("gameteamroster", array("id" => $gameId, "team" => $teamId));
        $data = json_decode($json, true);
        return $data;
    }
    public static function GetTeamRoster($teamId)
    {
        $json = self::Get("teamroster", array("team" => $teamId));
        $data = json_decode($json, true);
        return $data;
    }
    public static function GetTeamsStats($seasonId)
    {
        $json = self::Get("teamstats", array("id" => $seasonId));
        $data = json_decode($json, true);
        $stats = array();
        foreach($data as $item)
        {
            $stats[] = TeamStats::fromJson(json_encode($item));
        }
        return $stats;
    }
    public static function GetPlayerStats($seasonId)
    {
        $json = self::Get("playerstats", array("id" => $seasonId));
        $data = json_decode($json, true);
        $stats = array();
        foreach($data as $item)
        {
            $stats[] = PlayerStats::fromJson(json_encode($item));
        }
        return $stats;
    }
    public static function GetGameGroupStats($parameters = array())
    {
        $json = self::Get("gamegroupstats", $parameters);
        $data = json_decode($json, true);
        $stats = array();
        if (is_array($data))
        {
            foreach($data as $item)
            {
                $stats[] = GameGroupStats::fromJson(json_encode($item));
            }
        }
        else if (is_array($data) === false && is_object($data))
        {
            $stats[] = GameGroupStats::fromJson(json_encode($data));
        }
        return $stats;
    }
    public static function GetGameDetails($gameId)
    {
        $json = self::Get("gamedetails", array("id" => $gameId));
        $data = json_decode($json, true);
        return StadiumControl_Game::fromJson(json_encode($data));
    }
}

#[\AllowDynamicProperties]
abstract class JsonHydratable {
    public static function fromJson(string $json) {
        $data = json_decode($json, true);
        $object = new static();

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (property_exists($object, $key)) {
                    $object->$key = $value;
                }
            }
        }

        return $object;
    }
}

#[\AllowDynamicProperties]
class StadiumControl_Game extends JsonHydratable
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
class StadiumControl_Player extends JsonHydratable
{
    public $id=0;
	public $name="";
	public $imagePath="";
	public $dateOfBirth=0;
	public $skillLevel=0;
	public $notes="";
}

#[\AllowDynamicProperties]
class StadiumControl_Team extends JsonHydratable
{
    public $id=0;
	public $name="";
	public $shortName="";
	public $nameSoundFile=0;
	public $songFile=0;
	public $goalHorn=0;
	public $imagePath="";
}

#[\AllowDynamicProperties]
class PlayerStats extends JsonHydratable
{
    public $pointsPerGame = 0;
    public $totalPoints = 0;
    public $games = 0;
    public $goals = 0;
    public $assistances = 0;
    public $penaltySeconds = 0;
    public $playerid = 0;
    public $teamid = 0;
    public $playerName = '';
    public $playerImagePath = '';
    public $playerNumber = 0; // stored as string in JSON
    public $teamName = '';
    public $teamImagePath = '';
}

#[\AllowDynamicProperties]
class TeamStats extends JsonHydratable
{
    public $id = 0;
    public $name = '';
    public $shortName = '';
    public $teamPicture = '';
    public $points = 0;
    public $goalsScored = 0;
    public $goalsReceived = 0;
    public $goalDifference = 0;
    public $penalties = 0;
    public $games = 0;
    public $wins = 0;
    public $winsPP = 0;
    public $ties = 0;
    public $loses = 0;
    public $losesPP = 0;
}

#[\AllowDynamicProperties]
class GameGroupStats extends JsonHydratable
{
    public $id = 0;
    public $name = '';
    public $displayName = '';
    public $seasonId = '';
    public $webpageId = 0;
    public $pointsWin = 0;
    public $pointsLost = 0;
    public $pointsWinPp = 0;
    public $pointsLostPp = 0;
    public $pointsTie = 0;
    public $penaltySmall = 0;
    public $penaltyMedium = 0;
    public $penaltyLarge = 0;

    public function GetDisplayName()
    {
        if (trim($this->displayName) !== '')
            return $this->displayName;
        return $this->name;
    }
}

#[\AllowDynamicProperties]
class GameResult extends JsonHydratable
{
    public $id = 0;
    public $name = '';
    public $note = '';
    public $team1name = '';
    public $team2name = '';
    public $team1goals = 0;
    public $team2goals = 0;
    public $afterGameStatus = 0;
}

?>