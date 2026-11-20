<?php
include_once (__DIR__ . '/../StadiumControlApi.php');
#[\AllowDynamicProperties]
class Module_Match
{
	/* Public  *****************************/
	public function __construct()
	{
	}

    public $Game = null; // StadiumControl_Game
    public $Title = "";
	public $Id = 0;
    public $Location = "";
    public $Name = "";

    public $RefereeRating = 0;
    public $Visitors = 0;
    public $Referee = "";
    public $Goals = array();
    public $Penalties = array();
    public $RosterTeam1 = array();
    public $RosterTeam2 = array();
    public $Rosters = array();

    public $Time = 0;
    public $Team1id = 0;
    public $Team2id = 0;
    public $Team1name = "";
    public $Team2name = "";
    public $Team1picture = "";
    public $Team2picture = "";
    public $Team1goals = 0;
    public $Team2goals = 0;
    public $MatchState = 0; // 0 - not started, 1 - in progress, 2 - ended

	public $AssetsPath = "assets";

    public $team1winTag = '';
    public $team2winTag = '';
    public $scoreSufix = '';


    public function LoadFromGame()
    {
        if($this->Game != null)
        {
            $this->Id = $this->Game->id;
            $this->Location = $this->Game->location;
            $this->Name = $this->Game->name;
            $this->Time = $this->Game->startTime;
            $this->Team1id = $this->Game->team1;
            $this->Team1name = $this->Game->team1name;
            $this->Team1picture = $this->Game->team1ImagePath;
            $this->Team2id = $this->Game->team2;
            $this->Team2name = $this->Game->team2name;
            $this->Team2picture = $this->Game->team2ImagePath;
            $this->Team1goals = $this->Game->team1goalsCount;
            $this->Team2goals = $this->Game->team2goalsCount;
            $this->Goals = $this->Game->goals;
            $this->Penalties = $this->Game->penalties;
            $this->RosterTeam1 = $this->Game->rosterTeam1;
            $this->RosterTeam2 = $this->Game->rosterTeam2;
            $this->Referee = $this->Game->referee;
            $this->Visitors = $this->Game->visitors;
            $this->RefereeRating = $this->Game->refereeRating;

            $this->Rosters = array_merge((array)$this->RosterTeam1, (array)$this->RosterTeam2);

            //echo "Rosters:<br>";
            //print_r($this->Rosters);
            // determine match state
            $this->MatchState = $this->Game->afterGameStatus; // default to after game status
        }
        if ($this->MatchState == 2 || $this->MatchState == 4) // home team won
        {
            $this->team1winTag = 'game-result-team-win';
        }
        else if ($this->MatchState == 3 || $this->MatchState == 5) // away team won
        {
            $this->team2winTag = 'game-result-team-win';
        }

        if ($this->MatchState == 4 || $this->MatchState == 5)
        {
            $this->scoreSufix = '&nbsp;pp';
        }
    }

    public function GetPlayerNameById($id)
    {
        // combine $this->RosterTeam1 and $this->RosterTeam2 and search for player name by id
        foreach ($this->Rosters as $player) {
    if (isset($player['player']) && intval($player['player']) == intval($id)) {
        return $player['playerName'] ?? '';
    }
}
        return '';

    }
    public function renderSlovakTimeTag(int $timestamp): string 
    {
        // ISO 8601 format for datetime attribute
        $datetimeAttr = date('Y-m-d\TH:i:s', $timestamp);

        // Create a DateTime object
        $date = new DateTime();
        $date->setTimestamp($timestamp);

        // Format visible text in Slovak
        $formatterBig = new IntlDateFormatter(
            'sk_SK',
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            date_default_timezone_get(),
            IntlDateFormatter::GREGORIAN,
            'hh:mm EEEE' // e.g. "Piatok 19. mája 2020"
        );
        $formatterSmall = new IntlDateFormatter(
            'sk_SK',
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            date_default_timezone_get(),
            IntlDateFormatter::GREGORIAN,
            'd. MMMM yyyy' // e.g. "Piatok 19. mája 2020"
        );

        $formattedTextBig = $formatterBig->format($date);
        $formattedTextSmall = $formatterSmall->format($date);

        return '<time class="time-big" datetime="' . $datetimeAttr . '">' .
            '<span class="heading-3">' . $formattedTextBig . '</span>' .$formattedTextSmall.
            '</time>';
    }

