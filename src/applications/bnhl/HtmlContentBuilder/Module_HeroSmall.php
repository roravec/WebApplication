<?php

#[\AllowDynamicProperties]
class Module_HeroSmall
{
	/* Public  *****************************/
	public function __construct()
	{
	}
	
	public $title;
	public $AssetsPath = "assets";
	public $image = "/images/hero/hero.jpg";
    // images/bg-breadcrumbs-1-1920x726.jpg
  
	public function GetHTML()
	{
// 		return '
//         <section class="section parallax-container breadcrumbs-wrap" data-parallax-img="/'.$this->AssetsPath.$this->image.'">
//         <div class="parallax-content breadcrumbs-custom context-dark">
//           <div class="container">
//             <h3 class="breadcrumbs-custom-title">'.$this->title.'</h3>
//           </div>
//         </div>
//       </section>
// ';

       $content = '<!-- Swiper-->
      <section class="section swiper-container swiper-slider swiper-classic bg-gray-2" data-loop="false" data-autoplay="4000" data-simulate-touch="false" data-slide-effect="fade">
        <div class="swiper-wrapper">
          <div class="swiper-slide text-center" data-slide-bg="/'.$this->AssetsPath.$this->image.'">
            <div class="overlay"></div>
            <div class="container">
              <div class="row justify-content-center">
                <div class="col-xl-6">
                  <div class="swiper-slide-caption">
                    <h1 data-caption-animate="fadeInUp" data-caption-delay="100">'.$this->title.'</h1>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>';
        return $content;
	}
}

?>