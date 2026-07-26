<?php

require_once __DIR__ . "/../../config.php";

if(!defined("DB_FILE")) {
    exit("FATAL: DB_FILE not defined in config.php");
}

// Bestimme IMMER den wahren Projekt-Root über test.php-Mechanismus:
$projectRoot = realpath(__DIR__ . '/../../');

$seperator = "";

if(!str_starts_with(DB_FILE, "/")) {
    $seperator = "/";
}

// Absoluter Pfad zur echten DB
$dbPath = $projectRoot . $seperator . DB_FILE;

// Falls Datei fehlt → abbrechen
if (!file_exists($dbPath)) {
    http_response_code(500);
    die("FATAL: Database not found at: " . $dbPath);
}

function getDB() {
    global $dbPath;
    return new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
}