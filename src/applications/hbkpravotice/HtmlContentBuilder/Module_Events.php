<?php
#[\AllowDynamicProperties]
class Event
{
	public function __construct($img, $year, $date, $name, $desc, $url)
	{
		$this->Image = $img;
		$this->Year = $year;
		$this->Date = $date;
		$this->Name = $name;
		$this->Description = $desc;
		$this->URL = $url;
	}
	public $Image;
	public $Year;
	public $Date;
	public $Name;
	public $URL;
	public $Description;
}

#[\AllowDynamicProperties]
class Module_Events
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $Events = array();

	public function AddEvent($event)
	{
		$this->Events[] = $event;
	}

	public $SectionName;
	public $NoEvents;

	public $AssetsPath = "assets";
  
	public function GetHTML()
	{
		$content = '
		<section class="upcoming-meetings" id="events">
    <div class="container">
      <div class="row">
        <div class="col-lg-12">
          <div class="section-heading">
            <h2>'.$this->SectionName.'</h2>
          </div>
        </div>
        <div class="col-lg-12">
          <div class="row">';
        if (count($this->Events) > 0)
		{
			foreach ($this->Events as $event)
			{
				$content .= $this->GetEventHTML($event);
			}
		}
		else
		{
			$content .= "<p><h3>".$this->NoEvents."</h3></p>";
		}
          $content .= '</div>
        </div>
      </div>
    </div>
  </section>
';
	return $content;
	}

	public function GetEventHTML($event)
	{
		return '
			<div class="col-lg-6">
              <div class="meeting-item">
                <div class="thumb">
                  <a href="'.$event->URL.'"><img src="'.$this->AssetsPath.'/'.$event->Image.'" alt="'.$event->Name.'"></a>
                </div>
                <div class="down-content">
                  <div class="date">
                    <h6>'.$event->Year.'<span>'.$event->Date.'</span></h6>
                  </div>
                  <a href="meeting-details.html"><h4>'.$event->Name.'</h4></a>
                  <p>'.$event->Description.'</p>
                </div>
              </div>
            </div>
		';
	}
}

?>