<?php

#[\AllowDynamicProperties]
class Module_GenericContent
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $Headline="";
	public $AssetsPath = "assets";
	public $NoticeText = "";

	public $TopContent = "";
	public $Style = '';
	public $Content = array();

	public function AddContent($addcontent, $colSize, $headline="")
	{
		$content = '
		';
		$content .= '<div class="col-lg-'.$colSize.'">';
		if ($headline != '')
		{
			$content .= '<h4>'.$headline.'</h4>';
		}
		$content .= $addcontent. '</div>';
		$this->Content[] = $content;
	}
  
	public function GetHTML()
	{
		$content = '
		<section class="upcoming-meetings" '.$this->Style.'>
    <div class="container">
      	<div class="row">
		';
		if ($this->Headline != '')
		{
		$content .= '
        	<div class="col-lg-12">
          		<div class="section-heading">
		  			<div class="row">
		  				<h3>'.$this->Headline.'</h3>
					</div>
          		</div>';
				if ($this->NoticeText != "")
				{
					$content .= '<p class="notice">Notice: '.$this->NoticeText.'</p>';
				}
				
				
				$content .= '</div>';
		}
				$content .= '
        <div class="col-lg-12">
			<div class="row">';
			
			if ($this->TopContent != '')
			{
				$content .= '<div class="content">'.$this->TopContent.'</div>';
			}

		foreach($this->Content as $microcontent)
		{
			$content .= $microcontent;
		}

		$content .= '
        	</div>
      	</div>
    </div>
  </section>
  <script>
var coll = document.getElementsByClassName("collapsible");
var i;

for (i = 0; i < coll.length; i++) {
  coll[i].addEventListener("click", function() {
    this.classList.toggle("active");
    var content = this.nextElementSibling;
    if (content.style.maxHeight){
      content.style.maxHeight = null;
    } else {
      content.style.maxHeight = content.scrollHeight + "px";
    }
  });
}
</script>
';
	return $content;
	}
}
?>