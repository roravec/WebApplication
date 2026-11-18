<?php
/**
 * Contract of an application (global, no namespace — existing apps implement it as `IWebApp`).
 *
 * Each method receives the root WebApplication\Application and returns the response body.
 * Set a status code with http_response_code() or, better, $app->response()->setStatus().
 *
 * New apps may instead implement WebApplication\Http\RequestHandler (returns a Response object).
 */
interface IWebApp
{
    public function get($rootApplication): string;
    public function post($rootApplication): string;
    public function put($rootApplication): string;
    public function delete($rootApplication): string;
    public function methodNotAllowed(string $method, $rootApplication): string;
}
