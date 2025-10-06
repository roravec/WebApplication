<?php
require_once "Database.php";
require_once "HttpRouter.php";
require_once "UserAuth.php";
require_once __DIR__ . '/../interfaces/IWebApp.php';

define("URL_BASE", "/");
define("PATH_PAGES", __DIR__ . DIRECTORY_SEPARATOR . "pages" . DIRECTORY_SEPARATOR);

class SubApplication
{
    public $name = 'webapp';
    public $path = '';
    public $folder = '';
    public $file = '';
    public $class = '';
    public $languageCode = 'en';
    public $userAuth = false;
    public $uriSegments = array();
    public $rootApplication = null;

    public function __construct($rootApplication)
    {
        $this->rootApplication = $rootApplication;
    }
}

class Application
{
	public function __construct()
	{
		session_start();
        $appConfig = require __DIR__ . '/../config/ApplicationConfig.php';
        $dbConfig =  require __DIR__ . '/../config/DatabaseConfig.php';

		$this->debug = $appConfig['debugEnabled'] ?? false;

        /** Open database connection */
		$this->database = new Database(
			$dbConfig['dbHost'] ?? 'localhost', 
            $dbConfig['dbName'] ?? 'database', 
            $dbConfig['dbUser'] ?? 'root', 
            $dbConfig['dbPass'] ?? '', 
            $dbConfig['dbPort'] ?? 3306
        );
		
        /** Resolves Application to load and its DB prefix (internally) */
		$this->resolveApp($appConfig);

        if ($this->subApplication->userAuth)
        {
            $this->user = new UserAuth($this);
        }
        $router = new HttpRouter($this->subApplication);
        $router->handle($_SERVER['REQUEST_METHOD']);
	}

	/**
     * Detects the current page path segments from the request URI.
     * @return array<int, string> Path segments (e.g. ['overview', 'details'])
     */
    public function detectPage(): array
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Remove base path if defined
        if (defined('URL_BASE') && substr($path, 0, strlen(URL_BASE)) === URL_BASE)
            {
            $path = substr($path, strlen(URL_BASE));
        }

        // Trim trailing slashes and explode into segments
        $segments = explode('/', rtrim($path, "/\\"));

        // Filter out empty segments (e.g. if path is just "/")
        $segments = array_filter($segments, function($seg)
        {
            return $seg !== '';
        });

        if ($this->isDebugEnabled()) 
        {
            echo "<br><strong>DetectPage:</strong>";
            echo "<br>HTTP_REFERER: " . ($_SERVER['HTTP_REFERER'] ?? '') . "<br>";
            echo "PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? '') . "<br>";
            echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? '') . "<br>";
            echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? '') . "<br>";
            echo "REMOTE_HOST: " . ($_SERVER['REMOTE_HOST'] ?? '') . "<br>";
            echo "REQUEST_URI: " . $uri . "<br>";
        }
        return array_values($segments); // Reindex for consistency
    }

    /**
     * Resolves which application is being called based on the request URL.
     * Supports both production domains and localhost paths.
     * @return string Application name
     */
    private function resolveApp($appConfig): void
    {
        if (!is_object($this->subApplication))
        {
            $this->subApplication = new SubApplication($this);
        }
        $uriSegments = $this->detectPage(); // e.g. ['localhost', 'foo']
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $path = '';

        if ($host !== 'localhost')
        {
            // Use domain name directly (e.g. erma.sk -> erma)
            $domainParts = explode('.', $host);
            $path = $domainParts[0]; // 'erma' from 'erma.sk'
        }
        else
        {
            // Use first segment after localhost (e.g. localhost/app/foo -> 'app')
            $path = $uriSegments[0] ?? $this->subApplication->name;
        }

        //print_r($appConfig);
        if (isset($appConfig[$path]))
        {
            // take the informations from config file
            $this->subApplication->name = $path;
            $dbPrefix = $appConfig[$path]['dbPrefix'] ?? $this->subApplication->name.'_';
            $this->database->setPrefix($dbPrefix);
            $this->subApplication->folder = $appConfig[$path]['folder'] ?? $this->subApplication->folder;
            $this->subApplication->file = $appConfig[$path]['file'] ?? $this->subApplication->file;
            $this->subApplication->class = $appConfig[$path]['class'] ?? $this->subApplication->class;
            $this->subApplication->path = /*require*/ __DIR__ .'/../applications/'. $this->subApplication->folder;
            $this->subApplication->userAuth = $appConfig[$path]['userEnable'] ?? $this->subApplication->userAuth;
            $this->subApplication->uriSegments = $uriSegments;
        }
        else
        {
            // config file is not populated, use default values
        }

        if ($this->isDebugEnabled())
            {
            echo "<br>uriSegments:";
            print_r($uriSegments);
            echo "<br>PATH: " . $path;
            echo "<br>dbPrefix: " . $dbPrefix;
            echo "<br>applicationName: " . $this->subApplication->name;
            echo "<br>applicationFolder: " . $this->subApplication->folder;
            echo "<br>applicationFile: " . $this->subApplication->file;
            echo "<br>applicationClass: " . $this->subApplication->class;
            echo "<br>applicationPath: " . $this->subApplication->path;
            echo "<br>appUserEnable: " . $this->subApplication->userAuth;
            echo "<br><br>";
        }
    }

    /**
     * Retrieves a localized text string from the database.
     * @param string $language
     * @param string $textIdentifier
     * @return string
     */
    public function getTextFromDatabase(string $language, string $textIdentifier): string
    {
        $sql = "SELECT text FROM :text 
                WHERE language = :language 
                AND identifier = :identifier 
                AND appid = :appid 
                LIMIT 1";

        $params = [
            ':text' => $this->database->getPrefix().'text',
            ':language' => $language,
            ':identifier' => $textIdentifier,
            ':appid' => $this->subApplication->name
        ];

        $row = $this->database->fetchOne($sql, $params);

        return $row !== false ? $row['text'] : $textIdentifier;
    }
    public function getDatabase()
    {
        return $this->database;
    }
    public function getApplicationName() : string
    {
        return $this->subApplication->name;
    }
    public function getUser()
    {
        return $this->user;
    }

    public function isDebugEnabled() : bool
    {
        return $this->debug;
    }

    /**
     * Resolves a URL path to a physical HTML file.
     * @param string $path URL path (e.g. /foo/bar)
     * @param string $baseDir Directory where HTML files are stored
     * @return string|null Full file path if found, or null if not
     */
    function resolveHtmlFileFromPath(string $path, string $baseDir): ?string
    {
        // Normalize path: remove leading/trailing slashes
        $trimmed = trim($path, '/');

        // Convert path segments to filename
        $filename = $trimmed === '' ? 'index.html' : str_replace('/', '-', $trimmed) . '.html';

        // Build full file path
        $fullPath = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        // Check if file exists
        return file_exists($fullPath) ? $fullPath : null;
    }

    private $user; // user authorization object
    private $database; // database object
    private $subApplication; // subapplication object
    public $debug = true;
}

?>