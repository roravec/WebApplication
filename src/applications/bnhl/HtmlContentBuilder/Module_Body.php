<?php
#[\AllowDynamicProperties]
class Module_Body
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
    public $content = "";
    public $rightContent = "";
    public $fullPage = false;
  
	public function GetHTML()
	{
        $retcontent = '
        <section class="section section-md bg-gray-100">
        <div class="container">
          <div class="row row-50">';
        if ($this->fullPage)
            $retcontent .='<div class="col-lg-12">';
        else
            $retcontent .='
            <div class="col-lg-8">';

            $retcontent .= $this->content;
            $retcontent .='</div>';
                
            if (!$this->fullPage)
            {
                $retcontent .='<div class="col-lg-4">
                    '.$this->rightContent.'
                </div>';
            }
        $retcontent .='
                </div>
                </div>
            </section>
        ';
		return $retcontent;
	}
}

?>