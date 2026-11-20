<?php
include_once (__DIR__ . '/../StadiumControlApi.php');
#[\AllowDynamicProperties]
class Module_Matches
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
    public $AfterGameStatuses = array(2); // statuses after game is played

    public $View = 1; // large = 0, small = 1
    public $Limit = 0;
    public $SortDir = 0; // 0=asc, 1=desc
    public $Title = "Vypis zápasov";
    public $Games = array();
    public $InRow = false;
    public $ShowOnlyIfThereAreGames = false;

	public $AssetsPath = "assets";
  
	public function GetHTML()
	{
        $content = '
        <div class="main-component">
                <!-- Heading Component-->
                <article class="heading-component">
                  <div class="heading-component-inner">
                    <h5 class="heading-component-title">'.$this->Title.'</h5>
                  </div>
                </article>';
        // $games = StadiumControlApi::GetGames(array("eventgroup" => $this->EventGroupId, "afterStatuses" => implode(",",$this->AfterGameStatuses), "orderBy" => "startTime", "orderDir" => "ASC", "limit" => 30));
        //$games = StadiumControlApi::GetGames(array("eventgroup" => $this->EventGroupId));
        // Filter first so the limit is applied to the chronologically sorted matches.
        $games = array();
        foreach ($this->Games as $game)
        {
            if (in_array($game->afterGameStatus, $this->AfterGameStatuses))
                $games[] = $game;
        }

        $sortDirection = ($this->SortDir == 1) ? -1 : 1;
        usort($games, function($a, $b) use ($sortDirection) {
            $timeA = intval($a->startTime);
            $timeB = intval($b->startTime);
            if ($timeA == $timeB)
                return intval($a->id) <=> intval($b->id);
            return ($timeA <=> $timeB) * $sortDirection;
        });
    
        /* small match view */
        if ($this->View == 1)
        {
            if ($this->InRow)
                $content .= '<div class="row row-30">'; /* use for small */
            $count = 0;
            foreach($games as $game)
            {
                if ($this->Limit > 0 && $count >= $this->Limit)
                    break;
                $match = new Module_Match();
                $match->AssetsPath = $this->AssetsPath;
                $match->Game = $game;
                if ($this->InRow)
                    $content .= '<div class="col-md-6">'; /* use for small */
                $content .= ''.$match->GetHTML_small().'';
                if ($this->InRow)
                    $content .= '</div>'; /* use for small */
                $count++;
            }
            if ($this->InRow)
                $content .='</div>';/* use for small */
        }   
        else
        {
            /* large match view */
            $count = 0;
            foreach($games as $game)
            {
                if ($this->Limit > 0 && $count >= $this->Limit)
                    break;
                $match = new Module_Match();
                $match->AssetsPath = $this->AssetsPath;
                $match->Game = $game;
                $content .= $match->GetHTML();
                $count++;
            }
        }

        $content .='</div>
        ';
        if ($this->ShowOnlyIfThereAreGames && $count == 0)
            return "";
		return $content;
	}
}

?>