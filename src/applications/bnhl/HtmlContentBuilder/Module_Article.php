<?php

#[\AllowDynamicProperties]
class Module_Article
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $title;
    public $summary = "";
    public $content = "";
    // images/bg-breadcrumbs-1-1920x726.jpg
  
	public function GetHTML()
	{
       $content = '<div class="blog-post">
                <h3 class="blog-post-title">'.$this->title.'</h3>

                <div class="blog-post-author-quote">
                  <p>'.$this->summary.'</p>
                </div>

                <div class="blog-post-content">
                    <p>'.$this->content.'</p>
                </div>
              </div>';
        return $content;
	}
}

?>