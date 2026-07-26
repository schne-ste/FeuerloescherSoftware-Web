<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ============ Fehler-Handling muss GANZ OBEN stehen! ============
require_once __DIR__ . '/helpers/ErrorLog.php';

set_exception_handler(function($e) {
    ErrorLog::write("Unhandled Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(["error" => "Internal Server Error", "message" => $e->getMessage()]);
    exit;
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ErrorLog::write("PHP Error [$errno]: $errstr in $errfile:$errline");
});

// ================================================================

define('API_MODE', true);
require_once __DIR__ . '/../config.php';

// API Token Überprüfung
$token = $_GET['token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if ($token !== API_TOKEN) {
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && strpos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $bearer = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
        if ($bearer === API_TOKEN) {
            $token = $bearer;
        }
    }
}

if ($token !== API_TOKEN) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Support für direkte PUT/PATCH/POST-Requests ohne Rewrite
if (empty($_GET['route']) && isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '') {
    $_GET['route'] = $_SERVER['PATH_INFO'];
}

require_once __DIR__ . '/helpers/Router.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Input.php';
require_once __DIR__ . '/config/database.php';
// Router initialisieren
$router = new Router();

// ===== LOESCHER =====
$router->get('/loescher', 'LoescherController@index');
$router->get('/loescher/{nummer}', 'LoescherController@show');

// HEALTHCHECK
$router->get('/health', 'HealthController@status');

$router->post('/loescher', 'LoescherController@store');
$router->put('/loescher/{nummer}', 'LoescherController@update');
$router->patch('/loescher/{nummer}', 'LoescherController@patch');

// Druckservice
$router->put('/loescher/{nummer}/abholschein', 'PrintController@abholschein');
$router->put('/loescher/{nummer}/etikett', 'PrintController@etikett');

// ===== RECHNUNGEN =====
$router->get('/rechnungen', 'RechnungenController@index');
$router->get('/rechnungen/{nummer}', 'RechnungenController@show');
$router->post('/rechnungen', 'RechnungenController@store');
$router->patch('/rechnungen/{nummer}', 'RechnungenController@patch');

// Druckservice
$router->put('/rechnungen/{nummer}/gedruckt', 'PrintController@rechnungGedruckt');

// ===== CONFIG =====
$router->get('/config', 'ConfigController@defines');

// Route ausführen
$route = $_GET['route'] ?? null;
if (is_array($route)) {
    $route = $route[0] ?? null;
}

if (empty($route)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);

    $apiBase = '/api';
    if (strpos($path, $apiBase) === 0) {
        $path = substr($path, strlen($apiBase));
    }

    if ($path === '/index.php' || $path === '/index.php/') {
        $path = '/';
    }

    if ($path !== '/' && strpos($path, '/index.php') === 0) {
        $path = substr($path, strlen('/index.php'));
    }

    $route = '/' . trim($path, '/');
    if ($route === '//') {
        $route = '/';
    }
}

$router->run($route);