    public function formatSlovakDate(int $timestamp): string {
    $formatter = new IntlDateFormatter(
        'sk_SK', // Slovak locale
        IntlDateFormatter::FULL, // Full weekday name
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::GREGORIAN,
        'EEEE dd.MM.yyyy' // Day name + date format
    );
        return $formatter->format($timestamp);
    }

    public function formatSlovakTime(int $timestamp): string {
    $formatter = new IntlDateFormatter(
        'sk_SK', // Slovak locale
        IntlDateFormatter::FULL, // Full weekday name
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::GREGORIAN,
        'hh:mm' // Day name + date format
    );
        return $formatter->format($timestamp);
    }

      private function ReadField($row, $field, $default = null)
      {
        if (is_array($row) && array_key_exists($field, $row))
          return $row[$field];
        if (is_object($row) && isset($row->$field))
          return $row->$field;
        return $default;
      }

    private function FormatGoalTimeWithPeriod($goal)
    {
        $seconds = intval($this->ReadField($goal, 'time', 0));
        if ($seconds < 0)
            $seconds = 0;

        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;
        $timeText = sprintf('%02d:%02d', $minutes, $secs);

        $period = intval($this->ReadField($goal, 'goalPeriod', 0));
        // if ($period > 0)
        //     return $period.'. '.$timeText;

        return $timeText;
    }

    private function BuildGamePlayerPointsMap()
    {
        $pointsMap = array();

        foreach($this->Goals as $goal)
        {
            $scorerId = intval($this->ReadField($goal, 'player_goal', 0));
            if ($scorerId > 0)
            {
                if (!isset($pointsMap[$scorerId]))
                    $pointsMap[$scorerId] = array('goals' => 0, 'assistances' => 0, 'points' => 0);
                $pointsMap[$scorerId]['goals']++;
            }

            $assist1 = intval($this->ReadField($goal, 'player_assist1', 0));
            $assist2 = intval($this->ReadField($goal, 'player_assist2', 0));
            foreach(array($assist1, $assist2) as $assistId)
            {
                if ($assistId <= 0)
                    continue;
                if (!isset($pointsMap[$assistId]))
                    $pointsMap[$assistId] = array('goals' => 0, 'assistances' => 0, 'points' => 0);
                $pointsMap[$assistId]['assistances']++;
            }
        }

        foreach($pointsMap as $playerId => $data)
            $pointsMap[$playerId]['points'] = $data['goals'] + $data['assistances'];

        return $pointsMap;
    }

	public function GetHTML()
	{
        $this->LoadFromGame();
        $content = '
        <!-- Game Result Bug-->
                <article class="game-result">
                  <div class="game-info game-info-creative">
                    <p class="game-info-subtitle">'.$this->Location;

                    if ($this->MatchState > 1)
                    {
                        $content .= ' | '.
                        $this->formatSlovakDate($this->Time);
                    }
                    else if ($this->MatchState == 1)
                    {
                        $content .=' | Práve prebieha';
                    }
                    $content .='
                    </p>
                    <h3 class="game-info-title">'.$this->Name.'</h3>
                    <div class="game-info-main">
                      <div class="game-info-team game-info-team-first">
                        <figure><img src="'.$this->Team1picture.'" alt="" width="100" height="100"/>
                        </figure>
                        <div class="game-result-team-name"><a href="/tim/'.$this->Team1id.'">'.$this->Team1name.'</a></div>
                      </div>
                      <div class="game-info-middle game-info-middle-vertical">';
                      if ($this->MatchState == 0) // pregame
                        {
                            $content .= $this->renderSlovakTimeTag($this->Time);
                        }
                        else
                        {
                            $content .= '
                            <div class="game-result-score-wrap">
                          <div class="game-info-score '.$this->team1winTag.'">'.$this->Team1goals.'
                          </div>
                          <div class="game-result-score-divider">
                            <svg x="0px" y="0px" width="7px" height="21px" viewbox="0 0 7 21" enable-background="new 0 0 7 21" xml:space="preserve">
                              <g>
                                <circle cx="3.5" cy="3.5" r="3"></circle>
                                <path d="M3.5,1C4.879,1,6,2.122,6,3.5S4.879,6,3.5,6S1,4.878,1,3.5S2.122,1,3.5,1 M3.5,0C1.567,0,0,1.567,0,3.5S1.567,7,3.5,7      S7,5.433,7,3.5S5.433,0,3.5,0L3.5,0z"></path>
                              </g>
                              <g>
                                <circle cx="3.695" cy="17.5" r="3"></circle>
                                <path d="M3.695,15c1.378,0,2.5,1.122,2.5,2.5S5.073,20,3.695,20s-2.5-1.122-2.5-2.5S2.316,15,3.695,15 M3.695,14      c-1.933,0-3.5,1.567-3.5,3.5s1.567,3.5,3.5,3.5s3.5-1.567,3.5-3.5S5.628,14,3.695,14L3.695,14z"></path>
                              </g>
                            </svg>
                          </div>
                          <div class="game-info-score '.$this->team2winTag.'">'.$this->Team2goals.'
                          </div>'.$this->scoreSufix.'
                        </div>
                            ';
                        }
                        

                        $content .='
                        <div class="game-result-divider-wrap"><span class="game-info-team-divider">VS</span></div>
                        <div class="group-sm">
                        </div>
                      </div>
                      <div class="game-info-team game-info-team-second">
                        <figure><img src="'.$this->Team2picture.'" alt="" width="100" height="100"/>
                        </figure>
                        <div class="game-result-team-name"><a href="/tim/'.$this->Team2id.'">'.$this->Team2name.'</a></div>
                      </div>
                    </div>
                  </div>
                  <div class="game-info-countdown">'.
                    // <div class="countdown countdown-bordered" data-type="until" data-time="31 Dec 2020 16:00" data-format="dhms" data-style="short"></div>
                  '</div>
                </article>
        ';
		return $content;
	}

