<?php

// Bestimme IMMER den wahren Projekt-Root über test.php-Mechanismus:
$projectRoot = realpath(__DIR__ . '/../../');

// Absoluter Pfad zur echten DB
$dbPath = $projectRoot . '/feuerloescher.db';

// Falls Datei fehlt → abbrechen
if (!file_exists($dbPath)) {
    http_response_code(500);
    die("FATAL: Database not found at: " . $dbPath);
}

function getDB() {
    global $dbPath;
    return new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
}