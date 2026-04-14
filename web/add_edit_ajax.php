<?php 
require 'config.php';

// Sicherheits-Check: Eingeloggt?
if (!isset($_SESSION['logged_in'])) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(["error" => "Nicht angemeldet"]);
    exit;
}

$db = getDB();
$nummer = $_GET['nummer'] ?? null;

if (!$nummer) {
    header('Content-Type: application/json');
    echo json_encode(["error" => "keine nummer"]);
    exit;
}

$nummerSafe = (int)$nummer;

// Daten abrufen
$result = $db->query("
    SELECT * FROM loescher 
    WHERE CAST(nummer AS INTEGER) = $nummerSafe
");

$entry = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;

header('Content-Type: application/json');
header('Cache-Control: no-cache');

if ($entry) {
    // Gib alle Daten als JSON zurück
    echo json_encode($entry);
} else {
    echo json_encode(["error" => "nicht gefunden"]);
}