    public function GetHTML_small()
	{
        $this->LoadFromGame();
        $content = '
                  <!-- Game Result Classic-->
                  <article class="game-result game-result-classic">
                    <a href="/zapas/'.$this->Id.'">
                    <h5 class="game-info-title-small">'.$this->Name.'</h5>
                    <div class="game-result-main">
                      <div class="game-result-team game-result-team-first">
                        <figure class="game-result-team-figure game-result-team-figure-big"><img src="'.$this->Team1picture.'" alt="" width="55" height="55"/>
                        </figure>
                        <div class="game-result-team-name"><a href="/tim/'.$this->Team1id.'">'.$this->Team1name.'</a></div>
                      </div>
                      <div class="game-result-middle">';
                       if ($this->MatchState == 0) // pregame
                        {
                            $content .= '
                            <div class="game-result-score-wrap">
                          <div class="game-result-score">'.$this->formatSlovakTime($this->Time).'<br>'.$this->formatSlovakDate($this->Time).'
                          </div>
                        </div>
                        <div class="game-results-status">vs</div>
                            ';
                        }
                        else if ($this->MatchState == 1) // in progress
                        {
                            $content .= '
                            <div class="game-result-score-wrap">
                          <div class="game-result-score">'.$this->Team1goals.'
                          </div>
                          <div class="game-result-score-divider">
                            <svg x="0px" y="0px" width="7px" height="21px" viewbox="0 0 7 21" enable-background="new 0 0 7 21" xml:space="preserve">
                              <g>
                                <circle cx="3.5" cy="3.5" r="3"></circle>
                                <path d="M3.5,1C4.879,1,6,2.122,6,3.5S4.879,6,3.5,6S1,4.878,1,3.5S2.122,1,3.5,1 M3.5,0C1.567,0,0,1.567,0,3.5S1.567,7,3.5,7      S7,5.433,7,3.5S5.433,0,3.5,0L3.5,0z"></path>
                              </g>
                              <g>
                                <circle cx="3.695" cy="17.5" r="3"></circle>
                                <path d="M3.695,15c1.378,0,2.5,1.122,2.5,2.5S5.073,20,3.695,20s-2.5-1.122-2.5-2.5S2.316,15,3.695,15 M3.695,14      c-1.933,0-3.5,1.567-3.5,3.5s1.567,3.5,3.5,3.5s3.5-1.567,3.5-3.5S5.628,14,3.695,14L3.695,14z"></path>
                              </g>
                            </svg>
                          </div>
                          <div class="game-result-score">'.$this->Team2goals.'
                          </div>
                        </div>
                        <div class="game-results-status">Home</div>
                            ';
                        }
                        else // ended
                        {
                            $content .= '
                            <div class="game-result-score-wrap">
                          <div class="game-result-score '.$this->team1winTag.'">'.$this->Team1goals.'
                          </div>
                          <div class="game-result-score-divider">
                            <svg x="0px" y="0px" width="7px" height="21px" viewbox="0 0 7 21" enable-background="new 0 0 7 21" xml:space="preserve">
                              <g>
                                <circle cx="3.5" cy="3.5" r="3"></circle>
                                <path d="M3.5,1C4.879,1,6,2.122,6,3.5S4.879,6,3.5,6S1,4.878,1,3.5S2.122,1,3.5,1 M3.5,0C1.567,0,0,1.567,0,3.5S1.567,7,3.5,7      S7,5.433,7,3.5S5.433,0,3.5,0L3.5,0z"></path>
                              </g>
                              <g>
                                <circle cx="3.695" cy="17.5" r="3"></circle>
                                <path d="M3.695,15c1.378,0,2.5,1.122,2.5,2.5S5.073,20,3.695,20s-2.5-1.122-2.5-2.5S2.316,15,3.695,15 M3.695,14      c-1.933,0-3.5,1.567-3.5,3.5s1.567,3.5,3.5,3.5s3.5-1.567,3.5-3.5S5.628,14,3.695,14L3.695,14z"></path>
                              </g>
                            </svg>
                          </div>
                          <div class="game-result-score '.$this->team2winTag.'">'.$this->Team2goals.'
                          </div>'.$this->scoreSufix.'
                        </div>
                        <div class="game-results-status">vs</div>
                            ';
                        }
                        $content .='
                      </div>
                      <div class="game-result-team game-result-team-second">
                        <figure class="game-result-team-figure game-result-team-figure-big"><img src="'.$this->Team2picture.'" alt="" width="60" height="60"/>
                        </figure>
                        <div class="game-result-team-name"><a href="/tim/'.$this->Team2id.'">'.$this->Team2name.'</a></div>
                      </div>
                    </div>
                    <div class="game-result-footer">
                      <ul class="game-result-details">
                        <li>'.$this->Location.'</li>
                        ';
                        if ($this->MatchState > 1)
                        {
                            $content .='<li>
                            '.$this->formatSlovakDate($this->Time).'
                            </li>';
                        }
                        else if ($this->MatchState == 1)
                        {
                            $content .='<li>
                            Práve prebieha
                            </li>';
                        }
                      $content .='</ul>
                    </div>
                  </a></article>
        ';
		return $content;
	}

