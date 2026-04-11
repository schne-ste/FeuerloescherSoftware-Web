<?php
session_start();

define('DB_FILE', 'feuerloescher.db');
define('PASSWORD', '123'); // ändern!
define('RESET_PASSWORD', '47Wallern02'); // ändern!
define('API_TOKEN', '123'); // ändern!

define('PREIS_STANDARD', 15);
define('PREIS_RABATT', 8);
define('PREIS_GRATIS', 0);

define('RECHNUNGS_PREFIX', 'RFLU26-'); // Prefix für Rechnungsnummern

define('FIRMA_NAME', 'Freiwillige Feuerwehr Wallern');
define('FIRMA_ADRESSE', 'Kienzlstraße 10');
define('FIRMA_PLZORT', '4702 Wallern an der Trattnach');
define('FIRMA_WEB', 'https://feuerwehr-wallern.at');

define('BANK_NAME', 'Raiffeisenbank Wallern');
define('BANK_IBAN', 'AT1234567890123456');
define('BANK_EMPFAENGER', 'Feuerwehr Wallern');




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