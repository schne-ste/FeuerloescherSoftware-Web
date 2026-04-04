<?php
session_start();

define('DB_FILE', 'feuerloescher.db');
define('PASSWORD', '123'); // ändern!
define('API_TOKEN', '123'); // ändern!

define('PREIS_VOLLER', 15);
define('PREIS_RABATT', 8);
define('PREIS_GRATIS', 0);


function getDB() {
    return new SQLite3(DB_FILE);
}

// ID erzeugen (001, 002, ...)
function generateNummer($db) {
    $result = $db->query("SELECT MAX(id) as max_id FROM loescher");
    $row = $result->fetchArray();
    $next = $row['max_id'] + 1;
    return str_pad($next, 3, "0", STR_PAD_LEFT);
}