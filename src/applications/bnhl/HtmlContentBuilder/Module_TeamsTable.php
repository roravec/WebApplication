<?php
#[\AllowDynamicProperties]
class Module_TeamsTable
{
	/* Public  *****************************/
	public function __construct()
	{
	}

    public $Title = "Tabuľka";
    public $playersStats = false;
    public $Columns = array();
    public $Rows = array();

//     class TeamStats extends JsonHydratable
// {
//     public int $id = 0;
//     public string $name = '';
//     public string $shortName = '';
//     public string $teamPicture = '';
//     public int $points = 0;
//     public int $goalsScored = 0;
//     public int $goalsReceived = 0;
//     public int $goalDifference = 0;
//     public int $penalties = 0;
//     public int $games = 0;
//     public int $wins = 0;
//     public int $winsPP = 0;
//     public int $ties = 0;
//     public int $loses = 0;
//     public int $losesPP = 0;
// }

    public function GetRowHTML($row)
    {
        $content = '<article class="card card-custom">
                      <div class="card-standing-position card-standing-counter"></div>
                      <div class="card-header" role="tab">
                        <div class="card-standing-team-item">
                          <div class="card-standing-team">
                            <div class="card-standing-team-figure"><img src="'.$row->teamPicture.'" alt="" width="36" height="36"/>
                            </div>
                            <div class="card-standing-team-title">
                              <div class="card-standing-team-name"><a href="/tim/'.$row->id.'">'.$row->name.'</a></div>
                            </div>
                          </div>
                            <div class="card-standing-number">'.$row->games.'</div>
                            <div class="card-standing-number">'.$row->wins.'</div>
                            <div class="card-standing-number">'.$row->winsPP.'</div>
                            <div class="card-standing-number">'.$row->loses.'</div>
                            <div class="card-standing-number">'.$row->losesPP.'</div>
                            <div class="card-standing-number">'.$row->goalsScored.'</div>
                            <div class="card-standing-number">'.$row->goalsReceived.'</div>
                            <div class="card-standing-number">'.$row->goalDifference.'</div>
                            <div class="card-standing-diff">'.$row->points.'
                        </div>
                      </div>
                    </article>';
        return $content;
    }
  
	public function GetHTML()
	{
        $content = '
                  <!-- Heading Component-->
                  <article class="heading-component">
                    <div class="heading-component-inner">
                      <h5 class="heading-component-title">'.$this->Title.'
                      </h5>
                    </div>
                  </article>
                  <div class="card-standing card-group-custom card-standing-index" id="accordion1" role="tablist" aria-multiselectable="false">
                    <div class="card-standing-caption">
                      <div class="card-standing-position">Poz</div>
                      <div class="card-standing-caption-aside">
                        <div class="card-standing-team">Tím</div>
                        <div class="card-standing-number">Z</div>
                        <div class="card-standing-number">V</div>
                        <div class="card-standing-number">Vpp</div>
                        <div class="card-standing-number">P</div>
                        <div class="card-standing-number">Ppp</div>
                        <div class="card-standing-number">VG</div>
                        <div class="card-standing-number">IG</div>
                        <div class="card-standing-number">Roz</div>
                        <div class="card-standing-diff">B</div>
                      </div>
                    </div>';
        foreach($this->Rows as $row)
            $content .= $this->GetRowHTML($row);
        $content .= '
                  </div>
        ';
		return $content;
	}

    public function GetRowHtml_small($row, $index)
    {
        $content = '<tr>
                      <td><span>'.$index.'</span></td>
                      <td class="team-inline">
                        <div class="team-figure"><img src="'.$row->teamPicture.'" alt="" width="31" height="41"/>
                        </div>
                        <div class="team-title">
                          <div class="team-name"><a href="/tim/'.$row->id.'">'.$row->name.'</a></div>
                        </div>
                      </td>
                        <td>'.$row->points.'</td>
                      </tr>';
        return $content;
    }
    public function GetHtml_small()
    {
        $content = '
            <div class="col-lg-6 col-xl-4">
              <!-- Heading Component-->
              <article class="heading-component">
                <div class="heading-component-inner">
                  <h5 class="heading-component-title">'.$this->Title.'
                  </h5>
                </div>
              </article>
              <!-- Table team-->
              <div class="table-custom-responsive">
                <table class="table-custom table-standings table-classic">
                  <thead><tr>
                      <th colspan="2">Tím</th>
                      <th>B</th>
                    </tr>
                  </thead>
                  <tbody>';
        $index = 0;
        foreach($this->Rows as $row)
            $content .= $this->GetRowHtml_small($row, ++$index);

                  $content .= '</tbody>
                </table>
              </div>
            </div>';
        return $content;
    }
}
?>