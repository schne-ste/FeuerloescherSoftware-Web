<?php
echo exec("whoami");
echo "<pre>";

echo "API Test gestartet\n\n";

require_once __DIR__ . '/config/database.php';

echo "database.php geladen ✅\n\n";

$db = getDB();
echo "getDB() erfolgreich ✅\n\n";

echo "Echter DB Pfad, den getDB() nutzt:\n";
global $dbPath;
echo $dbPath . "\n\n";

echo "DB existiert: " . (file_exists($dbPath) ? "JA ✅" : "NEIN ❌") . "\n\n";

echo "=== TEST 1: Roher Query ===\n";
$res = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
echo "Query Result Type: " . gettype($res) . "\n";
echo "Query Result: " . var_export($res, true) . "\n\n";

echo "=== TEST 2: Mit Fehlerprüfung ===\n";
if ($res === false) {
    echo "❌ Query fehlgeschlagen!\n";
    echo "Error: " . $db->lastErrorMsg() . "\n";
} else {
    echo "✅ Query OK\n";
    echo "Gefundene Tabellen:\n";
    $found = false;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        echo "- " . $r['name'] . "\n";
        $found = true;
    }
    
    if ($found) {
        echo "\n✅ Tabellen erfolgreich geladen\n";
    } else {
        echo "\n❌ Keine Tabellen gefunden (aber Query funktionierte)\n";
    }
}

echo "</pre>";