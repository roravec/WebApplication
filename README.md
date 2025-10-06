Modular PHP Web Application Router
This project provides a lightweight and extensible HTTP router for PHP applications. It is designed to support modular subapplication dispatching based on URL resolution, with each subapplication implementing a consistent interface for handling HTTP methods.

Features
Supports GET, POST, PUT, DELETE HTTP methods

Dynamically resolves subapplications based on URL segments

Subapplications are defined via structured SubApplication instances

Each subapplication implements a common HttpHandler interface

Compatible with PHP 7.3

Clean separation of routing, resolution, and dispatch logic

SubApplication: Encapsulates metadata such as folder, file, class name, and language

WebApp Interface: Defines the contract for subapplication behavior


No external dependencies

Modularity: Each subapplication is self-contained and independently deployable

Traceability: Routes can be linked to functional requirements or project management tools

Scalability: New applications can be added without modifying core logic

Transparency: Routing and dispatch flow is explicit and easy to follow
