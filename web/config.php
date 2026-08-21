<?php
session_start();

define('DB_FILE', 'databases/2026.db');
define('PASSWORD', '123'); // ändern!
define('RESET_PASSWORD', '123'); // ändern!
define('API_TOKEN', '123'); // ändern!

define('RECHNUNGS_PREFIX', 'FLU' . date('y') . '-'); // Präfix für Rechnungsnummern (z.B. FLU26-001)

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
define('SumUp_PRICE_FAKTOR', 1.02);

define('EVENT_NAME', 'Feuerlöscherüberprüfung 2026');

if (!defined('API_MODE')) {
    function getDB() {
        $dbPath = __DIR__ . '/' . DB_FILE; 
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true);

        // 1. Tabelle: loescher
        $db->exec("CREATE TABLE IF NOT EXISTS loescher (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nummer TEXT UNIQUE,
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
            telefon TEXT,
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
            rechnungsnummer TEXT NOT NULL,
            zahlungsart TEXT DEFAULT 'Barzahlung',  -- Barzahlung, Kartenzahlung, SumUp
            bezahlt INTEGER DEFAULT 0, -- 0 = nicht bezahlt, 1 = bezahlt
            rechnung_gedruckt INTEGER DEFAULT 0,
            zeitstempel_gedruckt TEXT,
            zeitstempel_erstellung TEXT,
            sumup_transaction_id TEXT,
            sumup_status TEXT
        );");

        // 3. Tabelle: einstellungen (für DB-spezifische Preise)
        $db->exec("CREATE TABLE IF NOT EXISTS einstellungen (
            key TEXT PRIMARY KEY,
            value TEXT
        );");

        // Standard-Preise in Tabelle eintragen, falls noch nicht vorhanden
        $db->exec("INSERT OR IGNORE INTO einstellungen (key, value) VALUES ('preis_standard', '18');");
        $db->exec("INSERT OR IGNORE INTO einstellungen (key, value) VALUES ('preis_rabatt', '11');");

        return $db;
    }

    // ID erzeugen (001, 002, ...)
    function generateNummer($db) {
        $result = $db->query("SELECT MAX(id) as max_id FROM loescher");
        $row = $result->fetchArray();
        $next = $row['max_id'] + 1;
        return str_pad($next, 3, "0", STR_PAD_LEFT);
    }

    // ==========================================
    // PREISE AUS DER AKTIVEN DATENBANK LADEN
    // ==========================================
    try {
        $_tmpDb = getDB();
        $_resStd = $_tmpDb->querySingle("SELECT value FROM einstellungen WHERE key = 'preis_standard'");
        $_resRab = $_tmpDb->querySingle("SELECT value FROM einstellungen WHERE key = 'preis_rabatt'");

        define('PREIS_STANDARD', $_resStd !== null && $_resStd !== false ? floatval($_resStd) : 18.0);
        define('PREIS_RABATT', $_resRab !== null && $_resRab !== false ? floatval($_resRab) : 11.0);
        define('PREIS_GRATIS', 0.0);
        
        $_tmpDb->close();
        unset($_tmpDb, $_resStd, $_resRab);
    } catch (Exception $e) {
        // Fallback falls Datenbankzugriff beim ersten Laden scheitert
        define('PREIS_STANDARD', 20.0);
        define('PREIS_RABATT', 11.0);
        define('PREIS_GRATIS', 0.0);
    }
}