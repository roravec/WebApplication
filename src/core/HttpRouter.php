<?php

class HttpRouter {
    private $subApp;

    public function __construct(SubApplication $subApp)
    {
        $this->subApp = $subApp;
    }

    public function handle($method): string
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Optional: handle method override headers (e.g. X-HTTP-Method-Override)
        if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']))
        {
            $method = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
        }

        $fullPath = $this->subApp->path . DIRECTORY_SEPARATOR . $this->subApp->file;

        if (!file_exists($fullPath))
        {
            http_response_code(500);
            echo "<br>Subapplication file not found: $fullPath";
            return "Subapplication file not found: $fullPath";
        }

        require_once $fullPath;

        if (!class_exists($this->subApp->class))
        {
            http_response_code(500);
            echo "<br>Subapplication class not found: {$this->subApp->class}";
            return "Subapplication class not found: {$this->subApp->class}";
        }

        $instance = new $this->subApp->class();

        if (!($instance instanceof IWebApp))
        {
            http_response_code(500);
            echo "<br>Class does not implement IWebApp";
            return "Class does not implement IWebApp";
        }

        $instance->name = $this->subApp->name;

        return $this->dispatch($instance, $method);
    }

    private function dispatch(IWebApp $app, $method): string
    {
        switch (strtoupper($method))
        {
            case 'GET': return $app->get($this->subApp->rootApplication);
            case 'POST': return $app->post($this->subApp->rootApplication);
            case 'PUT': return $app->put($this->subApp->rootApplication);
            case 'DELETE': return $app->delete($this->subApp->rootApplication);
            default:
                http_response_code(405);
                return "Method Not Allowed";
        }
    }
}
?>