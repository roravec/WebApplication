<?php

class HttpRouter {
    private $subApp;

    public function __construct(SubApplication $subApp)
    {
        $this->subApp = $subApp;
    }

    public function handle($method)
    {
        $fullPath = $this->subApp->path . DIRECTORY_SEPARATOR . $this->subApp->file;

        if (!file_exists($fullPath))
        {
            http_response_code(500);
            echo "Subapplication file not found: $fullPath";
            return;
        }

        require_once $fullPath;

        if (!class_exists($this->subApp->class))
        {
            http_response_code(500);
            echo "Subapplication class not found: {$this->subApp->class}";
            return;
        }

        $instance = new $this->subApp->class();

        if (!($instance instanceof IWebApp))
        {
            http_response_code(500);
            echo "Class does not implement IWebApp";
            return;
        }

        $instance->name = $this->subApp->name;

        $this->dispatch($instance, $method);
    }

    private function dispatch(IWebApp $app, $method)
    {
        switch (strtoupper($method))
        {
            case 'GET': $app->get($this->subApp->rootApplication); break;
            case 'POST': $app->post($this->subApp->rootApplication); break;
            case 'PUT': $app->put($this->subApp->rootApplication); break;
            case 'DELETE': $app->delete($this->subApp->rootApplication); break;
            default:
                http_response_code(405);
                echo "Method Not Allowed";
        }
    }
}
?>