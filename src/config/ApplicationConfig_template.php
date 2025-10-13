<?php
return [
    'branchmanager' => [                // incoming URL
        'folder'  =>    'appFolder',    // folder, where the subapplication is located
        'file'  =>      'App.php',      // file name where the root class is defined
        'class'  =>     'App',          // class name (implements interface IWebApp)
        'dbPrefix' =>   'app_',         // database table prefix for the subapplication
        'clientAuth' => 'api',           // 'user' or 'api' for client authentication type
    ],
    'debugEnabled' => true
];
?>