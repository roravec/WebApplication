<?php

#[\AllowDynamicProperties]
class PlayerStats
{	
	public function __construct($playerid, $teamid)
	{
		$this->playerid = $playerid;
		$this->teamid = $teamid;
	}
	public function AddGame($count=1)
	{
		$this->games += $count;
	}
	public function AddGoal($count=1)
	{
		$this->goals += $count;
	}
	public function AddAssistance($count=1)
	{
		$this->assistances += $count;
	}
	public function AddPenaltySec($count=1)
	{
		$this->penaltySeconds += $count;
	}
	public function GetPoints()
	{
		return ($this->goals + $this->assistances);
	}
	public function RecalculateTotalPoints()
	{
		$this->totalPoints = $this->GetPoints();
	}
    public function RecalculatePointsPerGame()
    {
        if ($this->games > 0)
            $this->pointsPerGame = round($this->GetPoints() / $this->games, 2);
        else
            $this->pointsPerGame = 0;
    }
	public $totalPoints = 0;
    public $pointsPerGame = 0;
	public $games = 0;
	public $goals = 0;
	public $assistances = 0;
	public $penaltySeconds = 0;
	public $playerid = 0;
	public $teamid = 0;
	public $playerName = "";
    public $playerImagePath = "";
	public $playerNumber = 0;
	public $teamName = "";
	public $teamImagePath = "";
}

#[\AllowDynamicProperties]
class TeamStats
{
	public function __construct($teamObj)
	{
		$this->id = $teamObj->id;
		$this->name = $teamObj->name;
		$this->shortName = $teamObj->shortName;
	}
	public function AddPoints($count=1)
	{
		$this->points += $count;
	}
	public function AddGoalScored($count=1)
	{
		$this->goalsScored += $count;
	}
	public function AddGoalReceived($count=1)
	{
		$this->goalsReceived += $count;
	}
	public function AddPenalties($count=1)
	{
		$this->penalties += $count;
	}
	public function AddGames($count=1)
	{
		$this->games += $count;
	}
	public function AddWin($count=1)
	{
		$this->wins += $count;
	}
	public function AddWinPP($count=1)
	{
		$this->winsPP += $count;
	}
	public function AddTie($count=1)
	{
		$this->ties += $count;
	}
	public function AddLose($count=1)
	{
		$this->loses += $count;
	}
	public function AddLosePP($count=1)
	{
		$this->losesPP += $count;
	}
	public $id = 0;
	public $name = "";
	public $shortName = "";
    public $teamPicture = "";
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
class GameStats
{
	public function __construct($gameObj, $team1name, $team2name, $team1goals=0, $team2goals=0)
	{
		$this->id = $gameObj->id;
		$this->name = $gameObj->name;
		$this->note = $gameObj->note;
		$this->team1name = $team1name;
		$this->team2name = $team2name;
		$this->team1goals = $team1goals;
		$this->team2goals = $team2goals;
		$this->afterGameStatus = $gameObj->afterGameStatus;
	}

	public $id = 0;
	public $name = "";
	public $note = "";
	public $team1name = "";
	public $team2name = "";
	public $team1goals = 0;
	public $team2goals = 0;
	public $afterGameStatus = 0;
}

#[\AllowDynamicProperties]
class Stats
{	
	public function __construct($stadiumControl, $database, $dbPrefix, $groupid)   // was $dbPrefix="" (always passed; PHP 8: optional before required)
	{
		$this->Database = $database;
		$this->DbPrefix = $dbPrefix;
		$this->Gamesgroupid = $groupid;
		$this->StadiumControl = $stadiumControl;
		$this->GamesGroupStats = $this->StadiumControl->GetGameGroupStats($this->Gamesgroupid);
	}
	private $Database = null;
	private $DbPrefix = "";
	public $Gamesgroupid = 0;
	public $GamesGroupStats = null;
	public $PlayerStats = array();
	public $TeamsStats = array();
	public $GamesStats = array();
	private $StadiumControl = null;