    public function GetHTML_details()
	{
        $this->LoadFromGame();
        $content = '
        <div class="row row-50">
                <div class="col-sm-12">
                <!-- Heading Component-->
                <article class="heading-component">
                  <div class="heading-component-inner">
                    <h5 class="heading-component-title">'.$this->Title.'</h5>
                  </div>
                </article>';
        // $games = StadiumControlApi::GetGames(array("eventgroup" => $this->EventGroupId, "afterStatuses" => implode(",",$this->AfterGameStatuses), "orderBy" => "startTime", "orderDir" => "ASC", "limit" => 30));
        //$games = StadiumControlApi::GetGames(array("eventgroup" => $this->EventGroupId));
        // sort games by startTime ascending
        
        $content .='<!-- Game Result Bug-->
                  <article class="game-result">
                    <div class="game-info game-info-classic">
                      <p class="game-info-subtitle">'.$this->Location.' - ';
                      if ($this->MatchState == 1)
                      {
                        $content.= "Práve prebieha";
                      }
                      else
                      {
                        $content.= '<time>'.$this->formatSlovakTime($this->Time).' '.$this->formatSlovakDate($this->Time).'</time>';
                      }
                      $content.= '</p>
                      <h3 class="game-info-title">'.$this->Name.'</h3>
                      <div class="game-info-main">
                        <div class="game-info-team game-info-team-first">
                          <figure><img src="'.$this->Team1picture.'" alt="" width="75" height="99"/>
                          </figure>
                          <div class="game-result-team-name"><a href="/tim/'.$this->Team1id.'">'.$this->Team1name.'</a></div>
                        </div>
                        <div class="game-info-middle">
                          <div class="game-result-score-wrap">
                            <div class="game-info-score '.$this->team1winTag.'">'.$this->Team1goals.'</div>
                            <div class="game-info-score"> : </div>
                            <div class="game-info-score '.$this->team2winTag.'">'.$this->Team2goals.'</div>
                          </div>
                          <div class="game-result-divider-wrap"><span class="game-info-team-divider">VS</span></div>';
                          $referee = $this->Referee;
                          if ($this->Referee == '')
                          {
                            $referee = "<i>Neurčený</i>";
                          }
                          $content .= '<p>Rozhodca: '.$referee.'</p>';
                          if ($this->MatchState > 1)
                          {
                            $content .= '<p>Počet divákov: '.$this->Visitors.'</p>';
                          }
                        $content .= '</div>
                        <div class="game-info-team game-info-team-second">
                          <figure><img src="'.$this->Team2picture.'" alt="" width="77" height="99"/>
                          </figure>
                          <div class="game-result-team-name"><a href="/tim/'.$this->Team2id.'">'.$this->Team2name.'</a></div>
                        </div>
                      </div>';
                      if ($this->MatchState == 0)
                      {
                        $content.= "";
                      }
                      else
                      {

                    $content .='
                      <!-- Table Game Info-->
                      <div class="table-game-info-wrap"><span class="table-game-info-title">Priebeh zápasu<span></span></span>
                        <div class="table-game-info-main table-custom-responsive">
                          <table class="table-custom table-game-info">
                            <tbody>';
                            // combine goals and penalties into one chronological timeline
                            $events = array();
                            foreach($this->Goals as $goal)
                            {
                              $events[] = array(
                                'kind' => 'goal',
                                'time' => intval($this->ReadField($goal, 'time', 0)),
                                'id' => intval($this->ReadField($goal, 'goalid', 0)),
                                'row' => $goal
                              );
                            }
                            foreach($this->Penalties as $penalty)
                            {
                              $events[] = array(
                                'kind' => 'penalty',
                                'time' => intval($this->ReadField($penalty, 'timestamp', 0)),
                                'id' => intval($this->ReadField($penalty, 'id', 0)),
                                'row' => $penalty
                              );
                            }
                            usort($events, function($a, $b) {
                              if ($a['time'] === $b['time'])
                              {
                                if ($a['kind'] === $b['kind'])
                                  return $a['id'] <=> $b['id'];
                                return ($a['kind'] === 'goal') ? -1 : 1;
                              }
                              return $a['time'] <=> $b['time'];
                            });
                            $periodScores = array();
                            $maxSummaryPeriod = 3;
                            if ($this->MatchState == 4 || $this->MatchState == 5)
                            {
                                $maxSummaryPeriod = 4; // zápas v predĺžení mal aspoň 4 periódy
                            }

                            foreach($this->Goals as $goalForPeriodScore)
                            {
                              $goalPeriodForScore = intval($this->ReadField($goalForPeriodScore, 'goalPeriod', 0));
                              if ($goalPeriodForScore <= 0)
                                continue;
                                
                              if ($goalPeriodForScore > $maxSummaryPeriod)
                                $maxSummaryPeriod = $goalPeriodForScore;

                              if (!isset($periodScores[$goalPeriodForScore]))
                              {
                                $periodScores[$goalPeriodForScore] = array(
                                  'home' => 0,
                                  'away' => 0
                                );
                              }

                              if (intval($this->ReadField($goalForPeriodScore, 'teamid', 0)) == intval($this->Team1id))
                                $periodScores[$goalPeriodForScore]['home']++;
                              else
                                $periodScores[$goalPeriodForScore]['away']++;
                            }

                            $homeGoalCounter = 0;
                            $awayGoalCounter = 0;
                            $eventsCounter = 0;
                            $lastShownPeriod = -1;
                            foreach($events as $event)
                            {
                              $eventsCounter++;

                              if ($event['kind'] === 'goal')
                              {
                                $goal = $event['row'];
                                $goalPeriod = intval($this->ReadField($goal, 'goalPeriod', 0));
                                if ($goalPeriod > 0)
                                {
                                  if ($lastShownPeriod < 0) $lastShownPeriod = 0;
                                  while ($lastShownPeriod < $goalPeriod - 1)
                                  {
                                    $lastShownPeriod++;
                                    $pHome = isset($periodScores[$lastShownPeriod]) ? $periodScores[$lastShownPeriod]['home'] : 0;
                                    $pAway = isset($periodScores[$lastShownPeriod]) ? $periodScores[$lastShownPeriod]['away'] : 0;
                                    $content .= '
                                    <tr>
                                      <td colspan="3" style="padding-top:8px; padding-bottom:6px;">
                                        <div style="text-align:center;">'.$lastShownPeriod.'. perióda ('.$pHome.' : '.$pAway.')</div>
                                      </td>
                                    </tr>';
                                  }

                                  if ($goalPeriod !== $lastShownPeriod)
                                  {
                                    $lastShownPeriod = $goalPeriod;
                                    $periodHomeScore = isset($periodScores[$goalPeriod]) ? $periodScores[$goalPeriod]['home'] : 0;
                                    $periodAwayScore = isset($periodScores[$goalPeriod]) ? $periodScores[$goalPeriod]['away'] : 0;
                                    $content .= '
                                    <tr>
                                      <td colspan="3" style="padding-top:8px; padding-bottom:6px;">
                                        <div style="text-align:center;">'.$goalPeriod.'. perióda ('.$periodHomeScore.' : '.$periodAwayScore.')</div>
                                      </td>
                                    </tr>';
                                  }
                                }

                                $goalTimeText = $this->FormatGoalTimeWithPeriod($goal);
                                
                                $goalTypeLabels = array(
                                  0 => '',
                                  1 => ' (PP)',
                                  2 => ' (SH)',
                                  3 => ' (EN)',
                                  4 => ' (SO)',
                                );
                                $gType = intval($this->ReadField($goal, 'goalType', 0));
                                $goalTypeStr = isset($goalTypeLabels[$gType]) ? $goalTypeLabels[$gType] : '';

                                $content .='
                                <tr style="background-color: #fffacd;">';
                                if ($goal['teamid'] == $this->Team1id) // home team goal
                                {
                                  $homeGoalCounter++;
                                $content .='
                                <td class="table-game-info-number" style="">'.$goalTimeText.'</td>
                                  <td class="table-game-info-category" style="text-align: left;">'.$this->GetPlayerNameById($goal['player_goal']);
                                  if ($goal['player_assist1'] != null)
                                    $content .= ' ('.$this->GetPlayerNameById($goal['player_assist1']).')';
                                  $content .= $goalTypeStr.'</td><td style="">'.$homeGoalCounter.' : '.$awayGoalCounter.'</td>';
                                }
                                else // away team goal
                                {
                                  $awayGoalCounter++;
                                  $content .='
                                  <td style="">'.$homeGoalCounter.' : '.$awayGoalCounter.'</td>
                                  <td class="table-game-info-category" style="text-align: right;">'.$this->GetPlayerNameById($goal['player_goal']);
                                  if ($goal['player_assist1'] != null)
                                    $content .= ' ('.$this->GetPlayerNameById($goal['player_assist1']).')';
                                  $content .= $goalTypeStr.'</td>
                                  <td class="table-game-info-number" style="">'.$goalTimeText.'</td>';
                                }
                                $content .='</tr>
                                ';
                              }
                              else
                              {
                                $penalty = $event['row'];
                                $penaltyTimeText = sprintf('%02d:%02d', intdiv(intval($this->ReadField($penalty, 'timestamp', 0)), 60), intval($this->ReadField($penalty, 'timestamp', 0)) % 60);
                                $penaltyPlayer = $this->GetPlayerNameById($this->ReadField($penalty, 'player', 0));
                                $penaltyDurationMin = intval($this->ReadField($penalty, 'duration', 0)) / 60;
                                
                                $penaltyTypeLabels = array(
                                  0 => 'Iný trest',
                                  1 => 'Napadnutie rozhodcov',
                                  2 => 'Agresor',
                                  3 => 'Pokus o zranenie',
                                  4 => 'Vrazenie na mantinel',
                                  5 => 'Zlomená hokejka',
                                  6 => 'Krosček',
                                  7 => 'Zdržiavanie hry',
                                  8 => 'Faul lakťom',
                                  9 => 'Bitka',
                                  10 => 'Nedov. br. brankárovi',
                                  11 => 'Opustenie bránkoviska',
                                  12 => 'Úder hlavou',
                                  13 => 'Vysoká hokejka',
                                  14 => 'Držanie',
                                  15 => 'Háčkovanie',
                                  16 => 'Nedovolený výstroj',
                                  17 => 'Nedovolené bránenie',
                                  18 => 'Faul kolenom',
                                  19 => 'Hrubosť',
                                  20 => 'Sekanie',
                                  21 => 'Hodenie hokejky',
                                  22 => 'Príliš veľa hráčov',
                                  23 => 'Podrazenie',
                                  24 => 'Nešportové správanie'
                                );
                                $pType = intval($this->ReadField($penalty, 'penType', 0));
                                $pTypeStr = isset($penaltyTypeLabels[$pType]) ? $penaltyTypeLabels[$pType] : 'Trest';

                                $penaltyLabel = $pTypeStr . ' - ' . $penaltyPlayer . ' (' . $penaltyDurationMin . 'm)';
                                $penaltyTeamId = intval($this->ReadField($penalty, 'team', 0));

                                $content .= '
                                <tr style="background-color: #fff5f5;">';
                                if ($penaltyTeamId === intval($this->Team1id))
                                {
                                    $content .= '
                                    <td class="table-game-info-number">'.$penaltyTimeText.'</td>
                                    <td class="table-game-info-category" style="text-align:left; border-top:1px dashed #cc0000; border-bottom:1px dashed #cc0000;">'.$penaltyLabel.'</td>
                                    <td>&nbsp;</td>';
                                }
                                else
                                {
                                    $content .= '
                                    <td>&nbsp;</td>
                                    <td class="table-game-info-category" style="text-align:right; border-top:1px dashed #cc0000; border-bottom:1px dashed #cc0000;">'.$penaltyLabel.'</td>
                                    <td class="table-game-info-number">'.$penaltyTimeText.'</td>';
                                }
                                $content .= '</tr>';
                              }
                            }

                            if ($lastShownPeriod < 0) $lastShownPeriod = 0;
                            while ($lastShownPeriod < $maxSummaryPeriod)
                            {
                              $lastShownPeriod++;
                              $pHome = isset($periodScores[$lastShownPeriod]) ? $periodScores[$lastShownPeriod]['home'] : 0;
                              $pAway = isset($periodScores[$lastShownPeriod]) ? $periodScores[$lastShownPeriod]['away'] : 0;
                              $content .= '
                              <tr>
                                <td colspan="3" style="padding-top:8px; padding-bottom:6px;">
                                  <div style="text-align:center;">'.$lastShownPeriod.'. perióda ('.$pHome.' : '.$pAway.')</div>
                                </td>
                              </tr>';
                            }

                            $content .='
                            </tbody>
                          </table>
                        </div>
                      </div>';

                      $content .='<br>
                      <!-- Table Game Info-->
                      <div class="table-game-info-wrap"><span class="table-game-info-title">Tresty<span></span></span>
                        <div class="table-game-info-main table-custom-responsive">
                          <table class="table-custom table-game-info">
                            <tbody>';
                            // combine goals from both teams into single list, sort by time
                            $penalties = $this->Penalties;
                            usort($penalties, function($a, $b) {
                                return $a['timestamp'] <=> $b['timestamp'];
                            });
                            //echo "Goals:<br>";
                            //print_r($goals);
                            $penaltyCounter = 0;
                                foreach($penalties as $penalty)
                                {
                                $penaltyCounter++;
                                $content .='
                              <tr>';
                                $penaltyTimeText = sprintf('%02d:%02d', intdiv(intval($this->ReadField($penalty, 'timestamp', 0)), 60), intval($this->ReadField($penalty, 'timestamp', 0)) % 60);
                                if ($penalty['team'] == $this->Team1id) // home team goal
                                {
                                $content .='
                                    <td class="table-game-info-number">'.$penaltyTimeText.'</td>
                                    <td class="table-game-info-category" style="text-align: left;">'.$this->GetPlayerNameById($penalty['player']);

                                    $content .=' ('.($penalty['duration']/60).'m)</td><td></td>';
                                }
                                else // away team goal
                                {
                                    $content .='
                                    <td></td>
                                    <td class="table-game-info-category" style="text-align: right;">'.$this->GetPlayerNameById($penalty['player']);

                                    $content .=' ('.($penalty['duration']/60).'m)</td>
                                    <td class="table-game-info-number">'.$penaltyTimeText.'</td>';
                                }
                              $content .='</tr>
                                ';
                            }
                            if ($penaltyCounter == 0)
                            {
                                $content .='Žiadný záznam';
                            }

                            $content .='
                            </tbody>
                          </table>
                        </div>
                      </div>';

                        }
        $content .='
                    </div>
                  </article>
                </div>';

        $pointsMap = $this->BuildGamePlayerPointsMap();
        $team1Roster = $this->RosterTeam1;
        $team2Roster = $this->RosterTeam2;
        $sortRosterByPoints = function(&$roster) use ($pointsMap) {
            usort($roster, function($a, $b) use ($pointsMap) {
                $aId = intval($this->ReadField($a, 'player', 0));
                $bId = intval($this->ReadField($b, 'player', 0));

                $aGoals = isset($pointsMap[$aId]) ? intval($pointsMap[$aId]['goals']) : 0;
                $bGoals = isset($pointsMap[$bId]) ? intval($pointsMap[$bId]['goals']) : 0;
                $aAssists = isset($pointsMap[$aId]) ? intval($pointsMap[$aId]['assistances']) : 0;
                $bAssists = isset($pointsMap[$bId]) ? intval($pointsMap[$bId]['assistances']) : 0;
                $aPoints = $aGoals + $aAssists;
                $bPoints = $bGoals + $bAssists;

                if ($aPoints !== $bPoints) return $bPoints <=> $aPoints;
                if ($aGoals !== $bGoals) return $bGoals <=> $aGoals;
                if ($aAssists !== $bAssists) return $bAssists <=> $aAssists;

                return strcasecmp(
                    strval($this->ReadField($a, 'playerName', '')),
                    strval($this->ReadField($b, 'playerName', ''))
                );
            });
        };
        $sortRosterByPoints($team1Roster);
        $sortRosterByPoints($team2Roster);

        $content .='<div class="col-sm-12">
                  <!-- Heading Component-->
                  <article class="heading-component">
                    <div class="heading-component-inner">
                      <h5 class="heading-component-title">Súpisky tímov v zápase
                      </h5>
                    </div>
                  </article>
                  <div class="row row-30">
                    <div class="col-sm-6">
                      <!-- Table Roster-->
                      <div class="table-custom-responsive">
                        <table class="table-custom table-roster">
                          <thead>
                            <tr>
                              <th colspan="5">'.$this->Team1name.'</th>
                            </tr>
                          </thead>';
                          $content .='<tbody><tr>
                               <td>#</td>
                               <td>Meno</td>
                               <td>G</td>
                               <td>A</td>
                               <td>B</td>
                             </tr>';
                          foreach($team1Roster as $player)
                          {
                            $playerId = intval($this->ReadField($player, 'player', 0));
                            $goals = isset($pointsMap[$playerId]) ? intval($pointsMap[$playerId]['goals']) : 0;
                            $assistances = isset($pointsMap[$playerId]) ? intval($pointsMap[$playerId]['assistances']) : 0;
                            $points = $goals + $assistances;
                            $content .='<tr>
                               <td>'.$this->ReadField($player, 'number', '').'</td>
                               <td>'.$this->ReadField($player, 'playerName', '').'</td>
                               <td>'.$goals.'</td>
                               <td>'.$assistances.'</td>
                               <td><b>'.$points.'</b></td>
                             </tr>';
                          }

                        $content .='</tbody>
                        </table>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <!-- Table Roster-->
                      <div class="table-custom-responsive">
                        <table class="table-custom table-roster team2-blue">
                          <thead>
                            <tr>
                              <th colspan="5">'.$this->Team2name.'</th>
                            </tr>
                          </thead><tbody>';
                          $content .='<tbody><tr>
                               <td>#</td>
                               <td>Meno</td>
                               <td>G</td>
                               <td>A</td>
                               <td>B</td>
                             </tr>';
                          foreach($team2Roster as $player)
                          {
                            $playerId = intval($this->ReadField($player, 'player', 0));
                            $goals = isset($pointsMap[$playerId]) ? intval($pointsMap[$playerId]['goals']) : 0;
                            $assistances = isset($pointsMap[$playerId]) ? intval($pointsMap[$playerId]['assistances']) : 0;
                            $points = $goals + $assistances;
                            $content .='<tr>
                               <td>'.$this->ReadField($player, 'number', '').'</td>
                               <td>'.$this->ReadField($player, 'playerName', '').'</td>
                               <td>'.$goals.'</td>
                               <td>'.$assistances.'</td>
                               <td><b>'.$points.'</b></td>
                             </tr>';
                          }

                        $content .='</tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>';
        
        $content .='</div>
        ';
		return $content;
	}
}

?>