<?php
session_start();

define('DB_FILE', 'feuerloescher.db');
define('PASSWORD', '123'); // ändern!
define('RESET_PASSWORD', '123'); // ändern!
define('API_TOKEN', '123'); // ändern!

define('PREIS_STANDARD', 15);
define('PREIS_RABATT', 8);
define('PREIS_GRATIS', 0);

define('RECHNUNGS_PREFIX', 'FLU' . date('y') . '-'); // Prefix für Rechnungsnummern (z.B. FLU26-001)

define('FIRMA_NAME', 'Freiwillige Feuerwehr Wallern');
define('FIRMA_ADRESSE', 'Kienzlstraße 10');
define('FIRMA_PLZORT', '4702 Wallern / Trattnach');
define('FIRMA_WEB', 'https://www.feuerwehr-wallern.at');

define('BANK_NAME', 'Raiffeisenbank Wallern');
define('BANK_IBAN', 'AT1234567890123456');
define('BANK_EMPFAENGER', 'Feuerwehr Wallern');

define('SumUp_AVALIABLE', 'TRUE'); // TRUE oder FALSE
define('SumUp_URL', 'http://10.122.122.4:8080/api/transactions.php');
define('SumUp_API_KEY', 'd0b7062671b7d6c3063701796a7013679f2e332d220f3eea4a203f5110e1ffa2');

if (!defined('API_MODE')) {
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
}