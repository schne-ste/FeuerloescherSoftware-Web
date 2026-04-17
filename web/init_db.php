<?php

if (basename($_SERVER["SCRIPT_NAME"]) === "init_db.php") {
    http_response_code(404);
    exit("Not found");
}

$dbFile = 'feuerloescher.db';

// Alte DB löschen
if (file_exists($dbFile)) {
    unlink($dbFile);
}

$db = new SQLite3($dbFile);

// Tabelle für Löscher
$db->exec("
CREATE TABLE loescher (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nummer TEXT UNIQUE,
    name TEXT,
    zeitstempel TEXT,
    typ TEXT,
    preis REAL,
    loeschertyp TEXT,
    menge REAL,
    einheit TEXT,
    etikett_gedruckt INTEGER,
    abholschein_gedruckt INTEGER,
    bezahlt INTEGER,
    geprueft INTEGER,
    abgeholt INTEGER,
    defekt INTEGER,
    info TEXT,
    active INTEGER
);
");

// Tabelle für Rechnungen
$db->exec("
CREATE TABLE rechnungen (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    anrede TEXT,                         -- Herr, Frau, Firma, etc.
    name TEXT,
    adresse TEXT,
    plz TEXT,
    ort TEXT,
    anzahl_loescher INTEGER,
    preis_pro_loescher REAL,
    zeitstempel_erstellung TEXT,
    rechnung_gedruckt INTEGER DEFAULT 0,
    zeitstempel_gedruckt TEXT,
    rechnungsnummer TEXT,
    zahlungsart TEXT DEFAULT 'Barzahlung',  -- Barzahlung, Kartenzahlung, SumUp
    bezahlt INTEGER DEFAULT 0 ,            -- 0 = nicht bezahlt, 1 = bezahlt
    sumup_transaction_id TEXT,
    sumup_status TEXT
);
");

//echo "Datenbank wurde neu erstellt!";