	public function GetPlayerStats($playerid, $teamid, $createIfNotExist = false)
	{
		foreach($this->PlayerStats as $playerStats)
		{
			if ($playerStats->playerid == $playerid && $playerStats->teamid == $teamid)
			{
				return $playerStats;
			}
		}
		if ($createIfNotExist)
		{
			// doesnt exist
			$playerStats = new PlayerStats($playerid, $teamid);
			$team = $this->StadiumControl->GetTeam($teamid);
			$player = $this->StadiumControl->GetPlayer($playerid);
			$playerNumber = $this->StadiumControl->GetPlayerNumberInTeam($playerid, $teamid);
			$playerStats->playerName = $player->GetName();
            $playerStats->playerImagePath = $player->imagePath;
			$playerStats->playerNumber = $playerNumber;
			$playerStats->teamName = $team->GetName();
			$playerStats->teamImagePath = $team->imagePath;
			$this->PlayerStats[] = $playerStats;
			return $playerStats;
		}
		else
		{
			return null;
		}
	}
	public function GetTeamStats($teamid, $createIfNotExist = false)
	{
		foreach($this->TeamsStats as $teamStats)
		{
			if ($teamStats->id == $teamid)
			{
				return $teamStats;
			}
		}
		if ($createIfNotExist)
		{
			// doesnt exist
			$team = $this->StadiumControl->GetTeam($teamid);
			$teamStats = new TeamStats($team);
            $teamStats->teamPicture = $team->imagePath;
			$this->TeamsStats[] = $teamStats;
			return $teamStats;
		}
		else
		{
			return null;
		}
	}

	public function AddGame($playerid, $teamid, $count=1)
	{
		$playerStats = $this->GetPlayerStats($playerid, $teamid, true);
		$playerStats->AddGame($count);
	}
	public function AddGoal($playerid, $teamid, $count=1)
	{
		$playerStats = $this->GetPlayerStats($playerid, $teamid, true);
		$playerStats->AddGoal($count);
	}
	public function AddAssistance($playerid, $teamid, $count=1)
	{
		$playerStats = $this->GetPlayerStats($playerid, $teamid, true);
		$playerStats->AddAssistance($count);
	}
	public function AddPenaltySec($playerid, $teamid, $count=1)
	{
		$playerStats = $this->GetPlayerStats($playerid, $teamid, true);
		$playerStats->AddPenaltySec($count);
	}

	public function SortPlayerStats()
	{
		usort($this->PlayerStats, array($this, 'comparePlayerStats'));
	}

	// Custom sorting function based on player points
	private function comparePlayerStats($a, $b) 
	{
    	$a->RecalculateTotalPoints();
        $b->RecalculateTotalPoints();
        $a->RecalculatePointsPerGame();
        $b->RecalculatePointsPerGame();

        $pointsDiff = $b->GetPoints() - $a->GetPoints();
        if ($pointsDiff !== 0) {
            return $pointsDiff;
        }
        // If points are equal, compare by goals
        return $b->goals - $a->goals;
	}

