<?php
include_once __DIR__ . "/HtmlContentBuilder/HTMLContent.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Article.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Footer.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Header.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Matches.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Match.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Body.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_Stats.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_TeamsTable.php";
include_once __DIR__ . "/HtmlContentBuilder/Module_HeroSmall.php";


include_once __DIR__ . "/StadiumControlApi.php";

#[\AllowDynamicProperties]
class WebApp   // contract of the oldest core: SetInitVariables() + GetHTML() - run by WebApplication\Legacy\OldCoreApplication
{
  /* Public  */
    public static $EventGroupId = 11;
        public static $WebpageId = 1;
        public static $SeasonName = "BNHL";
    private static $DefaultSeasonId = "BNHL27";

        private $currentSeasonId = "";   // Aktuálne vybraná sezóna (prázdne = neinicializovaná)
    private $currentGameGroupId = 0; // Aktuálne vybraná stat group (0 = neinicializovaná)

    public $StadiumControlGames = array();
    public $TeamStats = array();      // Štatistiky základnej časti
    public $PlayerStats = array();    // Štatistiky hráčov - základná časť
    public $TeamStatsPlayoff = array();    // Štatistiky play-off
    public $PlayerStatsPlayoff = array();  // Štatistiky hráčov - play-off
    public $Teams = array();
    public $GameGroupStats = array();
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
							"Bánovecká hokejbalová liga",
								$this->AssetsPath
						);
	}

	public function GetHTML($pageName)
	{
        $page = (is_array($pageName) && count($pageName) > 0) ? $pageName[0] : "";
        if (in_array($page, array("rozpistlac", "rozpisprint", "scheduleprint", "timtlac", "timprint", "teamprint"))) {
            $id = (count($pageName) > 1) ? $pageName[1] : 0;
            switch($page) {
                case "rozpistlac":
                case "rozpisprint":
                case "scheduleprint":
                    return $this->BuildPage_SchedulePrint($id);
                case "timtlac":
                case "timprint":
                case "teamprint":
                    $team = $this->GetTeamById($id);
                    return ($team != null) ? $this->BuildPage_TeamRosterPrint($team) : "404";
            }
        }

		$header = $this->CreateHeader();
		$footerContent = $this->CreateFooter();
		
		$content = $this->BuildPageContent($pageName);
		
		$this->HtmlContent->AddToContent($header);
		$this->HtmlContent->AddToContent("<main>");
		$this->HtmlContent->AddToContent($content);
		$this->HtmlContent->AddToContent("</main>");
		$this->HtmlContent->AddToContent($footerContent);
		return $this->HtmlContent->PageStart();
	}
	
	public function GetTxt($code)
	{
		return $this->App->GetTextFromDatabase($this->LanguageCode, $code);
	}
    
    /**
     * @return GameGroupStats[]
     */
    public function GetGameGroupStatsArr()
    {
        if (count($this->GameGroupStats) == 0) {
            $this->GameGroupStats = StadiumControlApi::GetGameGroupStats(
                array("webpageid" => self::$WebpageId)
            );
        }
        return $this->GameGroupStats;
    }

    private function GetAvailableSeasonIds()
    {
        $seasonIds = array();
        foreach ($this->GetGameGroupStatsArr() as $group) {
            /** @var GameGroupStats $group */
            $seasonId = trim(strval($group->seasonId));
            if ($seasonId !== "")
                $seasonIds[$seasonId] = true;
        }
        $seasonIds = array_keys($seasonIds);
        natsort($seasonIds);
        $seasonIds = array_values($seasonIds);
        return $seasonIds;
    }

    private function GetSeasonLabel($seasonId)
    {
        $label = strval($seasonId);
        if (preg_match('/^KC(\d{2})$/i', $label, $matches)) {
            return "Konovica Cup 20" . $matches[1];
        }
        return $label;
    }

    private function GetGroupsForSeason($seasonId)
    {
        $seasonId = trim(strval($seasonId));
        $groups = array();
        foreach ($this->GetGameGroupStatsArr() as $group) {
            /** @var GameGroupStats $group */
            if (trim(strval($group->seasonId)) === $seasonId)
                $groups[] = $group;
        }
        usort($groups, function($a, $b) {
            return intval($a->id) <=> intval($b->id);
        });
        return $groups;
    }

    private function GetDefaultGameGroupIdForSeason($seasonId)
    {
        $groups = $this->GetGroupsForSeason($seasonId);
        if (count($groups) > 0)
            return intval($groups[0]->id);
        return 0;
    }

    private function InitCurrentSeason()
    {
        $seasonIds = $this->GetAvailableSeasonIds();
        $defaultSeasonId = strval(self::$DefaultSeasonId);

        if (isset($_GET['season']) && trim(strval($_GET['season'])) !== '') {
            $requestedId = trim(strval($_GET['season']));
            if (in_array($requestedId, $seasonIds, true)) {
                $this->currentSeasonId = $requestedId;
                return;
            }
        }

        $this->currentSeasonId = $defaultSeasonId;
    }
    
    /**
     * Vrátí aktuálne ID sezóny
     */
    private function GetCurrentSeasonId()
    {
        if ($this->currentSeasonId === '') {
            $this->InitCurrentSeason();
        }
        return $this->currentSeasonId;
    }

    private function BuildSeasonUrl($path, $query = array())
    {
        $currentSeasonId = $this->GetCurrentSeasonId();
        if (trim(strval($currentSeasonId)) !== '') {
            $query['season'] = $currentSeasonId;
        } else {
            unset($query['season']);
        }
        $queryString = http_build_query($query);
        return $path . ($queryString !== '' ? '?' . $queryString : '');
    }

    private function InitCurrentGameGroup()
    {
        $seasonId = $this->GetCurrentSeasonId();
        $defaultGroupId = $this->GetDefaultGameGroupIdForSeason($seasonId);
        $groupsForSeason = $this->GetGroupsForSeason($seasonId);

        if (isset($_GET['ggs']) && intval($_GET['ggs']) > 0) {
            $requestedGroupId = intval($_GET['ggs']);
            foreach ($groupsForSeason as $group) {
                if (intval($group->id) === $requestedGroupId) {
                    $this->currentGameGroupId = $requestedGroupId;
                    return;
                }
            }
        }

        $this->currentGameGroupId = $defaultGroupId;
    }

    private function GetCurrentGameGroupId()
    {
        if ($this->currentGameGroupId == 0) {
            $this->InitCurrentGameGroup();
        }
        return $this->currentGameGroupId;
    }
    
    /**
     * Vrátí typ aktuálnej sezóny ('base' alebo 'playoff')
     */
    private function GetCurrentSeasonType()
    {
        return 'base';
    }
    
    /**
     * Vrátí label sezóny (napr. "Základná časť" alebo "Play-off")
     */
    private function GetCurrentStatGroupLabel()
    {
        $seasonId = $this->GetCurrentSeasonId();
        if ($seasonId !== '') {
            return $this->GetSeasonLabel($seasonId);
        }
        return "Štatistiky";
    }

    private function GetCurrentGameGroup()
    {
        $currentGroupId = $this->GetCurrentGameGroupId();
        foreach ($this->GetGameGroupStatsArr() as $group) {
            /** @var GameGroupStats $group */
            if (intval($group->id) === intval($currentGroupId))
                return $group;
        }
        return null;
    }

    private function GetCurrentGameGroupLabel()
    {
        $group = $this->GetCurrentGameGroup();
        if ($group != null) {
            if (method_exists($group, 'GetDisplayName'))
                return $group->GetDisplayName();
            if (isset($group->displayName) && trim($group->displayName) !== '')
                return $group->displayName;
            return $group->name;
        }
        return "Štatistiky";
    }
    public function GetStadiumControlGamesArr()
    {
        $currentSeasonId = $this->GetCurrentSeasonId();
        $cacheKey = 'Games_' . $currentSeasonId;
        
        if (!isset($this->$cacheKey) || count($this->$cacheKey) == 0) {
            $this->$cacheKey = StadiumControlApi::GetGames(array(
                "webpageid" => self::$WebpageId,
                "seasonid" => $currentSeasonId,
                "orderBy" => "startTime",
                "orderDir" => "ASC"
            ));
        }

        usort($this->$cacheKey, function($a, $b) {
            $timeA = is_numeric($a->startTime ?? null) ? intval($a->startTime) : strtotime(strval($a->startTime ?? 0));
            $timeB = is_numeric($b->startTime ?? null) ? intval($b->startTime) : strtotime(strval($b->startTime ?? 0));
            if ($timeA === false) $timeA = 0;
            if ($timeB === false) $timeB = 0;
            return $timeA <=> $timeB;
        });

        return $this->$cacheKey;
    }
    public function GetTeamStatsArr()
    {
        $currentGroupId = $this->GetCurrentGameGroupId();
        $cacheKey = 'TeamStats_' . $currentGroupId;
        if (!isset($this->$cacheKey) || count($this->$cacheKey) == 0) {
            $this->$cacheKey = StadiumControlApi::GetTeamsStats($currentGroupId);
        }
        return $this->$cacheKey;
    }
    public function GetPlayerStatsArr()
    {
        $currentGroupId = $this->GetCurrentGameGroupId();
        $cacheKey = 'PlayerStats_' . $currentGroupId;
        if (!isset($this->$cacheKey) || count($this->$cacheKey) == 0) {
            $this->$cacheKey = StadiumControlApi::GetPlayerStats($currentGroupId);
        }
        return $this->$cacheKey;
    }
    public function GetTeamsArr()
    {
        if (count($this->Teams) == 0)
            $this->Teams = StadiumControlApi::GetTeams();
        return $this->Teams;
    }
    /**
     * @return StadiumControl_Team|null
     */
    public function GetTeamById($teamId)
    {
        foreach ($this->GetTeamsArr() as $team)
        {
            /** @var StadiumControl_Team $team */
            if (intval($team->id) == intval($teamId))
                return $team;
        }
        return null;
    }
    /**
     * @return PlayerStats[]
     */
    public function GetTeamPlayerStatsArr($teamId)
    {
        $teamPlayers = array();
        $playerIdsInStats = array();
        foreach ($this->GetPlayerStatsArr() as $playerStats)
        {
            /** @var PlayerStats $playerStats */
            if (intval($playerStats->teamid) == intval($teamId))
            {
                $teamPlayers[] = $playerStats;
                $playerIdsInStats[] = intval($playerStats->playerid);
            }
        }

        // Also include roster players who have no stats yet (zero games played).
        $roster = StadiumControlApi::GetTeamRoster(intval($teamId));
        foreach ($roster as $rosterRow)
        {
            $playerId = intval($this->ReadApiField($rosterRow, "player", 0));
            if ($playerId <= 0 || in_array($playerId, $playerIdsInStats))
                continue;
            $player = StadiumControlApi::GetPlayer($playerId);
            $ps = new PlayerStats();
            $ps->playerid = $playerId;
            $ps->teamid = intval($teamId);
            $ps->playerName = $player->name;
            $ps->playerNumber = strval($this->ReadApiField($rosterRow, "number", ""));
            $ps->playerImagePath = $player->imagePath;
            $teamPlayers[] = $ps;
        }

        // Keep the same ordering strategy as global stats: points first, then goals.
        usort($teamPlayers, function($a, $b) {
            return $b->totalPoints <=> $a->totalPoints ?: $b->goals <=> $a->goals;
        });
        return $teamPlayers;
    }
    /**
     * @return StadiumControl_Game[]
     */
    public function GetTeamGamesArr($teamId, $statuses = array())
    {
        $games = array();
        foreach ($this->GetStadiumControlGamesArr() as $game)
        {
            /** @var StadiumControl_Game $game */
            $isTeamGame = intval($game->team1) == intval($teamId) || intval($game->team2) == intval($teamId);
            if (!$isTeamGame)
                continue;
            if (count($statuses) > 0 && !in_array(intval($game->afterGameStatus), $statuses))
                continue;
            $games[] = $game;
        }

        usort($games, function($a, $b) {
            $timeA = is_numeric($a->startTime ?? null) ? intval($a->startTime) : strtotime(strval($a->startTime ?? 0));
            $timeB = is_numeric($b->startTime ?? null) ? intval($b->startTime) : strtotime(strval($b->startTime ?? 0));
            if ($timeA === false) $timeA = 0;
            if ($timeB === false) $timeB = 0;
            return $timeA <=> $timeB;
        });

        return $games;
    }
    private function IsPlayedGame($game)
    {
        /** @var StadiumControl_Game $game */
        $status = intval($game->afterGameStatus);
        return in_array($status, array(2, 3, 4, 5, 6, 7));
    }
    private function AddMiniTableResult(&$miniStatsByTeamId, $team1Id, $team2Id, $status, $team1Goals, $team2Goals)
    {
        // Track mutual score in mini-table for tie-breaks inside tied group.
        $miniStatsByTeamId[$team1Id]["goalsFor"] += intval($team1Goals);
        $miniStatsByTeamId[$team1Id]["goalsAgainst"] += intval($team2Goals);
        $miniStatsByTeamId[$team2Id]["goalsFor"] += intval($team2Goals);
        $miniStatsByTeamId[$team2Id]["goalsAgainst"] += intval($team1Goals);

        // Same point model as season standings: regular win 3, pp win 2, pp loss 1, regular loss 0.
        if ($status === 2)
        {
            $miniStatsByTeamId[$team1Id]["points"] += 3;
            return;
        }
        if ($status === 3)
        {
            $miniStatsByTeamId[$team2Id]["points"] += 3;
            return;
        }
        if ($status === 4)
        {
            $miniStatsByTeamId[$team1Id]["points"] += 2;
            $miniStatsByTeamId[$team2Id]["points"] += 1;
            return;
        }
        if ($status === 5)
        {
            $miniStatsByTeamId[$team2Id]["points"] += 2;
            $miniStatsByTeamId[$team1Id]["points"] += 1;
            return;
        }

        // Fallback for other finished statuses.
        if ($team1Goals > $team2Goals)
            $miniStatsByTeamId[$team1Id]["points"] += 3;
        else if ($team2Goals > $team1Goals)
            $miniStatsByTeamId[$team2Id]["points"] += 3;
        else
        {
            $miniStatsByTeamId[$team1Id]["points"] += 1;
            $miniStatsByTeamId[$team2Id]["points"] += 1;
        }
    }
    private function BuildMiniTableStatsForGroup($rows)
    {
        $teamIds = array();
        $miniStatsByTeamId = array();

        foreach($rows as $row)
        {
            $teamId = intval($row->id);
            $teamIds[$teamId] = true;
            $miniStatsByTeamId[$teamId] = array(
                "points" => 0,
                "goalsFor" => 0,
                "goalsAgainst" => 0
            );
        }

        foreach($this->GetStadiumControlGamesArr() as $game)
        {
            /** @var StadiumControl_Game $game */
            if (!$this->IsPlayedGame($game))
                continue;

            $team1Id = intval($game->team1);
            $team2Id = intval($game->team2);
            if (!isset($teamIds[$team1Id]) || !isset($teamIds[$team2Id]))
                continue;

            $this->AddMiniTableResult(
                $miniStatsByTeamId,
                $team1Id,
                $team2Id,
                intval($game->afterGameStatus),
                intval($game->team1goalsCount),
                intval($game->team2goalsCount)
            );
        }

        return $miniStatsByTeamId;
    }
    public function SortTeamStatsRows($rows)
    {
        usort($rows, function($a, $b) {
            return intval($b->points) <=> intval($a->points);
        });

        $result = array();
        $index = 0;
        while ($index < count($rows))
        {
            $group = array($rows[$index]);
            $groupPoints = intval($rows[$index]->points);
            $index++;

            while ($index < count($rows) && intval($rows[$index]->points) === $groupPoints)
            {
                $group[] = $rows[$index];
                $index++;
            }

            if (count($group) > 1)
            {
                $miniStatsByTeamId = $this->BuildMiniTableStatsForGroup($group);
                usort($group, function($a, $b) use ($miniStatsByTeamId) {
                    $aMiniPoints = isset($miniStatsByTeamId[intval($a->id)]) ? intval($miniStatsByTeamId[intval($a->id)]["points"]) : 0;
                    $bMiniPoints = isset($miniStatsByTeamId[intval($b->id)]) ? intval($miniStatsByTeamId[intval($b->id)]["points"]) : 0;

                    if ($bMiniPoints !== $aMiniPoints)
                        return $bMiniPoints <=> $aMiniPoints;

                    $aMiniGoalDiff = isset($miniStatsByTeamId[intval($a->id)])
                        ? intval($miniStatsByTeamId[intval($a->id)]["goalsFor"]) - intval($miniStatsByTeamId[intval($a->id)]["goalsAgainst"])
                        : 0;
                    $bMiniGoalDiff = isset($miniStatsByTeamId[intval($b->id)])
                        ? intval($miniStatsByTeamId[intval($b->id)]["goalsFor"]) - intval($miniStatsByTeamId[intval($b->id)]["goalsAgainst"])
                        : 0;

                    if ($bMiniGoalDiff !== $aMiniGoalDiff)
                        return $bMiniGoalDiff <=> $aMiniGoalDiff;

                    return 0;
                });
            }

            foreach($group as $groupRow)
                $result[] = $groupRow;
        }

        return $result;
    }
    public function GetPlayerStatsSortOptions($includeTeamName = true)
    {
        $options = array(
            "playerName" => "Hrac",
            "playerNumber" => "Cislo",
            "games" => "Z",
            "goals" => "G",
            "assistances" => "A",
            "totalPoints" => "B",
            "pointsPerGame" => "BNZ",
            "penaltySeconds" => "TM"
        );
        if ($includeTeamName)
            $options["teamName"] = "Tim";
        return $options;
    }
    public function GetSortFieldFromRequest($fieldParam, $defaultField, $includeTeamName = true)
    {
        $allowedFields = array_keys($this->GetPlayerStatsSortOptions($includeTeamName));
        $field = isset($_GET[$fieldParam]) ? $_GET[$fieldParam] : $defaultField;
        if (!in_array($field, $allowedFields))
            return $defaultField;
        return $field;
    }
    public function GetSortDirectionFromRequest($directionParam, $defaultDirection = "desc")
    {
        $direction = isset($_GET[$directionParam]) ? strtolower($_GET[$directionParam]) : $defaultDirection;
        if ($direction !== "asc" && $direction !== "desc")
            return $defaultDirection;
        return $direction;
    }
    public function SortPlayerStatsArray($playerStats, $sortField, $sortDirection)
    {
        $isNumericField = in_array($sortField, array(
            "playerNumber", "games", "goals", "assistances", "totalPoints", "pointsPerGame", "penaltySeconds"
        ));

        usort($playerStats, function($a, $b) use ($sortField, $sortDirection, $isNumericField) {
            $valueA = isset($a->$sortField) ? $a->$sortField : null;
            $valueB = isset($b->$sortField) ? $b->$sortField : null;

            if ($isNumericField)
            {
                $cmp = floatval($valueA) <=> floatval($valueB);
            }
            else
            {
                $cmp = strcasecmp(strval($valueA), strval($valueB));
            }

            if ($cmp === 0)
            {
                // Tie-breaker keeps deterministic ranking when selected column matches.
                $cmp = floatval($a->totalPoints) <=> floatval($b->totalPoints);
                if ($cmp === 0)
                    $cmp = floatval($a->goals) <=> floatval($b->goals);
            }

            return ($sortDirection === "asc") ? $cmp : -$cmp;
        });
        return $playerStats;
    }
    public function RenderPlayerStatsSortControls($fieldParam, $directionParam, $selectedField, $selectedDirection, $includeTeamName = true)
    {
        $options = $this->GetPlayerStatsSortOptions($includeTeamName);

        $html = '<div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">';
        $html .= '<span><b>Zoradit podla:</b></span>';
        $html .= '<form method="get" style="display: inline-flex; gap: 8px; align-items: center;">';
        $html .= '<select name="'.$fieldParam.'">';
        foreach($options as $value => $label)
        {
            $selected = ($selectedField === $value ? ' selected' : '');
            $html .= '<option value="'.$value.'"'.$selected.'>'.$label.'</option>';
        }
        $html .= '</select>';

        $html .= '<select name="'.$directionParam.'">';
        $html .= '<option value="desc"'.($selectedDirection === "desc" ? ' selected' : '').'>Zostupne</option>';
        $html .= '<option value="asc"'.($selectedDirection === "asc" ? ' selected' : '').'>Vzostupne</option>';
        $html .= '</select>';

        $html .= '<button type="submit">Zoradit</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }
    public function BuildSortLink($label, $targetField, $fieldParam, $directionParam, $currentField, $currentDirection)
    {
        $nextDirection = "desc";
        $suffix = "";

        if ($currentField === $targetField)
        {
            if ($currentDirection === "desc")
            {
                $nextDirection = "asc";
                $suffix = " ↓";
            }
            else
            {
                $nextDirection = "desc";
                $suffix = " ↑";
            }
        }

        $query = $_GET;
        $query[$fieldParam] = $targetField;
        $query[$directionParam] = $nextDirection;

        $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $url = $this->BuildSeasonUrl($uriPath, $query);

        return '<a href="'.$url.'">'.$label.$suffix.'</a>';
    }
    private function ReadApiField($row, $field, $default = null)
    {
        if (is_array($row) && array_key_exists($field, $row))
            return $row[$field];
        if (is_object($row) && isset($row->$field))
            return $row->$field;
        return $default;
    }
    public function GetPlayerSeasonStats($playerId)
    {
        $summary = array(
            "games" => 0,
            "goals" => 0,
            "assistances" => 0,
            "points" => 0,
            "penaltyMinutes" => 0,
            "pointsPerGame" => 0,
            "teams" => array()
        );

        foreach($this->GetPlayerStatsArr() as $ps)
        {
            /** @var PlayerStats $ps */
            if (intval($ps->playerid) !== intval($playerId))
                continue;

            $summary["games"] += intval($ps->games);
            $summary["goals"] += intval($ps->goals);
            $summary["assistances"] += intval($ps->assistances);
            $summary["points"] += intval($ps->totalPoints);
            $summary["penaltyMinutes"] += intval(intval($ps->penaltySeconds) / 60);

            if (!in_array($ps->teamName, $summary["teams"]))
                $summary["teams"][] = $ps->teamName;
        }

        if ($summary["games"] > 0)
            $summary["pointsPerGame"] = round($summary["points"] / $summary["games"], 2);

        return $summary;
    }
    public function GetPlayerGamesStats($playerId)
    {
        $rows = array();

        foreach($this->GetStadiumControlGamesArr() as $game)
        {
            /** @var StadiumControl_Game $game */
            $playedTeamId = 0;
            $playedTeamName = "";
            $opponentName = "";

            $teamsToCheck = array(
                array(intval($game->team1), $game->team1name, $game->team2name),
                array(intval($game->team2), $game->team2name, $game->team1name)
            );

            foreach($teamsToCheck as $teamToCheck)
            {
                $teamId = $teamToCheck[0];
                $roster = StadiumControlApi::GetGameTeamRoster(intval($game->id), $teamId);
                foreach($roster as $rosterRow)
                {
                    $rosterPlayerId = intval($this->ReadApiField($rosterRow, "player", 0));
                    if ($rosterPlayerId === intval($playerId))
                    {
                        $playedTeamId = $teamId;
                        $playedTeamName = $teamToCheck[1];
                        $opponentName = $teamToCheck[2];
                        break;
                    }
                }
                if ($playedTeamId > 0)
                    break;
            }

            if ($playedTeamId <= 0)
                continue;

            $goalsCount = 0;
            $assistCount = 0;
            $tmMinutes = 0;

            $goals = StadiumControlApi::GetGameTeamGoals(intval($game->id), intval($playedTeamId));
            foreach($goals as $goal)
            {
                if (intval($this->ReadApiField($goal, "player_goal", 0)) === intval($playerId))
                    $goalsCount++;
                if (intval($this->ReadApiField($goal, "player_assist1", 0)) === intval($playerId))
                    $assistCount++;
                if (intval($this->ReadApiField($goal, "player_assist2", 0)) === intval($playerId))
                    $assistCount++;
            }

            $penalties = StadiumControlApi::GetGameTeamPenalties(intval($game->id), intval($playedTeamId));
            foreach($penalties as $penalty)
            {
                if (intval($this->ReadApiField($penalty, "player", 0)) === intval($playerId))
                    $tmMinutes += intval(intval($this->ReadApiField($penalty, "duration", 0)) / 60);
            }

            $rows[] = array(
                "game" => $game,
                "teamName" => $playedTeamName,
                "opponentName" => $opponentName,
                "goals" => $goalsCount,
                "assistances" => $assistCount,
                "points" => $goalsCount + $assistCount,
                "tm" => $tmMinutes
            );
        }

        usort($rows, function($a, $b) {
            /** @var StadiumControl_Game $ag */
            $ag = $a["game"];
            /** @var StadiumControl_Game $bg */
            $bg = $b["game"];
            return intval($bg->startTime) <=> intval($ag->startTime);
        });

        return $rows;
    }
    public function GetTeamRosterForPrint($teamId)
    {
        $rows = array();
        $roster = StadiumControlApi::GetTeamRoster(intval($teamId));

        foreach($roster as $rosterRow)
        {
            $playerId = intval($this->ReadApiField($rosterRow, "player", 0));
            if ($playerId <= 0)
                continue;

            $player = StadiumControlApi::GetPlayer($playerId);
            /** @var StadiumControl_Player $player */
            $rows[] = array(
                "number" => strval($this->ReadApiField($rosterRow, "number", "")),
                "playerName" => $player->name
            );
        }

        usort($rows, function($a, $b) {
            return strcasecmp($a["playerName"], $b["playerName"]);
        });

        return $rows;
    }
    public function CreateTeamRosterPrintPage($team)
    {
        $rosterRows = $this->GetTeamRosterForPrint($team->id);

        $content = '
        <style>
            .print-roster-wrap { max-width: 900px; margin: 0 auto; background: #fff; }
            .print-league-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; border-bottom: 2px solid #000; padding-bottom: 10px; }
            .print-league-head img { width: 54px; height: 54px; object-fit: contain; }
            .print-league-title { font-size: 28px; font-weight: 700; line-height: 1.2; }
            .print-roster-head { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
            .print-roster-head img { width: 64px; height: 64px; object-fit: contain; }
            .print-roster-meta { margin: 10px 0 18px 0; font-size: 24px; line-height: 1.6; }
            .print-roster-meta-row { display: flex; align-items: center; margin: 6px 0; }
            .print-roster-meta-label { min-width: 120px; font-weight: bold; }
            .print-roster-meta-line { flex: 1; border-bottom: 2px solid #000; height: 28px; }
            .print-roster-table { width: 100%; border-collapse: collapse; }
            .print-roster-table th, .print-roster-table td { border: 1px solid #000; padding: 8px; }
            .print-roster-table td { height: 34px; }
            .print-roster-check { width: 120px; text-align: center; font-size: 18px; }
            .print-roster-actions { margin: 14px 0; }
            @media print {
                .print-roster-actions { display: none; }
                .section { background: #fff !important; }
                header, footer, .page-header, .rd-navbar-wrap, .rd-navbar, .rd-navbar-main, .rd-navbar-panel, .rd-navbar-main-top, .rd-navbar-main-bottom, .rd-navbar-nav, .rd-navbar-list, .rd-navbar-search { display: none !important; }
                body * { visibility: hidden; }
                .print-roster-wrap, .print-roster-wrap * { visibility: visible; }
                .print-roster-wrap { position: absolute; left: 0; top: 0; width: 100%; max-width: none; padding: 8mm; margin: 0; }
            }
        </style>

        <div class="print-roster-wrap">
            <div class="print-roster-actions">
                <button onclick="window.print()">Tlačiť súpisku</button>
            </div>
            <div class="print-league-head">
                <img src="/'.$this->AssetsPath.'/images/logo/logo.png" alt="Bánovecká hokejbalová liga" />
                <div class="print-league-title">Bánovecká hokejbalová liga</div>
            </div>
            <div class="print-roster-head">
                <img src="'.$team->imagePath.'" alt="'.$team->name.'" />
                <h3>Súpiska tímu '.$team->name.'</h3>
            </div>
            <div class="print-roster-meta">
                <div class="print-roster-meta-row"><span class="print-roster-meta-label">Dátum:</span><span class="print-roster-meta-line"></span></div>
                <div class="print-roster-meta-row"><span class="print-roster-meta-label">Sezóna + kolo:</span><span class="print-roster-meta-line"></span></div>
            </div>

            <table class="print-roster-table">
                <thead>
                    <tr>
                        <th style="width:80px;">#</th>
                        <th>Hráč</th>
                        <th class="print-roster-check">Prítomný</th>
                    </tr>
                </thead>
                <tbody>';

        if (count($rosterRows) == 0)
        {
            $content .= '<tr><td colspan="3">Súpiska je prázdna.</td></tr>';
        }
        else
        {
            foreach($rosterRows as $row)
            {
                $content .= '<tr>
                    <td>'.$row["number"].'</td>
                    <td>'.$row["playerName"].'</td>
                    <td class="print-roster-check">[&nbsp;&nbsp;]</td>
                </tr>';
            }
        }

        $content .= '
                </tbody>
            </table>
        </div>';

        return $content;
    }
    public function CreatePlayerPage($playerId)
    {
        $player = StadiumControlApi::GetPlayer(intval($playerId));
        /** @var StadiumControl_Player $player */
        if (intval($player->id) <= 0)
            return "";

        $summary = $this->GetPlayerSeasonStats($playerId);
        $games = $this->GetPlayerGamesStats($playerId);

        $content = '
        <div class="row row-30">
            <div class="col-sm-12">
                <article class="heading-component">
                    <div class="heading-component-inner">
                        <h5 class="heading-component-title">'.$player->name.'</h5>
                    </div>
                </article>
                <div class="table-custom-responsive">
                    <table class="table-custom" style="text-align: left;">
                        <thead>
                            <tr>
                                <th>Z</th>
                                <th>G</th>
                                <th>A</th>
                                <th>B</th>
                                <th>TM</th>
                                <th>BNZ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>'.$summary["games"].'</td>
                                <td>'.$summary["goals"].'</td>
                                <td>'.$summary["assistances"].'</td>
                                <td>'.$summary["points"].'</td>
                                <td>'.$summary["penaltyMinutes"].'</td>
                                <td>'.$summary["pointsPerGame"].'</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>';

        $content .= '
        <div class="row row-30" style="margin-top: 24px;">
            <div class="col-sm-12">
                <article class="heading-component">
                    <div class="heading-component-inner">
                        <h5 class="heading-component-title">Zápasy, v ktorých hráč nastúpil</h5>
                    </div>
                </article>
                <div class="table-custom-responsive">
                    <table class="table-custom" style="text-align: left;">
                        <thead>
                            <tr>
                                <th>Dátum</th>
                                <th>Zápas</th>
                                <th>Tím</th>
                                <th>Stav</th>
                                <th>G</th>
                                <th>A</th>
                                <th>B</th>
                                <th>TM</th>
                            </tr>
                        </thead>
                        <tbody>';

        if (count($games) == 0)
        {
            $content .= '<tr><td colspan="8">Žiadny záznam</td></tr>';
        }
        else
        {
            foreach($games as $row)
            {
                /** @var StadiumControl_Game $game */
                $game = $row["game"];
                $dateText = date('d.m.Y H:i', intval($game->startTime));
                $score = intval($game->team1goalsCount).' : '.intval($game->team2goalsCount);
                $matchup = $game->team1name.' vs '.$game->team2name;

                $content .= '<tr>
                    <td>'.$dateText.'</td>
                    <td><a href="'.$this->BuildSeasonUrl('/zapas/'.$game->id).'">'.$game->name.'</a><br><small>'.$matchup.'</small></td>
                    <td>'.$row["teamName"].'</td>
                    <td>'.$score.'</td>
                    <td>'.$row["goals"].'</td>
                    <td>'.$row["assistances"].'</td>
                    <td><b>'.$row["points"].'</b></td>
                    <td>'.$row["tm"].'</td>
                </tr>';
            }
        }

        $content .= '
                        </tbody>
                    </table>
                </div>
            </div>
        </div>';

        return $content;
    }
  
	public function CreateFooter()
	{
		$footer = new Module_Footer();
		$footer->AssetsPath = $this->AssetsPath;
		$footer->CenterText = "";
		$footer->Phone = "";
		$footer->Email = 'bnhlofficial25@gmail.com';
		$footer->ExtraContent = $this->CreateFooterSeasonSwitcher();
		return $footer->GetHTML();
	}

    private function CreateFooterSeasonSwitcher()
    {
        $seasonIds = $this->GetAvailableSeasonIds();
        if (count($seasonIds) === 0)
            return "";

        $currentSeasonId = $this->GetCurrentSeasonId();
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $content = '<div style="display:flex; align-items:center; flex-wrap:wrap; gap:8px; justify-content:flex-end;">';
        $content .= '<span style="font-weight:600; color:#ffffff; font-size:13px;">Sezóna:</span>';

        foreach ($seasonIds as $seasonId)
        {
            $seasonId = strval($seasonId);
            $query = $_GET;
            $query['season'] = $seasonId;
            unset($query['ggs']);
            $url = $path . '?' . http_build_query($query);
            $isActive = ($seasonId === strval($currentSeasonId));

            $content .= '<a href="' . $url . '" style="padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; line-height:1.2; border:1px solid ' . ($isActive ? '#35ad79' : '#a7adb4') . '; background:' . ($isActive ? '#35ad79' : 'transparent') . '; color:' . ($isActive ? '#ffffff' : '#dbe1e8') . ';">' . $this->GetSeasonLabel($seasonId) . '</a>';
        }

        $content .= '</div>';
        return $content;
    }

    public function CreateSchedule($limit = 0, $title = "Rozpis zápasov", $inRow = false)
    {
        $schedule = new Module_Matches();
        $schedule->AssetsPath = $this->AssetsPath;
        $schedule->Limit = $limit;
        $schedule->SortDir = 0; // chronological: earliest to latest
        $schedule->Games = $this->GetStadiumControlGamesArr();
        $schedule->Title = $title;
        $schedule->InRow = $inRow;
        $schedule->View = 1; // small view
        $schedule->AfterGameStatuses = array(0); // pregame
        return $schedule->GetHTML();
    }
    public function CreateResults($limit=0, $sortDir=0, $title = "Výsledky zápasov", $view=1, $afterStatuses = array(2,3,4,5,6,7), $inRow = false, $showOnlyIfThereAreGames = false)
    {
        $schedule = new Module_Matches();
        $schedule->AssetsPath = $this->AssetsPath;
        $schedule->Limit = $limit;
        $schedule->SortDir = $sortDir;
        $schedule->InRow = $inRow;
        $schedule->Games = $this->GetStadiumControlGamesArr();
        $schedule->Title = $title;
        $schedule->ShowOnlyIfThereAreGames = $showOnlyIfThereAreGames;
        $schedule->View = $view; // small view
        $schedule->AfterGameStatuses = $afterStatuses;
        return $schedule->GetHTML();
    }

    public function CreateMatchDetails($id)
    {
        $match = new Module_Match();
        $match->Game = StadiumControlApi::GetGameDetails($id);
        if (empty($match->Game) || (is_object($match->Game) && (string)($match->Game->startTime ?? '') === ''))
            return $this->BuildPage_404();   // unknown match id
        $match->AssetsPath = $this->AssetsPath;
        $match->Title = "Detaily zápasu";
        return $match->GetHTML_details();
    }
    public function CreateStats()
    {
        $sortField = $this->GetSortFieldFromRequest("statssort", "totalPoints", true);
        $sortDirection = $this->GetSortDirectionFromRequest("statsdir", "desc");
        $currentGameGroupId = $this->GetCurrentGameGroupId();
        $seasonId = $this->GetCurrentSeasonId();

        $html = '<div style="margin-bottom: 18px;">';
        $html .= '<div style="font-size: 18px; font-weight: 700; margin-bottom: 10px;">' . $this->GetCurrentGameGroupLabel() . '</div>';

        $groups = $this->GetGroupsForSeason($seasonId);
        if (count($groups) > 0) {
            $html .= '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px;">';
            foreach ($groups as $group) {
                /** @var GameGroupStats $group */
                $groupLabel = method_exists($group, 'GetDisplayName') ? $group->GetDisplayName() : (trim($group->displayName) !== '' ? $group->displayName : $group->name);
                $isActive = intval($group->id) === intval($currentGameGroupId);
                $query = $_GET;
                $query['ggs'] = intval($group->id);
                $query['season'] = $seasonId;
                $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $url = $path . '?' . http_build_query($query);
                $html .= '<a href="' . $url . '" style="padding: 8px 14px; background: ' . ($isActive ? '#35ad79' : '#ddd') . '; color: ' . ($isActive ? 'white' : '#333') . '; text-decoration: none; border-radius: 4px; display: inline-block;">' . $groupLabel . '</a>';
            }
            $html .= '</div>';
        }

        // Štatistiky
        $stats = new Module_Stats();
        $stats->PlayerStats = $this->SortPlayerStatsArray($this->GetPlayerStatsArr(), $sortField, $sortDirection);
        $stats->AssetsPath = $this->AssetsPath;
        $stats->Title = "Štatistiky hráčov - " . $this->GetCurrentStatGroupLabel() . " / " . $this->GetCurrentGameGroupLabel();
        $stats->PlayerLinkBasePath = "/hrac/";
        $stats->HeaderSortLinks = array(
            "playerName" => $this->BuildSortLink("Hrac", "playerName", "statssort", "statsdir", $sortField, $sortDirection),
            "games" => $this->BuildSortLink("Z", "games", "statssort", "statsdir", $sortField, $sortDirection),
            "goals" => $this->BuildSortLink("G", "goals", "statssort", "statsdir", $sortField, $sortDirection),
            "assistances" => $this->BuildSortLink("A", "assistances", "statssort", "statsdir", $sortField, $sortDirection),
            "totalPoints" => $this->BuildSortLink("B", "totalPoints", "statssort", "statsdir", $sortField, $sortDirection),
            "pointsPerGame" => $this->BuildSortLink("BNZ", "pointsPerGame", "statssort", "statsdir", $sortField, $sortDirection),
            "penaltySeconds" => $this->BuildSortLink("TM", "penaltySeconds", "statssort", "statsdir", $sortField, $sortDirection)
        );
        
        return $html . $stats->GetHTML();
    }
        public function CreateTeamRosterStats($team)
        {
                $sortField = $this->GetSortFieldFromRequest("teamsort", "totalPoints", false);
                $sortDirection = $this->GetSortDirectionFromRequest("teamdir", "desc");
                $playerStats = $this->SortPlayerStatsArray($this->GetTeamPlayerStatsArr($team->id), $sortField, $sortDirection);

                $thPlayer = $this->BuildSortLink("Hrac", "playerName", "teamsort", "teamdir", $sortField, $sortDirection);
                $thGames = $this->BuildSortLink("Z", "games", "teamsort", "teamdir", $sortField, $sortDirection);
                $thGoals = $this->BuildSortLink("G", "goals", "teamsort", "teamdir", $sortField, $sortDirection);
                $thAssistances = $this->BuildSortLink("A", "assistances", "teamsort", "teamdir", $sortField, $sortDirection);
                $thPoints = $this->BuildSortLink("B", "totalPoints", "teamsort", "teamdir", $sortField, $sortDirection);
                $thPpg = $this->BuildSortLink("BNZ", "pointsPerGame", "teamsort", "teamdir", $sortField, $sortDirection);
                $thTm = $this->BuildSortLink("TM", "penaltySeconds", "teamsort", "teamdir", $sortField, $sortDirection);

                $content = '
                <div class="row row-30">
                    <div class="col-sm-12">
                        <article class="heading-component">'.$this->GameGroupSelector().'
                            <div class="heading-component-inner">
                                <h5 class="heading-component-title">Súpíska a body</h5>
                            </div>
                        </article>
                        <div class="table-custom-responsive">
                            <table class="table-custom" style="text-align: left;">
                                <thead>
                                    <tr style="font-size: 17px;">
                                        <th></th>
                                        <th>'.$thPlayer.'</th>
                                        <th>'.$thGames.'</th>
                                        <th>'.$thGoals.'</th>
                                        <th>'.$thAssistances.'</th>
                                        <th>'.$thPoints.'</th>
                                        <th>'.$thPpg.'</th>
                                        <th>'.$thTm.'</th>
                                    </tr>
                                </thead>
                                <tbody>';

                $counter = 0;
                foreach($playerStats as $player)
                {
                    /** @var PlayerStats $player */
                        $counter++;
                        $content .= '
                                <tr style="height: 40px;">
                                    <td style="text-align: center;">'.$counter.'</td>
                                    <td><a href="'.$this->BuildSeasonUrl('/hrac/'.$player->playerid).'">'.$player->playerName.'</a> <small>#'.$player->playerNumber.'</small></td>
                                    <td>'.$player->games.'</td>
                                    <td>'.$player->goals.'</td>
                                    <td>'.$player->assistances.'</td>
                                    <td>'.$player->totalPoints.'</td>
                                    <td>'.$player->pointsPerGame.'</td>
                                    <td>'.intval($player->penaltySeconds / 60).'</td>
                                </tr>';
                }

                if ($counter == 0)
                {
                        $content .= '
                                <tr>
                                    <td colspan="8">V súpiske zatiaľ nie sú žiadni hráči.</td>
                                </tr>';
                }

                $content .= '
                                </tbody>
                            </table>
                            <div style="margin: 10px 0 0 0;">
                                <a href="'.$this->BuildSeasonUrl('/timtlac/'.$team->id).'" target="_blank">Tlač súpisky tímu</a>
                            </div>
                        </div>
                    </div>
                </div>';

                return $content;
        }
        public function CreateTeamGamesSection($teamId, $statuses, $title)
        {
                $matches = new Module_Matches();
                $matches->AssetsPath = $this->AssetsPath;
                $matches->Title = $title;
                $matches->Games = $this->GetTeamGamesArr($teamId, $statuses);
                $matches->AfterGameStatuses = $statuses;
                $matches->View = 1;
                $matches->InRow = true;
                return $matches->GetHTML();
        }
            private function ComputeTeamGameResultForTable($game, $teamId)
            {
                /** @var StadiumControl_Game $game */
                $isHome = intval($game->team1) === intval($teamId);
                $teamGoals = $isHome ? intval($game->team1goalsCount) : intval($game->team2goalsCount);
                $opponentGoals = $isHome ? intval($game->team2goalsCount) : intval($game->team1goalsCount);
                $opponentName = $isHome ? $game->team2name : $game->team1name;
                $status = intval($game->afterGameStatus);

                $resultLabel = "P";
                $points = 0;

                if ($status === 2)
                {
                    $isWin = $isHome;
                    $resultLabel = $isWin ? "V" : "P";
                    $points = $isWin ? 3 : 0;
                }
                else if ($status === 3)
                {
                    $isWin = !$isHome;
                    $resultLabel = $isWin ? "V" : "P";
                    $points = $isWin ? 3 : 0;
                }
                else if ($status === 4)
                {
                    $isWin = $isHome;
                    $resultLabel = $isWin ? "Vpp" : "Ppp";
                    $points = $isWin ? 2 : 1;
                }
                else if ($status === 5)
                {
                    $isWin = !$isHome;
                    $resultLabel = $isWin ? "Vpp" : "Ppp";
                    $points = $isWin ? 2 : 1;
                }
                else
                {
                    if ($teamGoals > $opponentGoals)
                    {
                        $resultLabel = "V";
                        $points = 3;
                    }
                    else if ($teamGoals < $opponentGoals)
                    {
                        $resultLabel = "P";
                        $points = 0;
                    }
                    else
                    {
                        $resultLabel = "R";
                        $points = 1;
                    }
                }

                return array(
                    "isHome" => $isHome,
                    "opponentName" => $opponentName,
                    "teamGoals" => $teamGoals,
                    "opponentGoals" => $opponentGoals,
                    "resultLabel" => $resultLabel,
                    "points" => $points
                );
            }
            private function UpdateHeadToHeadCell(&$cell, $result)
            {
                $cell["games"]++;
                $cell["points"] += intval($result["points"]);
                $cell["goalsFor"] += intval($result["teamGoals"]);
                $cell["goalsAgainst"] += intval($result["opponentGoals"]);

                if ($result["resultLabel"] === "V")
                    $cell["wins"]++;
                else if ($result["resultLabel"] === "Vpp")
                    $cell["winsPP"]++;
                else if ($result["resultLabel"] === "Ppp")
                    $cell["lossesPP"]++;
                else
                    $cell["losses"]++;
            }
            public function CreateTeamVsOpponentsTable($team = null)
            {
                $teamRows = $this->SortTeamStatsRows($this->GetTeamStatsArr());
                $selectedTeamId = 0;
                if ($team != null && isset($team->id))
                    $selectedTeamId = intval($team->id);

                if (count($teamRows) === 0)
                {
                    return '
                    <div class="row row-30">
                        <div class="col-sm-12">
                            <article class="heading-component">
                                <div class="heading-component-inner">
                                    <h5 class="heading-component-title">Vzájomné zápasy tímov (matica)</h5>
                                </div>
                            </article>
                            <div class="table-custom-responsive">
                                <table class="table-custom" style="text-align: left;">
                                    <tbody><tr><td>Nie sú dostupné dáta o tímoch.</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>';
                }

                $teamIds = array();
                foreach($teamRows as $row)
                    $teamIds[intval($row->id)] = true;

                $matrix = array();
                foreach($teamRows as $rowA)
                {
                    $idA = intval($rowA->id);
                    $matrix[$idA] = array();
                    foreach($teamRows as $rowB)
                    {
                        $idB = intval($rowB->id);
                        if ($idA === $idB)
                            continue;
                        $matrix[$idA][$idB] = array(
                            "games" => 0,
                            "points" => 0,
                            "goalsFor" => 0,
                            "goalsAgainst" => 0,
                            "wins" => 0,
                            "winsPP" => 0,
                            "lossesPP" => 0,
                            "losses" => 0
                        );
                    }
                }

                foreach($this->GetStadiumControlGamesArr() as $game)
                {
                    /** @var StadiumControl_Game $game */
                    if (!$this->IsPlayedGame($game))
                        continue;

                    $team1Id = intval($game->team1);
                    $team2Id = intval($game->team2);
                    if (!isset($teamIds[$team1Id]) || !isset($teamIds[$team2Id]))
                        continue;

                    $result1 = $this->ComputeTeamGameResultForTable($game, $team1Id);
                    $result2 = $this->ComputeTeamGameResultForTable($game, $team2Id);

                    $this->UpdateHeadToHeadCell($matrix[$team1Id][$team2Id], $result1);
                    $this->UpdateHeadToHeadCell($matrix[$team2Id][$team1Id], $result2);
                }

                $content = '
                <div class="row row-30">
                    <div class="col-sm-12">
                    <article class="heading-component">
                        <div class="heading-component-inner">
                        <h5 class="heading-component-title">Vzájomné zápasy tímov (matica)</h5>
                        </div>
                    </article>
                    <style>
                        .team-matrix-table { table-layout: fixed; border-collapse: separate; border-spacing: 0; }
                        .team-matrix-table th, .team-matrix-table td { vertical-align: middle; border: 1px solid #d8d8d8; }
                        .team-matrix-table thead th { position: sticky; top: 0; z-index: 3; background: #f3f7fb; }
                        .team-matrix-table tbody th { position: sticky; left: 0; z-index: 2; background: #f9fbfd; }
                        .team-matrix-row-team { min-width: 180px; max-width: 220px; font-weight: 700; }
                        .team-matrix-col-team { min-width: 150px; max-width: 180px; white-space: normal; line-height: 1.2; font-size: 13px; }
                        .team-matrix-cell { min-width: 120px; text-align: center; line-height: 1.2; padding: 8px 6px; }
                        .team-matrix-pts { font-size: 22px; font-weight: 800; color: #0f3f73; }
                        .team-matrix-score { font-size: 14px; color: #1f1f1f; margin-top: 4px; }
                        .team-matrix-games { font-size: 13px; color: #2f2f2f; margin-top: 4px; font-weight: 600; }
                        .team-matrix-record { font-size: 12px; color: #3f3f3f; margin-top: 4px; line-height: 1.25; }
                        .team-matrix-empty { color: #8a8a8a; font-size: 18px; font-weight: 600; }
                    </style>
                    <div class="table-custom-responsive">
                        <table class="table-custom team-matrix-table" style="text-align: left;">
                        <thead>
                            <tr>
                            <th>Tím \ súper</th>';

                foreach($teamRows as $colTeam)
                {
                    $isSelected = $selectedTeamId > 0 && intval($colTeam->id) === $selectedTeamId;
                    $cellStyle = $isSelected ? ' style="background:#e8f3ff; border-bottom:2px solid #7fb3e6;"' : '';
                    $content .= '<th class="team-matrix-col-team"'.$cellStyle.'>'.$colTeam->name.'</th>';
                }

                $content .= '
                            </tr>
                        </thead>
                        <tbody>';

                foreach($teamRows as $rowTeam)
                {
                    $rowTeamId = intval($rowTeam->id);
                    $isSelectedRow = $selectedTeamId > 0 && $rowTeamId === $selectedTeamId;
                    $rowHeaderStyle = $isSelectedRow ? ' style="background:#e8f3ff; font-weight:700;"' : '';
                    $content .= '<tr><th class="team-matrix-row-team"'.$rowHeaderStyle.'>'.$rowTeam->name.'</th>';

                    foreach($teamRows as $colTeam)
                    {
                        $colTeamId = intval($colTeam->id);
                        $isSelectedCol = $selectedTeamId > 0 && $colTeamId === $selectedTeamId;
                        $cellStyle = ($isSelectedRow || $isSelectedCol) ? 'background:#f4f9ff;' : '';

                        if ($rowTeamId === $colTeamId)
                        {
                            $content .= '<td class="team-matrix-cell" style="'.$cellStyle.'"><span class="team-matrix-empty">-</span></td>';
                            continue;
                        }

                        $cell = $matrix[$rowTeamId][$colTeamId];
                        if (intval($cell["games"]) === 0)
                        {
                            $content .= '<td class="team-matrix-cell" style="'.$cellStyle.'"><span class="team-matrix-empty">-</span></td>';
                            continue;
                        }

                        $content .= '<td class="team-matrix-cell" style="'.$cellStyle.'">'
                            .'<div class="team-matrix-pts">'.$cell["points"].' b</div>'
                            .'<div class="team-matrix-games">Skóre '.$cell["goalsFor"].':'.$cell["goalsAgainst"].'</div>'
                            .'<div class="team-matrix-record">Z '.$cell["games"].' | V '.$cell["wins"].' | Vpp '.$cell["winsPP"].' | Ppp '.$cell["lossesPP"].' | P '.$cell["losses"].'</div>'
                            .'</td>';
                    }

                    $content .= '</tr>';
                }

                $content .= '
                        </tbody>
                        </table>
                        <div style="margin-top:8px; line-height:1.35;"><small>
                            Legenda: bunka = výsledky tímu v riadku proti tímu v stĺpci (body, skóre, Z/V/Vpp/Ppp/P).
                        </small></div>
                    </div>
                    </div>
                </div>';

                return $content;
            }
        public function CreateTeamPage($team)
        {
                $content = '
                <div class="row row-30">
                    <div class="col-sm-12">
                        <article class="heading-component">
                            <div class="heading-component-inner">
                                <h5 class="heading-component-title" style="display:flex; align-items:center; gap:10px;">
                                    <img src="'.$team->imagePath.'" alt="'.$team->name.'" width="42" height="42" style="object-fit:contain;"/>
                                    <span>'.$team->name.'</span>
                                </h5>
                            </div>
                        </article>
                    </div>
                </div>';

                $content .= $this->CreateTeamRosterStats($team);
                $content .= $this->CreateTeamGamesSection($team->id, array(0), "Naplánované zápasy");
                $content .= $this->CreateTeamGamesSection($team->id, array(2,3,4,5,6,7), "Odohrané zápasy");
                $content .= $this->CreateTeamVsOpponentsTable($team);

                return $content;
        }

    public function GameGroupSelector()
    {
        $htmladd = "";
        $seasonId = $this->GetCurrentSeasonId();
        $groups = $this->GetGroupsForSeason($seasonId);
        if (count($groups) > 0) {
            $htmladd .= '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px;">';
            foreach ($groups as $group) {
                /** @var GameGroupStats $group */
                $groupLabel = method_exists($group, 'GetDisplayName') ? $group->GetDisplayName() : (trim($group->displayName) !== '' ? $group->displayName : $group->name);
                $isActive = intval($group->id) === intval($this->currentGameGroupId);
                $query = $_GET;
                $query['ggs'] = intval($group->id);
                $query['season'] = $seasonId;
                $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $url = $this->BuildSeasonUrl($path, $query);
                $htmladd .= '<a href="' . $url . '" style="padding: 8px 14px; background: ' . ($isActive ? '#35ad79' : '#ddd') . '; color: ' . ($isActive ? 'white' : '#333') . '; text-decoration: none; border-radius: 4px; display: inline-block;">' . $groupLabel . '</a>';
            }
            $htmladd .= '</div>';
        }
        return $htmladd;
    }
    
    public function CreateTeamsTable()
    {
        // Tabuľka
        $table = new Module_TeamsTable();


        $table->Rows = $this->GetTeamStatsArr();
        $table->Rows = $this->SortTeamStatsRows($table->Rows);

        $htmladd = $this->GameGroupSelector();

        return $htmladd . $table->GetHTML();
    }
    public function CreateSmallTeamsTable()
    {
        // Tabuľka
        $table = new Module_TeamsTable();
        $table->Rows = $this->GetTeamStatsArr();
        $table->Rows = $this->SortTeamStatsRows($table->Rows);

            return $table->GetHtml_small();
    }
    
	public function CreateHeader()
	{
		$menu = new Module_Header();
        $menu->AssetsPath = $this->AssetsPath;
		return $menu->GetHTML();
	}
	
	public function BuildPageContent($path)
	{
		$page = $this->App->GetContentFilename($path, $this->defaultPage);
        // print_r($path);
        // echo "page:".$page;
        $id = 0;
        if (count($path) > 0)
        {
            $page = $path[0];
            if (count($path) > 1)
            {
                $id = $path[1];
            }
        }
		switch ($page)
		{
			case "404": 		return $this->BuildPage_404();
			case "": 	return $this->BuildPage_Overview();
			case "prehlad": 	return $this->BuildPage_Overview();
			case "rozpis": 	return $this->BuildPage_Schedule();
			case "tabulka": 	return $this->BuildPage_Standing();
			case "pravidla": 	    return $this->BuildPage_Rules();
            case "statistiky":       return $this->BuildPage_Stats();
            case "clanky":    return $this->BuildPage_Articles();
            case "volnihraci": return $this->BuildPage_FreePlayers();
            case "zapas": return $this->BuildPage_Match($id);
            case "tim": return $this->BuildPage_Team($id);
            case "team": return $this->BuildPage_Team($id);
            case "timtlac": return $this->BuildPage_TeamRosterPrint($id);
            case "timprint": return $this->BuildPage_TeamRosterPrint($id);
            case "teamprint": return $this->BuildPage_TeamRosterPrint($id);
            case "hrac": return $this->BuildPage_Player($id);
            case "player": return $this->BuildPage_Player($id);
            case "rozpistlac": return $this->BuildPage_SchedulePrint($id);
            case "rozpisprint": return $this->BuildPage_SchedulePrint($id);
            case "scheduleprint": return $this->BuildPage_SchedulePrint($id);

			default: 			return $this->BuildPage_404();
		}
	}
	
	public function BuildPage_404()
	{
		return "404";
	}
	public function BuildPage_Overview()
	{
        $hero = new Module_HeroSmall();
        $number = rand(0,16);
        $hero->image = '/images/hero/'.$number.'.jpg';
        $hero->AssetsPath = $this->AssetsPath;

        $hero->title = "Bánovecká hokejbalová liga";

        $body = new Module_Body();
        //$body->content = $this->CreateSmallTeamsTable();
        $body->content = $this->CreateSchedule(6, "Najbližšie zápasy", true);
        
        $body->content .= '<br><br>'.$this->CreateTeamsTable();

        $body->rightContent = $this->CreateResults(2, 1, "Práve prebieha", 1, array(1), false, true);
        $body->rightContent .= $this->CreateResults(4, 1, "Posledné výsledky", 1, array(2,3,4,5,6,7));

        return $hero->GetHTML().$body->GetHTML();
	}
    public function BuildPage_Schedule()
    {
        $body = new Module_Body();
        $body->content = $this->CreateSchedule(0, "Rozpis zápasov", true);
        $body->content .= '<div style="margin-top: 20px; text-align: right;"><a href="/rozpistlac/' . $this->GetCurrentSeasonId() . '" target="_blank" style="font-size: 12px; color: #888; text-decoration: none;">Verzia pre tlač</a></div>';
        return $body->GetHTML();
    }
    public function BuildPage_SchedulePrint($seasonId = 0)
    {
        if ($seasonId !== 0 && $seasonId !== "0" && trim(strval($seasonId)) !== "") {
            $this->currentSeasonId = trim(strval($seasonId));
        } else if (isset($_GET['season']) && trim(strval($_GET['season'])) !== '') {
            $this->currentSeasonId = trim(strval($_GET['season']));
        } else {
            $this->currentSeasonId = strval(self::$DefaultSeasonId);
        }

        $games = $this->GetStadiumControlGamesArr();
        // Sort games by startTime
        usort($games, function($a, $b) {
            return intval($a->startTime) <=> intval($b->startTime);
        });

        $seasonLabel = $this->GetCurrentStatGroupLabel();
        $logoUrl = '/' . $this->AssetsPath . '/images/logo/logo.png';
        if (strpos($seasonLabel, 'Konovica Cup') !== false) {
            $logoUrl = 'https://hbkpravotice.sk/logo_hbkp_small.png';
        }

        $content = '<!DOCTYPE html>
        <html lang="sk">
        <head>
            <meta charset="UTF-8">
            <title>Rozpis zápasov - '.$seasonLabel.'</title>
            <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;800&display=swap" rel="stylesheet">
            <style>
                @page { size: A4 landscape; margin: 8mm; }
                body { 
                    margin: 0; padding: 0; 
                    font-family: "Montserrat", sans-serif; 
                    background: #fff;
                    color: #333;
                }
                .print-wrap { 
                    position: relative; 
                    width: 100%;
                    min-height: 100vh;
                    padding: 20mm;
                    box-sizing: border-box;
                }
                .print-bg {
                    position: fixed;
                    top: 0; left: 0; width: 100%; height: 100%;
                    background: url("/applications/bnhl/assets/images/kc_bg.jpg") no-repeat center center;
                    background-size: cover;
                    opacity: 0.25;
                    z-index: -1;
                }
                .print-header { 
                    display: flex; 
                    align-items: center; 
                    justify-content: space-between;
                    border-bottom: 4px solid #0f3f73; 
                    padding-bottom: 15px; 
                    margin-bottom: 25px; 
                }
                .print-logo { height: 60px; }
                .print-title { font-size: 32px; font-weight: 800; color: #0f3f73; text-transform: uppercase; letter-spacing: 1px; }
                
                .print-table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    background: rgba(255,255,255,0.9); 
                    table-layout: fixed;
                }
                .print-table th, .print-table td {
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
                .print-table th { 
                    background: #0f3f73; 
                    color: #fff; 
                    padding: 12px 15px; 
                    text-align: left; 
                    font-size: 14px; 
                    text-transform: uppercase; 
                    font-weight: 800; 
                }
                .print-table td { 
                    padding: 10px 15px; 
                    border-bottom: 1px solid #ddd; 
                    font-size: 14px; 
                    vertical-align: middle;
                }
                .team-cell { display: flex; align-items: center; gap: 10px; font-weight: 600; }
                .team-cell.home { justify-content: flex-end; text-align: right; }
                .team-cell.away { justify-content: flex-start; text-align: left; }
                .team-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; }
                .vs-cell { font-weight: 800; color: #0f3f73; text-align: center; width: 60px; font-size: 12px; }
                th.vs-cell { opacity: 1; }
                td.vs-cell { opacity: 0.5; }
                .date-cell { white-space: nowrap; font-weight: 600; color: #444; width: 140px; }
                .time-cell { font-weight: 800; color: #0f3f73; font-size: 16px; margin-left: 5px; }
                
                @media print {
                    body { -webkit-print-color-adjust: exact; }
                    .print-wrap { padding: 15mm; }
                }
            </style>
        </head>
        <body>
            <div class="print-bg"></div>
            <div class="print-wrap">
                <div class="print-header">
                    <img src="'.$logoUrl.'" class="print-logo" />
                    <div class="print-title">Rozpis zápasov - '.$seasonLabel.'</div>
                </div>

                <div class="tables-container">
                    <table class="print-table">
                        <thead>
                        <tr>
                            <th style="width: 140px;">Dátum a čas</th>
                            <th style="width: 100px;"></th>
                            <th style="text-align: right;">Domáci</th>
                            <th class="vs-cell" style="width: 60px; text-align: center; opacity: 1;">VS</th>
                            <th style="text-align: left;">Hostia</th>
                        </tr>
                    </thead>
                    <tbody>';

        if (count($games) > 0) {
                foreach ($games as $game) {
                $dateText = date('d.m.Y', intval($game->startTime));
                $timeText = date('H:i', intval($game->startTime));
                
                $content .= '<tr>
                    <td class="date-cell">'.$dateText.' <span class="time-cell">'.$timeText.'</span></td>
                    <td style="font-style: italic; color: #555; font-size: 12px; font-weight: 600;">'.$game->note.'</td>
                    <td>
                        <div class="team-cell home">
                            <span>'.$game->team1name.'</span>
                            <img src="'.$game->team1ImagePath.'" class="team-logo" />
                        </div>
                    </td>
                    <td class="vs-cell">VS</td>
                    <td>
                        <div class="team-cell away">
                            <img src="'.$game->team2ImagePath.'" class="team-logo" />
                            <span>'.$game->team2name.'</span>
                        </div>
                    </td>
                </tr>';
            }
        } else {
            $content .= '<tr><td colspan="5" style="text-align:center;">Žiadne zápasy na zobrazenie.</td></tr>';
        }

        $content .= '
                        </tbody>
                    </table>
                </div>
            </div>
        </body>
        </html>';

        return $content;
    }
    public function BuildPage_Match($id)
    {
        // /zapas/<not a number> (e.g. relative image links of the template) ended with a fatal TypeError (2026-11-20)
        if (!ctype_digit((string)$id))
            return $this->BuildPage_404();
        $body = new Module_Body();
        $body->content = $this->CreateMatchDetails($id);
        return $body->GetHTML();
    }
    public function BuildPage_Standing()
    {
        $body = new Module_Body();
        $body->content = $this->CreateTeamsTable();
        $body->content .= $this->CreateTeamVsOpponentsTable();
        $body->content .= $this->CreateResults(0, 1, "Výsledky zápasov", 1, array(2,3,4,5,6,7), true);
        return $body->GetHTML();
    }
    public function BuildPage_Team($id)
    {
        $team = $this->GetTeamById($id);
        if ($team == null)
            return $this->BuildPage_404();

        $body = new Module_Body();
        $body->fullPage = true;
        $body->content = $this->CreateTeamPage($team);
        return $body->GetHTML();
    }
    public function BuildPage_Player($id)
    {
        $player = StadiumControlApi::GetPlayer(intval($id));
        /** @var StadiumControl_Player $player */
        if (intval($player->id) <= 0)
            return $this->BuildPage_404();

        $body = new Module_Body();
        $body->fullPage = true;
        $body->content = $this->CreatePlayerPage(intval($id));
        return $body->GetHTML();
    }
    public function BuildPage_TeamRosterPrint($id)
    {
        $team = $this->GetTeamById($id);
        if ($team == null)
            return $this->BuildPage_404();

        $body = new Module_Body();
        $body->fullPage = true;
        $body->content = $this->CreateTeamRosterPrintPage($team);
        return $body->GetHTML();
    }
    public function BuildPage_Rules()
    {
        $hero = new Module_HeroSmall();
        $hero->image = '/images/hero/ref.jpg';
        $hero->AssetsPath = $this->AssetsPath;
        $hero->title = "Pravidlá ligy";

        $article = new Module_Article();
        $article->title = "Pravidlá ligy";
        $article->summary = "BNHL sa riadi podľa pravidiel WBDHF 2025 - <a href='/WBDHF-rule-book-2025-1.pdf' target='_blank'>WBDHF-rule-book-2025-1.pdf</a>.<br>
        Niektoré z pravidiel ale upravujeme pre potreby našej ligy podľa dodatku nižšie.";
        $article->content = '<iframe
  src="https://docs.google.com/document/d/e/2PACX-1vS7AIxp3nSfNhfBnIp90XKuNk2tTsIcvhQhq8gH5UqNa_KUcTcOsCcXsnvobBZZzJVcAvi2m7uhK9QN/pub?embedded=true"
  width="100%"
  height="100%"
  style="min-height: 5000px;top: 0; left: 0; border: none; z-index: 1;"
></iframe>';

        $body = new Module_Body();
        $body->fullPage = true;
        $body->content = $article->GetHTML();
        return $hero->GetHTML().$body->GetHTML();

        
    }
    public function BuildPage_Stats()
    {
        $body = new Module_Body();
        $body->fullPage = false;
        $body->content = $this->CreateStats();
        return $body->GetHTML();
    }
    public function BuildPage_Articles()
    {
        $body = new Module_Body();
        $body->fullPage = true;
        $article = new Module_Article();
        $article->title = "Články";
        $article->summary = "Táto sekcia je vo výstavbe.";
        $body->content = $article->GetHTML();
        return $body->GetHTML();
    }
    public function BuildPage_FreePlayers()
    {
        $body = new Module_Body();
        $body->fullPage = true;
        $article = new Module_Article();
        $article->title = "Voľní hráči";
        $article->summary = "Táto sekcia je vo výstavbe.";
        $article->content = "Ak máte záujem byť zaradený do zoznamu voľných hráčov, kontaktujte nás na emailovej adrese bnhlofficial25@gmail.com";
        $body->content = $article->GetHTML();
        return $body->GetHTML();
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
	private $defaultPage = "prehlad";
	private $page404 = "404";
	private $AssetsPath = "assets";
}
?>