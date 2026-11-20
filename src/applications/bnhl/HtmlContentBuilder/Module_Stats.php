<?php
include_once (__DIR__ . '/../StadiumControlApi.php');
#[\AllowDynamicProperties]
class Module_Stats
{
	/* Public  *****************************/
	public function __construct()
	{
	}

    public $PlayerStats = array(); // playerstats
    public $Title = "";
    public $HeaderSortLinks = array();
    public $PlayerLinkBasePath = "";

	public $AssetsPath = "assets";

  private function HeaderLabel($field, $fallback)
  {
    if (isset($this->HeaderSortLinks[$field]))
      return $this->HeaderSortLinks[$field];
    return $fallback;
  }

    public function GetHTML()
	{
        $content = '
        <div class="row row-50">
                <div class="col-sm-12">
                <!-- Heading Component-->
                <article class="heading-component">
                  <div class="heading-component-inner">
                    <h5 class="heading-component-title">'.$this->Title.'</h5>
                  </div>
                </article>';


        $content .='
                  <div class="row row-30">
                    <div class="col-sm-12">
                      <!-- Table Roster-->
                      <div class="table-custom-responsive">
                        <table class="table-custom" style="text-align: left;">
                          <thead>
                            <tr style="font-size: 17px;">';
                              $content .='
                               <th></th>
                               <th></th>
                               <th>'.$this->HeaderLabel("playerName", "Hráč").'</th>
                               <th>'.$this->HeaderLabel("games", "Z").'</th>
                               <th>'.$this->HeaderLabel("goals", "G").'</th>
                               <th>'.$this->HeaderLabel("assistances", "A").'</th>
                               <th>'.$this->HeaderLabel("totalPoints", "B").'</th>
                               <th>'.$this->HeaderLabel("pointsPerGame", "BNZ").'</th>
                               <th>'.$this->HeaderLabel("penaltySeconds", "TM").'</th>';
                            $content .='</tr>
                          </thead>';
                        //   $content .='<tbody><tr>
                        //        <td></td>
                        //        <td></td>
                        //        <td>Meno</td>
                        //        <td>Z</td>
                        //        <td>G</td>
                        //        <td>A</td>
                        //        <td>B</td>
                        //        <td>BNZ</td>
                        //        <td>TM</td>
                        //      </tr>';
                            $counter = 0;
                            foreach($this->PlayerStats as $player)
                            {
                                $counter++;
                              $playerName = $player->playerName;
                              if ($this->PlayerLinkBasePath !== "")
                                $playerName = '<a href="'.$this->PlayerLinkBasePath.$player->playerid.'">'.$playerName.'</a>';
                                $content .='
                                <tr style="height: 40px;">
                                <td style="text-align: center;">'.$counter.'</td>';
                                //$content .= '<td><img src="'.$player->playerImagePath.'" alt="'.$player->playerName.'" width="50" /></td>';
                                $content .= '<td><img src="'.$player->teamImagePath.'" alt="'.$player->teamName.'" width="25" /></td>
                              <td>'.$playerName.' <small>#'.$player->playerNumber.' '.$player->teamName.'</small></td>
                                <td>'.$player->games.'</td>
                                <td>'.$player->goals.'</td>
                                <td>'.$player->assistances.'</td>
                                <td>'.$player->totalPoints.'</td>
                                <td>'.$player->pointsPerGame.'</td>
                                <td>'.intval($player->penaltySeconds / 60).'</td>
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