	public function ReadStats()
	{
		$dataJson = "{";
		$dataJson .= "\"players\":".$this->ReadStats_Players();
		$dataJson .= ",\"teams\":".$this->ReadStats_Teams();
		$dataJson .= ",\"games\":".$this->ReadStats_Games();
		$dataJson .= "}";
		return $dataJson;
	}
	public function ReadStats_Players()
	{
		$queryWhereGoals = "";
		$queryWherePenalties = "";
		$queryWherePlayedGames = "";
		$ggsgames = $this->StadiumControl->GetggsgamesOfGroup($this->Gamesgroupid);
		{
			$i = 0;
			foreach($ggsgames as $g)
			{
				if ($i > 0) 
				{
					$queryWhereGoals .= ' OR ';
					$queryWherePenalties .= ' OR ';
					$queryWherePlayedGames .= ' OR ';
				}
				$queryWhereGoals .= " game.id=".$g->game;
				$queryWherePenalties .= " game=".$g->game;
				$queryWherePlayedGames .= " game=".$g->game;

				$i++;
			}
		}
		if ($i==0) // list is empty, terminate
			return;

		// READ GOALS AND ASSISTANCES
		$queryGoals = '
SELECT 
game.id AS gameid, 
goal.player AS gplayer, 
goal.team AS gteam,
assist0.player AS a0player,
assist1.player AS a1player

FROM `'.$this->DbPrefix.'game` game
LEFT JOIN `'.$this->DbPrefix.'goal` goal ON goal.game = game.id
LEFT JOIN `'.$this->DbPrefix.'assistance` assist0 ON goal.id = assist0.goal AND assist0.assistOrder=0
LEFT JOIN `'.$this->DbPrefix.'assistance` assist1 ON goal.id = assist1.goal AND assist1.assistOrder=1

WHERE '.$queryWhereGoals.'
		;';

		$queryPenalties = '
SELECT *
FROM `'.$this->DbPrefix.'penalty`
WHERE '.$queryWherePenalties.'
				;';

		$queryPlayedGames = '
SELECT *
FROM `'.$this->DbPrefix.'gameroster`
WHERE '.$queryWherePenalties.'
				;';

		//echo "Query goals:". $queryGoals ."<br>Querypens:". $queryPenalties ."<br><br>";
		// GOALS AND ASSISTANCES
		$resultGoals = $this->Database->Query($queryGoals);
		while ($row = $resultGoals->fetch_assoc())
		{
			if (intval($row['gplayer']) != 0)
				$this->AddGoal(intval($row['gplayer']), intval($row['gteam']));
			if (intval($row['a0player']) != 0)
				$this->AddAssistance(intval($row['a0player']), intval($row['gteam']));
			if (intval($row['a1player']) != 0)
				$this->AddAssistance(intval($row['a1player']), intval($row['gteam']));
		}

		// PENALTIES
		$resultPens = $this->Database->Query($queryPenalties);
		while ($row = $resultPens->fetch_assoc())
		{
			if (intval($row['player']) != 0)
			{
				$this->AddPenaltySec(
					intval($row['player']), 
					intval($row['team']),
					intval($row['duration'])
				);
			}
		}

        // PLAYED GAMES
		$resultGames = $this->Database->Query($queryPlayedGames);
		while ($row = $resultGames->fetch_assoc())
		{
			if (intval($row['player']) != 0)
			{
				$this->AddGame(
					intval($row['player']), 
					intval($row['team'])
				);
			}
		}
		$this->SortPlayerStats();

		return json_encode($this->PlayerStats);
	}
	public function ReadStats_Teams()
	{
		$ggsgames = $this->StadiumControl->GetggsgamesOfGroup($this->Gamesgroupid);
		$i = 0;
		foreach($ggsgames as $g)
		{
			$game = $this->StadiumControl->GetGame($g->game);
			$team1stats = $this->GetTeamStats($game->team1, true);
			$team2stats = $this->GetTeamStats($game->team2, true);
            if ($game->afterGameStatus > 1) // count only finished games
            {
                $team1stats->AddGames();
			    $team2stats->AddGames();
            }
			
			switch ($game->afterGameStatus)
			{
				case 2: // home(team1) team won
					$team1stats->AddWin();
					$team2stats->AddLose();
					$team1stats->AddPoints($this->GamesGroupStats->pointsWin);
					$team2stats->AddPoints($this->GamesGroupStats->pointsLost);
					break;
				case 3: // away(team2) team won
					$team1stats->AddLose();
					$team2stats->AddWin();
					$team1stats->AddPoints($this->GamesGroupStats->pointsLost);
					$team2stats->AddPoints($this->GamesGroupStats->pointsWin);
					break;
				case 4: // home team won PP
					$team1stats->AddWinPP();
					$team2stats->AddLosePP();
					$team1stats->AddPoints($this->GamesGroupStats->pointsWinPp);
					$team2stats->AddPoints($this->GamesGroupStats->pointsLostPp);
					break;
				case 5: // away team won PP
					$team1stats->AddLosePP();
					$team2stats->AddWinPP();
					$team1stats->AddPoints($this->GamesGroupStats->pointsLostPp);
					$team2stats->AddPoints($this->GamesGroupStats->pointsWinPp);
					break;
				case 6: // tie
					$team1stats->AddTie();
					$team2stats->AddTie();
					$team1stats->AddPoints($this->GamesGroupStats->pointsTie);
					$team2stats->AddPoints($this->GamesGroupStats->pointsTie);
					break;
				default: break;
			}
			$team1stats->AddPenalties($this->StadiumControl->GetTeamPenaltySeconds($game->id, $game->team1));
			$team2stats->AddPenalties($this->StadiumControl->GetTeamPenaltySeconds($game->id, $game->team2));
			
			$team1stats->AddGoalScored($this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team1));
			$team2stats->AddGoalScored($this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team2));
			
			$team1stats->AddGoalReceived($this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team2));
			$team2stats->AddGoalReceived($this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team1));
		}
        // calculate goal difference
        foreach($this->TeamsStats as $teamStats)
        {
            $teamStats->goalDifference = $teamStats->goalsScored - $teamStats->goalsReceived;
        }
		return json_encode($this->TeamsStats);
	}
	public function ReadStats_Games()
	{
		$ggsgames = $this->StadiumControl->GetggsgamesOfGroup($this->Gamesgroupid);
		foreach($ggsgames as $g)
		{
			$game = $this->StadiumControl->GetGame($g->game);
			$this->GamesStats[] = new GameStats(
				$game,
				$this->StadiumControl->GetTeam($game->team1)->GetName(),
				$this->StadiumControl->GetTeam($game->team2)->GetName(),
				$this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team1),
				$this->StadiumControl->GetTeamNumberOfGoalsInGame($game->id, $game->team2)
			);
		}
		return json_encode($this->GamesStats);
	}
}

?>