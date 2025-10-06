Modular PHP Web Application Router
This project provides a lightweight and extensible HTTP router for PHP applications. It is designed to support modular subapplication dispatching based on URL resolution, with each subapplication implementing a consistent interface for handling HTTP methods.

Features
Supports GET, POST, PUT, DELETE HTTP methods

Dynamically resolves subapplications based on URL segments

Subapplications are defined via structured SubApplication instances

Each subapplication implements a common HttpHandler interface

Compatible with PHP 7.3

Clean separation of routing, resolution, and dispatch logic

Architecture Overview
Code
/core
  └── Router.php
/apps
  ├── blog/
  │   └── BlogApp.php
  ├── shop/
  │   └── ShopApp.php
/config
  └── bootstrap.php
Router: Central dispatcher that loads and invokes subapplications

SubApplication: Encapsulates metadata such as folder, file, class name, and language

HttpHandler Interface: Defines the contract for subapplication behavior

SubApplication Structure
php
class SubApplication {
    public $name = 'blog';
    public $folder = 'apps/blog/';
    public $file = 'BlogApp.php';
    public $class = 'BlogApp';
    public $languageCode = 'en';
    public $userAuth = false;
}
HttpHandler Interface
php
interface HttpHandler {
    public function get();
    public function post();
    public function update();
    public function delete();
}
Example Subapplication
php
class BlogApp implements HttpHandler {
    public function get()    { echo "Viewing blog"; }
    public function post()   { echo "Creating blog"; }
    public function update() { echo "Updating blog"; }
    public function delete() { echo "Deleting blog"; }
}
Router Usage
php
$subApp = new SubApplication();
// Set properties dynamically or via resolver...

$router = new Router($subApp);
$router->handle($_SERVER['REQUEST_METHOD']);
Requirements
PHP 7.3 or higher

No external dependencies

PSR-4 autoloading recommended for larger projects

Design Philosophy
This router is built with the following principles:

Modularity: Each subapplication is self-contained and independently deployable

Traceability: Routes can be linked to functional requirements or project management tools

Scalability: New applications can be added without modifying core logic

Transparency: Routing and dispatch flow is explicit and easy to follow
