<?php
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    die("Nicht autorisiert");
}

$db = getDB();
// Wir holen alle Rechnungsnummern aus der Datenbank
$query = "SELECT rechnungsnummer FROM rechnungen";
$result = $db->query($query);

$zip = new ZipArchive();
$zipName = 'Feuerlöscher_Rechnungen_' . date('Y-m-d_H-i') . '.zip';
$pdfFolder = __DIR__ . '/_Rechnungen/'; // Dein definierter Ordner

if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    
    $filesAdded = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Rechnungsnummer bereinigen wie im Generierungsscript
        $cleanNr = preg_replace('/[^a-zA-Z0-9_-]/', '', $row['rechnungsnummer']);
        $fileName = 'Rechnung_' . $cleanNr . '.pdf';
        $filePath = $pdfFolder . $fileName;

        if (file_exists($filePath)) {
            // Die Datei wird im ZIP ohne den Ordnerpfad gespeichert
            $zip->addFile($filePath, $fileName);
            $filesAdded++;
        }
    }

    $zip->close();

    if ($filesAdded > 0) {
        // ZIP an den Browser senden
        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename=' . $zipName);
        header('Content-Length: ' . filesize($zipName));
        
        // Cache leeren
        ob_clean();
        flush();
        
        readfile($zipName);
        
        // Temporäre ZIP-Datei auf dem Server löschen
        unlink($zipName);
        exit;
    } else {
        echo "<script>alert('Keine PDF-Dateien im Ordner _Rechnungen gefunden!'); window.history.back();</script>";
    }
} else {
    die("Fehler: ZIP-Archiv konnte nicht erstellt werden.");
}