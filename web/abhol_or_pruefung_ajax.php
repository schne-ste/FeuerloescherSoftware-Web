<?php
require 'config.php';

// Sitzungsprüfung für Sicherheit
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
    WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
");

$eintrag = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;

header('Content-Type: application/json');

if ($eintrag) {
    // Gibt alle Felder (nummer, name, bezahlt, defekt, active, geprueft, abgeholt, info) zurück
    echo json_encode($eintrag);
} else {
    echo json_encode(["error" => "nicht gefunden"]);
}