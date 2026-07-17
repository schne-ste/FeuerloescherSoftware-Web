<?php
session_start();

define('DB_FILE', 'databases/2025.db');
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
define('SumUp_URL', 'http://10.122.122.66:8080/api/transactions.php');
define('SumUp_API_KEY', 'd0b7062671b7d6c3063701796a7013679f2e332d220f3eea4a203f5110e1ffa2');
define('SumUp_PRICE_FAKTOR', 1.020);

if (!defined('API_MODE')) {
    function getDB() {
        // Pfad dynamisch aus der definierten Konstante DB_FILE laden
        $dbPath = __DIR__ . '/' . DB_FILE; 
        
        // Verbindung zur SQLite3-Datenbank herstellen
        $db = new SQLite3($dbPath);
        
        // Fehlerberichterstattung aktivieren
        $db->enableExceptions(true);

        // ==========================================
        // AUTOMATISCHE TABELLENERSTELLUNG (Schema)
        // ==========================================
        
        // 1. Tabelle: loescher
        $db->exec("CREATE TABLE IF NOT EXISTS loescher (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nummer INTEGER NOT NULL,
            name TEXT NOT NULL,
            typ TEXT,
            preis REAL DEFAULT 0,
            loeschertyp TEXT,
            menge TEXT,
            einheit TEXT,
            etikett_gedruckt INTEGER DEFAULT 0,
            abholschein_gedruckt INTEGER DEFAULT 0,
            bezahlt INTEGER DEFAULT 0,
            geprueft INTEGER DEFAULT 0,
            abgeholt INTEGER DEFAULT 0,
            defekt INTEGER DEFAULT 0,
            active INTEGER DEFAULT 1,
            info TEXT,
            zeitstempel TEXT
        );");

        // 2. Tabelle: rechnungen
        $db->exec("CREATE TABLE IF NOT EXISTS rechnungen (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            anrede TEXT,
            name TEXT NOT NULL,
            adresse TEXT,
            plz TEXT,
            ort TEXT,
            anzahl_loescher INTEGER DEFAULT 1,
            preis_pro_loescher REAL DEFAULT 0.0,
            rechnungsnummer TEXT UNIQUE NOT NULL,
            zahlungsart TEXT,
            bezahlt INTEGER DEFAULT 0,
            rechnung_gedruckt INTEGER DEFAULT 0,
            zeitstempel_gedruckt TEXT,
            zeitstempel_erstellung TEXT
        );");

        return $db;
    }

    // ID erzeugen (001, 002, ...)
    function generateNummer($db) {
        $result = $db->query("SELECT MAX(id) as max_id FROM loescher");
        $row = $result->fetchArray();
        $next = $row['max_id'] + 1;
        return str_pad($next, 3, "0", STR_PAD_LEFT);
    }
}