<?php
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    exit;
}

$db = getDB();

$nummer = $_GET['nummer'] ?? null;

if (!$nummer) {
    echo json_encode(["error" => "keine nummer"]);
    exit;
}

$nummerSafe = (int)$nummer;

$result = $db->query("
    SELECT * FROM loescher 
    WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
");

$eintrag = $result->fetchArray();

header('Content-Type: application/json');
echo json_encode($eintrag);