<?php
namespace WebApplication\Http;

use WebApplication\Application;

/**
 * Contract for new-style apps: one method, gets the request, returns a Response.
 * (Old-style apps implement the global IWebApp instead — both are supported.)
 */
interface RequestHandler
{
    public function handle(Request $request, Application $app): Response;
}
