<?php
// Interface definition
interface IWebApp
{
    /**
     * Summary of get
     * @param mixed $rootApplication
     * @return void
     */
    public function get($rootApplication): string;

    /**
     * Summary of post
     * @param mixed $rootApplication
     * @return void
     */
    public function post($rootApplication): string;

    /**
     * Summary of put
     * @param mixed $rootApplication
     * @return void
     */
    public function put($rootApplication): string;

    /**
     * Summary of delete
     * @param mixed $rootApplication
     * @return void
     */
    public function delete($rootApplication): string;

    /**
     * Summary of methodNotAllowed
     * @param string $method
     * @param mixed $rootApplication
     * @return void
     */
    public function methodNotAllowed(string $method, $rootApplication): string;
}
?>