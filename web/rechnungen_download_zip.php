<?php
ob_start();
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// 1. Hilfsfunktion für identische Dateinamen-Generierung
function cleanWindowsFilename($rnr, $kundenname)
{
    $cleanRnr = preg_replace('/[^a-zA-Z0-9_-]/', '', $rnr);
    $umlautMap = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'];
    $cleanName = strtr($kundenname, $umlautMap);
    $cleanName = str_replace(' ', '_', $cleanName);
    $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '', $cleanName);
    $cleanName = preg_replace('/_+/', '_', $cleanName);
    $cleanName = trim($cleanName, '_');

    return !empty($cleanName) ? $cleanRnr . '_' . $cleanName : $cleanRnr;
}

$db = getDB();
$res = $db->query("SELECT rechnungsnummer, name FROM rechnungen");

// 2. Zielordner ermitteln (inklusive DB-Unterordner)
$dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME);
$pdfFolder = __DIR__ . '/_Rechnungen/' . $dbNameOnly;

if (!is_dir($pdfFolder)) {
    die("Fehler: Rechnungsordner existiert noch nicht!");
}

// 3. ZipArchive prüfen
if (!class_exists('ZipArchive')) {
    die("Fehler: PHP-Erweiterung 'ZipArchive' (php_zip) ist auf dem Server nicht aktiviert!");
}

$zip = new ZipArchive();
$zipFileName = sys_get_temp_dir() . '/Rechnungen_' . $dbNameOnly . '_' . date('Y-m-d_H-i-s') . '.zip';

if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Fehler: ZIP-Datei konnte nicht erstellt werden.");
}

$filesAdded = 0;

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $filenameOnly = 'Rechnung_' . cleanWindowsFilename($row['rechnungsnummer'], $row['name']) . '.pdf';
    $filePath = $pdfFolder . '/' . $filenameOnly;

    if (file_exists($filePath)) {
        $zip->addFile($filePath, $filenameOnly);
        $filesAdded++;
    }
}

$zip->close();

if ($filesAdded === 0) {
    die("Fehler: Keine passenden PDF-Rechnungen im Ordner gefunden.");
}

// 4. Clean Buffer & Header für Download senden
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="Rechnungen_' . $dbNameOnly . '_' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zipFileName));
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipFileName);
@unlink($zipFileName); // Temporäre Datei löschen
exit;