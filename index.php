<?php

/*
 * Fallback entry point, used only when the web folder (document root) is this application folder
 * instead of its "public" folder — for example when PHP's test server is started here with
 * "php -S localhost:8000". On Apache/LiteSpeed the .htaccess file sends requests to public/ instead.
 * Best practice is still to point the document root at public/.
 */
require __DIR__ . '/public/index.php';
