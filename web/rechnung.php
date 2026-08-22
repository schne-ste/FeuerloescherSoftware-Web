<?php
ob_start();
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$db = getDB();
$successMessage = '';
$errorMessage = '';
if (isset($_GET['success'])) {
    $successMessage = "&#9989; Rechnung gespeichert und PDF generiert!";
}
$searchResults = [];
$editEntry = null;

// =====================
// HILFSFUNKTION FÜR DATEINAMEN
// =====================
function cleanWindowsFilename($rnr, $kundenname)
{
    // 1. Rechnungsnummer säubern
    $cleanRnr = preg_replace('/[^a-zA-Z0-9_-]/', '', $rnr);

    // 2. Kundenname: Umlaute und scharfes S ersetzen
    $umlautMap = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'];
    $cleanName = strtr($kundenname, $umlautMap);

    // 3. Leerzeichen durch Unterstriche ersetzen
    $cleanName = str_replace(' ', '_', $cleanName);

    // 4. Windows-Sonderzeichen entfernen (\ / : * ? " < > |) sowie ungewollte Zeichen
    $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '', $cleanName);

    // 5. Mehrfache Unterstriche reduzieren
    $cleanName = preg_replace('/_+/', '_', $cleanName);
    $cleanName = trim($cleanName, '_');

    // Rückgabe mit _ getrennt. Wenn Name leer ist, nur die Nummer.
    return !empty($cleanName) ? $cleanRnr . '_' . $cleanName : $cleanRnr;
}

// =====================
// PREISE
// =====================
$preise = [
    'Standard' => PREIS_STANDARD,
    'Rabatt' => PREIS_RABATT,
    'Gratis' => PREIS_GRATIS
];

// =====================
// NAMEN LADEN
// =====================
$namen = [];
$res = $db->query("SELECT DISTINCT TRIM(name) as name FROM loescher WHERE name IS NOT NULL AND TRIM(name) != '' ORDER BY nummer DESC");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $namen[] = $row['name'];
}

// =====================
// RECHNUNGSLISTE FÜR SUCHE (DATALIST)
// =====================
$rechnungsListe = [];
$resR = $db->query("SELECT name, rechnungsnummer FROM rechnungen ORDER BY zeitstempel_erstellung DESC");
while ($rowR = $resR->fetchArray(SQLITE3_ASSOC)) {
    // Kombiniert Nummer und Name für die Anzeige
    $rechnungsListe[] = $rowR['rechnungsnummer'] . " - " . $rowR['name'];
}
// Duplikate entfernen falls nötig
$rechnungsListe = array_unique($rechnungsListe);

// =====================
// NÄCHSTE RECHNUNGSNUMMER BERECHNEN
// =====================
$lastNr = $db->querySingle("
    SELECT rechnungsnummer FROM rechnungen
    WHERE rechnungsnummer LIKE '" . RECHNUNGS_PREFIX . "%' 
    ORDER BY id DESC LIMIT 1
");
if ($lastNr) {
    $number = intval(str_replace(RECHNUNGS_PREFIX, '', $lastNr)) + 1;
} else {
    $number = 1;
}
$nextRechnungsnummer = RECHNUNGS_PREFIX . str_pad($number, 4, '0', STR_PAD_LEFT);

// =====================
// AJAX: Daten neu laden
// =====================
if (isset($_GET['action']) && $_GET['action'] === 'reload_data' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $row = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);

    // Wir bereiten die Antwort vor
    if ($row) {
        $row['status_html'] = ($row['rechnung_gedruckt'] == 1) ? '&#9989;' : '&#10060;';
        // Dateiname für den Link generieren
        $dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME); // Holt z.B. 'Test1' aus 'databases/Test1.db'
        $row['pdf_url'] = '_Rechnungen/' . $dbNameOnly . '/Rechnung_' . cleanWindowsFilename($row['rechnungsnummer'], $row['name']) . '.pdf';
    }

    header('Content-Type: application/json');
    echo json_encode($row);
    exit;
}

// =====================
// AJAX: Anzahl ALLER Löscher zum Namen ermitteln (samt Preisstufen)
// =====================
if (isset($_GET['action']) && $_GET['action'] === 'get_loescher_count' && !empty($_GET['name'])) {
    $searchName = mb_strtolower(trim($_GET['name']));
    $inklDefekt = isset($_GET['inkl_defekt']) && $_GET['inkl_defekt'] === '1';

    $defektCondition = $inklDefekt ? "" : "AND (defekt = 0 OR defekt = '0')";

    // Zählt Löscher (aktiv = 1)
    $stmt = $db->prepare("
        SELECT COUNT(*) AS anzahl 
        FROM loescher 
        WHERE LOWER(TRIM(name)) = :name 
          AND (active = 1 OR active = '1')
          $defektCondition
    ");
    $stmt->bindValue(':name', $searchName, SQLITE3_TEXT);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    // Zählt Löscher gruppiert nach Typ
    $stmtTypen = $db->prepare("
        SELECT typ, COUNT(*) AS anzahl 
        FROM loescher 
        WHERE LOWER(TRIM(name)) = :name 
          AND (active = 1 OR active = '1')
          $defektCondition
        GROUP BY typ
    ");
    $stmtTypen->bindValue(':name', $searchName, SQLITE3_TEXT);
    $resTypen = $stmtTypen->execute();
    
    $typen = [];
    while ($rowT = $resTypen->fetchArray(SQLITE3_ASSOC)) {
        $typen[$rowT['typ']] = (int)$rowT['anzahl'];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'anzahl' => $res ? (int)$res['anzahl'] : 0,
        'typen' => $typen
    ]);
    exit;
}

// =====================
// AJAX: Aktuelle Namenliste für Autocomplete neu laden
// =====================
if (isset($_GET['action']) && $_GET['action'] === 'get_namen_list') {
    $aktuellesNamenArray = [];
    $resNamen = $db->query("SELECT DISTINCT TRIM(name) as name FROM loescher WHERE name IS NOT NULL AND TRIM(name) != '' ORDER BY nummer DESC");
    while ($rowN = $resNamen->fetchArray(SQLITE3_ASSOC)) {
        $aktuellesNamenArray[] = $rowN['name'];
    }

    header('Content-Type: application/json');
    echo json_encode($aktuellesNamenArray);
    exit;
}

// =====================
// RECHNUNG AUS MASKE LÖSCHEN (STORNO)
// =====================
if (isset($_POST['delete_rechnung_form']) && !empty($_POST['edit_id'])) {
    $deleteId = (int) $_POST['edit_id'];

    $stmt = $db->prepare("SELECT rechnungsnummer, name FROM rechnungen WHERE id = :id");
    $stmt->bindValue(':id', $deleteId);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($res) {
        $rnr = $res['rechnungsnummer'];
        $kname = $res['name'];
        $dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME);
        $pdfPath = __DIR__ . '/_Rechnungen/' . $dbNameOnly . '/Rechnung_' . cleanWindowsFilename($rnr, $kname) . '.pdf';

        $delStmt = $db->prepare("DELETE FROM rechnungen WHERE id = :id");
        $delStmt->bindValue(':id', $deleteId);
        if ($delStmt->execute()) {
            if (file_exists($pdfPath)) {
                @unlink($pdfPath);
            }
            header("Location: rechnungen_anzeigen.php");
            exit;
        }
    }
}

// =====================
// RECHNUNG NACHDRUCKEN
// =====================
if (isset($_POST['reprint_rechnung']) && !empty($_POST['edit_id'])) {
    $id = (int) $_POST['edit_id'];
    $stmt = $db->prepare("UPDATE rechnungen SET rechnung_gedruckt = 0, zeitstempel_gedruckt = NULL WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $successMessage = "&#128424; Rechnung wird erneut gedruckt (BON)!";

    $editEntry = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// AUTOMATISCHES LADEN NACH SPEICHERN
// =====================
if (isset($_GET['id']) && empty($editEntry)) {
    $id = (int) $_GET['id'];
    $editEntry = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// SUCHE
// =====================
if (isset($_POST['suche'])) {
    $input = trim($_POST['suchfeld'] ?? '');

    if ($input !== '') {
        if (strpos($input, ' - ') !== false) {
            $parts = explode(' - ', $input);
            $searchVal = trim($parts[0]);
        } else {
            $searchVal = $input;
        }

        $safe = $db->escapeString($searchVal);

        $result = $db->query("
            SELECT * FROM rechnungen
            WHERE rechnungsnummer = '$safe'
            OR name LIKE '%$safe%'
            OR rechnungsnummer LIKE '%$safe%'
        ");

        $rows = [];
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }

        if (count($rows) === 1) {
            $editEntry = $rows[0];
        } elseif (count($rows) > 1) {
            $searchResults = $rows;
        } else {
            $errorMessage = "❌ Keine Rechnung gefunden!";
        }
    }
}

// =====================
// AUSWAHL
// =====================
if (isset($_POST['select_entry'])) {
    $id = (int) $_POST['selected_entry'];
    $editEntry = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// SPEICHERN / UPDATE
// =====================
if (isset($_POST['save_rechnung'])) {

    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;

    if (empty($_POST['edit_id'])) {
        $rechnungsnummer = $nextRechnungsnummer;
    } else {
        $rechnungsnummer = $_POST['rechnungsnummer'];
    }

    if (!empty($_POST['edit_id'])) {
        $lastId = (int) $_POST['edit_id'];
        
        // 1. Aktuelle Daten VOR dem Update aus der DB holen (für alten Namen & Druckstatus-Vergleich)
        $currentData = $db->query("SELECT * FROM rechnungen WHERE id = $lastId")->fetchArray(SQLITE3_ASSOC);

        $typ = $_POST['typ'] ?? '';
        $neuerPreis = $preise[$typ] ?? 0;

        $hatKritischeAenderung = (
            (int) $_POST['anzahl'] !== (int) $currentData['anzahl_loescher'] ||
            (float) $neuerPreis !== (float) $currentData['preis_pro_loescher'] ||
            $_POST['rechnungsnummer'] !== $currentData['rechnungsnummer']
        );

        $resetDruck = $hatKritischeAenderung ? 0 : $currentData['rechnung_gedruckt'];
        $resetZeit = $hatKritischeAenderung ? NULL : $currentData['zeitstempel_gedruckt'];

        // Alte PDF-Datei löschen
        $oldFilename = __DIR__ . '/_Rechnungen/' . pathinfo(DB_FILE, PATHINFO_FILENAME) . '/Rechnung_' . cleanWindowsFilename($currentData['rechnungsnummer'], $currentData['name']) . '.pdf';
        if (file_exists($oldFilename)) {
            @unlink($oldFilename);
        }

        // 2. Namensänderung synchronisieren: Alter Name kommt garantiert aus $currentData!
        $alterName = trim($currentData['name']);
        $neuerName = trim($_POST['name']);

        if (!empty($alterName) && !empty($neuerName) && strcasecmp($alterName, $neuerName) !== 0) {
            $updateLoescherStmt = $db->prepare("
                UPDATE loescher 
                SET name = :neuerName 
                WHERE LOWER(TRIM(name)) = :alterName 
                   OR name = :alterNameExact
            ");
            $updateLoescherStmt->bindValue(':neuerName', $neuerName, SQLITE3_TEXT);
            $updateLoescherStmt->bindValue(':alterName', mb_strtolower($alterName), SQLITE3_TEXT);
            $updateLoescherStmt->bindValue(':alterNameExact', $alterName, SQLITE3_TEXT);
            $updateLoescherStmt->execute();
        }

        // 3. Erst JETZT die Rechnungs-Tabelle aktualisieren
        $stmt = $db->prepare("
            UPDATE rechnungen SET
                anrede = :anrede,
                name = :name,
                adresse = :adresse,
                plz = :plz,
                ort = :ort,
                anzahl_loescher = :anzahl,
                preis_pro_loescher = :preis,
                rechnungsnummer = :rnr,
                zahlungsart = :zahlungsart,
                bezahlt = :bezahlt,
                rechnung_gedruckt = :gedruckt,
                zeitstempel_gedruckt = :z_gedruckt,
                verrechnen_defekt = :verrechnen_defekt
            WHERE id = :id
        ");

        $stmt->bindValue(':id', $lastId);
        $stmt->bindValue(':gedruckt', $resetDruck);
        $stmt->bindValue(':z_gedruckt', $resetZeit);

        if ($hatKritischeAenderung) {
            $successMessage = "&#9989; Rechnung aktualisiert (Preise geändert -> Druckstatus zurückgesetzt)!";
        } else {
            $successMessage = "&#9989; Rechnung aktualisiert (Druckstatus beibehalten).";
        }
    } else {
        $stmt = $db->prepare("
            INSERT INTO rechnungen (
                anrede, name, adresse, plz, ort,
                anzahl_loescher, preis_pro_loescher,
                zeitstempel_erstellung, rechnungsnummer, zahlungsart, bezahlt, verrechnen_defekt
            ) VALUES (
                :anrede, :name, :adresse, :plz, :ort,
                :anzahl, :preis, :zeit, :rnr, :zahlungsart, :bezahlt, :verrechnen_defekt
            )
        ");
        $stmt->bindValue(':zeit', date('Y-m-d H:i:s'));
        $successMessage = "&#9989; Rechnung gespeichert!";
    }

    $stmt->bindValue(':anrede', $_POST['anrede']);
    $stmt->bindValue(':name', $_POST['name']);
    $stmt->bindValue(':adresse', $_POST['adresse']);
    $stmt->bindValue(':plz', $_POST['plz']);
    $stmt->bindValue(':ort', $_POST['ort']);
    $stmt->bindValue(':anzahl', (int) $_POST['anzahl']);
    $stmt->bindValue(':preis', $preis);
    $stmt->bindValue(':rnr', $rechnungsnummer);
    $stmt->bindValue(':zahlungsart', $_POST['zahlungsart'] ?? 'Barzahlung');
    $stmt->bindValue(':bezahlt', isset($_POST['bezahlt']) ? 1 : 0);
    $stmt->bindValue(':verrechnen_defekt', isset($_POST['inkl_defekt']) ? 1 : 0, SQLITE3_INTEGER);
    $stmt->execute();

    // --- Löscher-Bezahlstatus synchron zur Rechnung aktualisieren ---
    $kundeName = trim($_POST['name']);
    if (!empty($kundeName)) {
        $istBezahlt = (isset($_POST['bezahlt']) && $_POST['bezahlt'] == '1') ? 1 : 0;
        
        $updateLoescherBezahltStmt = $db->prepare("
            UPDATE loescher 
            SET bezahlt = :bezahltval 
            WHERE LOWER(TRIM(name)) = :name 
              AND (active = 1 OR active = '1')
        ");
        $updateLoescherBezahltStmt->bindValue(':bezahltval', $istBezahlt, SQLITE3_INTEGER);
        $updateLoescherBezahltStmt->bindValue(':name', mb_strtolower($kundeName), SQLITE3_TEXT);
        $updateLoescherBezahltStmt->execute();
    }

    if (empty($_POST['edit_id'])) {
        $lastId = $db->lastInsertRowID();
    }

    // =====================
    // TCPDF PDF GENERIEREN
    // =====================
    require_once('tcpdf/tcpdf.php');

    class MYPDF extends TCPDF
    {
        public function Footer()
        {
            $this->SetY(-25);
            $this->SetFont('helvetica', 'I', 8);

            $this->Line(15, $this->GetY(), 195, $this->GetY());
            $this->Ln(2);

            $firma = FIRMA_NAME . " | " . FIRMA_ADRESSE . " | " . FIRMA_PLZORT . " | " . FIRMA_WEB;
            $this->Cell(0, 4, $firma, 0, 1, 'C');

            $this->SetFont('helvetica', 'B', 8);
            $this->Cell(0, 4, 'Vielen Dank für Ihren Besuch!', 0, 0, 'C');

            $info = "Erstellt mit Feuerlöscher-Software | © " . FIRMA_NAME . " - Schneebauer " . date("Y");
            $this->SetY(-5);
            $this->SetFont('helvetica', 'I', 5);
            $this->Cell(0, 4, $info, 0, 1, 'C');
        }
    }

    $pdf = new MYPDF();
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor(FIRMA_NAME);
    $pdf->SetTitle('Rechnung ' . $rechnungsnummer);
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);
    $pdf->AddPage();

    $logoPath = __DIR__ . '/images/Logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 145, 15, 50);
    }

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetXY(15, 42);
    $pdf->Cell(0, 5, FIRMA_NAME . " • " . FIRMA_ADRESSE . " • " . FIRMA_PLZORT, 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 11);
    $leftX = 15;
    $leftWidth = 100;
    $pdf->SetXY($leftX, 50);

    if (!empty($_POST['anrede']) && $_POST['anrede'] !== '-') {
        $pdf->MultiCell($leftWidth, 6, $_POST['anrede'], 0, 'L');
    }

    $pdf->SetX($leftX);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->MultiCell($leftWidth, 6, $_POST['name'], 0, 'L');

    $pdf->SetFont('helvetica', '', 11);
    if (!empty($_POST['adresse'])) {
        $pdf->SetX($leftX);
        $pdf->MultiCell($leftWidth, 6, $_POST['adresse'], 0, 'L');
    }

    $pdf->SetX($leftX);
    $pdf->MultiCell($leftWidth, 6, $_POST['plz'] . ' ' . $_POST['ort'], 0, 'L');

    $rightX = 130;
    $pdf->SetXY($rightX, 50);

    $pdf->Cell(40, 6, 'Datum:', 0, 0, 'R');
    $pdf->Cell(30, 6, date('d.m.Y'), 0, 1, 'L');

    $pdf->SetX($rightX);
    $pdf->Cell(40, 6, 'Rechnungs-Nr.:', 0, 0, 'R');
    $pdf->Cell(30, 6, $rechnungsnummer, 0, 1, 'L');

    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, "Rechnung", 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Ln(10);

    $searchNameDb = mb_strtolower(trim($_POST['name']));
    $inklDefekt = isset($_POST['inkl_defekt']) ? true : false;
    $defektCondition = $inklDefekt ? "" : "AND (defekt = 0 OR defekt = '0')";

    $stmtLoescher = $db->prepare("
        SELECT typ, COUNT(*) AS anzahl 
        FROM loescher 
        WHERE LOWER(TRIM(name)) = :name 
          AND (active = 1 OR active = '1') 
          $defektCondition
        GROUP BY typ
    ");
    $stmtLoescher->bindValue(':name', $searchNameDb, SQLITE3_TEXT);
    $resLoescher = $stmtLoescher->execute();

    $dbLoescherTypen = [];
    while ($rowL = $resLoescher->fetchArray(SQLITE3_ASSOC)) {
        $dbLoescherTypen[$rowL['typ']] = (int)$rowL['anzahl'];
    }

    $w_bez = "55%";
    $w_menge = "15%";
    $w_einzel = "15%";
    $w_gesamt = "15%";

    $tbl = '
    <table border="0" cellpadding="6" cellspacing="0" width="100%">
        <thead>
            <tr style="background-color:#eeeeee; font-weight:bold;">
                <th width="' . $w_bez . '" style="border-bottom: 1px solid #333;">Bezeichnung</th>
                <th width="' . $w_menge . '" align="center" style="border-bottom: 1px solid #333;">Menge</th>
                <th width="' . $w_einzel . '" align="right" style="border-bottom: 1px solid #333;">Einzel</th>
                <th width="' . $w_gesamt . '" align="right" style="border-bottom: 1px solid #333;">Gesamt</th>
            </tr>
        </thead>
        <tbody>';

    $gesamtsumme = 0;

    if (!empty($dbLoescherTypen)) {
        foreach ($preise as $pTyp => $pPreis) {
            $mengeStk = $dbLoescherTypen[$pTyp] ?? 0;
            if ($mengeStk > 0) {
                $zeilenGesamt = $mengeStk * $pPreis;
                $gesamtsumme += $zeilenGesamt;
                $tbl .= '
            <tr>
                <td width="' . $w_bez . '" style="border-bottom: 0.5px solid #ccc;">Fachmännische Überprüfung von tragbaren Feuerlöschern gemäß ÖNORM F 1053 (' . htmlspecialchars($pTyp) . ')</td>
                <td width="' . $w_menge . '" align="center" style="border-bottom: 0.5px solid #ccc;">' . $mengeStk . ' Stk.</td>
                <td width="' . $w_einzel . '" align="right" style="border-bottom: 0.5px solid #ccc;">' . number_format($pPreis, 2, ',', '.') . ' €</td>
                <td width="' . $w_gesamt . '" align="right" style="border-bottom: 0.5px solid #ccc;">' . number_format($zeilenGesamt, 2, ',', '.') . ' €</td>
            </tr>';
            }
        }
    } else {
        $anzahl = (int) $_POST['anzahl'];
        $einzelpreis = floatval($preis);
        $gesamtsumme = $anzahl * $einzelpreis;
        $tbl .= '
            <tr>
                <td width="' . $w_bez . '" style="border-bottom: 0.5px solid #ccc;">Fachmännische Überprüfung von tragbaren Feuerlöschern gemäß ÖNORM F 1053</td>
                <td width="' . $w_menge . '" align="center" style="border-bottom: 0.5px solid #ccc;">' . $anzahl . ' Stk.</td>
                <td width="' . $w_einzel . '" align="right" style="border-bottom: 0.5px solid #ccc;">' . number_format($einzelpreis, 2, ',', '.') . ' €</td>
                <td width="' . $w_gesamt . '" align="right" style="border-bottom: 0.5px solid #ccc;">' . number_format($gesamtsumme, 2, ',', '.') . ' €</td>
            </tr>';
    }

    $tbl .= '
            <tr style="font-size: 12pt; font-weight:bold;">
                <td colspan="3" align="right">Gesamtsumme:</td>
                <td align="right">' . number_format($gesamtsumme, 2, ',', '.') . ' €</td>
            </tr>
        </tbody>
    </table>';

    $pdf->writeHTML($tbl, true, false, true, false, '');

    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 9);
    $hinweis = "Hinweis: Als Körperschaft öffentlichen Rechts ist die Freiwillige Feuerwehr gemäß § 2 Abs. 5 UStG nicht umsatzsteuerpflichtig. Der ausgewiesene Betrag entspricht dem Bruttobetrag (0% MwSt).";
    $pdf->MultiCell(0, 5, $hinweis, 0, 'L');

    $pdf->Ln(5);
    $zahlungsart = $_POST['zahlungsart'] ?? 'Barzahlung';
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Zahlungsart: ' . $zahlungsart, 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    if ($zahlungsart === 'Barzahlung') {
        $pdf->Cell(0, 6, 'Betrag dankend in Bar erhalten.', 0, 1, 'L');
    } elseif ($zahlungsart === 'SumUp' || $zahlungsart === 'Kartenzahlung') {
        $pdf->Cell(0, 6, 'Betrag dankend erhalten.', 0, 1, 'L');
    } else {
        $text = "Bitte überweisen Sie den Betrag innerhalb von 14 Tagen ohne Abzug an folgendes Konto:\n"
            . "Empfänger: " . BANK_EMPFAENGER . "\n"
            . "Bank: " . BANK_NAME . " | IBAN: " . BANK_IBAN;
        $pdf->MultiCell(0, 5, $text, 0, 'L');
    }

    $dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME);
    $folder = __DIR__ . '/_Rechnungen/' . $dbNameOnly;

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    $filename = $folder . '/Rechnung_' . cleanWindowsFilename($rechnungsnummer, $_POST['name']) . '.pdf';
    $pdf->Output($filename, 'F');

    header("Location: " . $_SERVER['PHP_SELF'] . "?success=1&id=" . $lastId);
    exit;
}

$currentTyp = '';
if ($editEntry && isset($editEntry['preis_pro_loescher'])) {
    foreach ($preise as $k => $v) {
        if ($v == $editEntry['preis_pro_loescher']) {
            $currentTyp = $k;
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <style>
        .highlight {
            box-shadow: 0 .125rem .25rem rgba(0, 0, 0, .35) !important;
        }

        .alert {
            position: fixed !important;
            top: 100px !important;
            right: 20px !important;
            z-index: 10000000 !important;
        }

        #preisAnzahlLabel {
            display: inline-block;
            margin-top: 32px; /* Schiebt den Text exakt auf die Höhe des Eingabefelds links */
            line-height: 1.5;
        }

        #nameInfo {
            display: none;
        }
    </style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>&#128293; Feuerlöscher Software</title>
    <link rel="icon" href="./images/Feuerlöscher.ico" type="image/x-icon">
    <link rel="shortcut icon" href="./images/Feuerlöscher.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
                &#128293; Feuerlöscher Software - &#128179; Rechnung
            </span>

            <div class="d-flex gap-2">
                <a href="rechnungen_anzeigen.php" class="btn btn-outline-info btn-sm">Rechnungsübersicht</a>
                <a href="index.php" class="btn btn-outline-light btn-sm">&#127968; Start</a>
                <!--<a href="?logout=1" class="btn btn-danger btn-sm">Abmelden</a>-->
            </div>
        </div>
    </nav>

    <div class="container mt-5">

        <?php if ($successMessage): ?>
            <div class="alert alert-success">
                <?= $successMessage ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger">
                <?= $errorMessage ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="post" class="card p-3 mb-4">
            <label class="form-label">&#128269; Name oder Rechnungsnummer</label>
            <div class="d-flex gap-2">
                <input type="text" name="suchfeld" class="form-control" autocomplete="off" list="sucheListe">
                <button name="suche" class="btn btn-primary">Suchen</button>
            </div>

            <datalist id="sucheListe">
                <?php foreach ($rechnungsListe as $item): ?>
                    <option value="<?= htmlspecialchars($item) ?>">
                    <?php endforeach; ?>
            </datalist>
        </form>

        <?php if ($searchResults): ?>
            <form method="post" class="card p-3 mb-4">
                <label>Mehrere Treffer:</label>
                <select name="selected_entry" class="form-select mb-2">
                    <?php foreach ($searchResults as $r): ?>
                        <option value="<?= $r['id'] ?>">
                            <?= htmlspecialchars($r['name']) ?> - <?= $r['rechnungsnummer'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button name="select_entry" class="btn btn-primary">Laden</button>
            </form>
        <?php endif; ?>

        <form method="post" id="rechnungsForm" class="card p-4">
            <input type="hidden" name="edit_id" id="edit_id_field" value="<?= $editEntry['id'] ?? '' ?>">

            <!-- Toast rechts oben-->
            <?php if (empty($successMessage)): ?>
                <?php if (!empty($editEntry['id'])): ?>
                    <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
                        <span class="fs-5 me-2">&#9998;</span>
                        <div>
                            <strong>Rechnung wird bearbeitet</strong> (Rechnungs-Nr: <strong><?= htmlspecialchars($editEntry['rechnungsnummer']) ?></strong>)
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info d-flex align-items-center mb-3" role="alert">
                        <span class="fs-5 me-2">&#10010;</span>
                        <div>
                            <strong>Neue Rechnung erstellen</strong>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <label class="form-label text-muted small m-0">&#128196; Rechnungsnummer</label>
                    
                    <!-- Exaktes Badge rechts oben wie vorher -->
                    <?php if (!empty($editEntry['id'])): ?>
                        <span class="badge bg-warning text-dark fs-6 py-2 px-3">&#9998; Rechnung wird bearbeitet</span>
                    <?php else: ?>
                        <span class="badge bg-success fs-6 py-2 px-3">Rechnung wird neu erstellt</span>
                    <?php endif; ?>
                </div>

                <!-- Rechnungsnummer: Grau bei Neuerstellung, Schwarz bei Bearbeiten -->
                <div class="fw-bold fs-4 m-1 <?= !empty($editEntry['id']) ? 'text-dark' : 'text-muted' ?>">
                    <?= htmlspecialchars($editEntry['rechnungsnummer'] ?? $nextRechnungsnummer) ?>
                </div>
                
                <input type="hidden" name="rechnungsnummer" value="<?= htmlspecialchars($editEntry['rechnungsnummer'] ?? $nextRechnungsnummer) ?>">
                <hr> 
            </div>

                           
            <div class="mb-3">
                <label class="form-label">&#128100; Anrede</label>
                <select name="anrede" class="form-select">
                    <option <?= ($editEntry['anrede'] ?? '') == '' ? 'selected' : '' ?>>-</option>
                    <option <?= ($editEntry['anrede'] ?? '') == 'Herr' ? 'selected' : '' ?>>Herr</option>
                    <option <?= ($editEntry['anrede'] ?? '') == 'Frau' ? 'selected' : '' ?>>Frau</option>
                    <option <?= ($editEntry['anrede'] ?? '') == 'Firma' ? 'selected' : '' ?>>Firma</option>
                    <option <?= ($editEntry['anrede'] ?? '') == 'Verein' ? 'selected' : '' ?>>Verein</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">&#128100; Name</label>
                <div id="nameInfo" class="form-text text-muted mb-2" style="font-style: italic; display: none;">Name kann nach dem Erstellen geändert werden - Löschernamen werden mit aktualisiert!</div>
                <input list="namenListe" name="name" class="form-control highlight" autocomplete="off"
                    value="<?= htmlspecialchars($editEntry['name'] ?? '') ?>" required>
                <datalist id="namenListe">
                    <?php foreach ($namen as $n): ?>
                        <option value="<?= htmlspecialchars($n) ?>">
                        <?php endforeach; ?>
                </datalist>
            </div>

            <div class="mb-3">
                <label class="form-label">&#127968; Adresse</label>
                <input type="text" name="adresse" class="form-control"
                    value="<?= htmlspecialchars($editEntry['adresse'] ?? '') ?>">
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">&#128205; PLZ</label>
                    <input type="text" name="plz" class="form-control"
                        value="<?= htmlspecialchars($editEntry['plz'] ?? '4702') ?>">
                </div>
                <div class="col-md-8 mb-3">
                    <label class="form-label">&#127970; Ort</label>
                    <input type="text" name="ort" class="form-control"
                        value="<?= htmlspecialchars($editEntry['ort'] ?? 'Wallern an der Trattnach') ?>">
                </div>
            </div>

            <div class="row align-items-center">
                <div class="col-md-6 mb-3">
                    <label class="form-label mb-0">&#128293; Anzahl Löscher</label>
                    <input type="number" name="anzahl" class="form-control highlight mt-1"
                        value="<?= $editEntry['anzahl_loescher'] ?? 1 ?>">
                </div>

                <div class="col-md-6 mb-3">
                    <!-- Label und gefundener Text wandern nun in dieselbe Zeile -->
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label mb-0" id="preisAnzahlLabel">&#128176; Preis je Löscher</label>
                    </div>
                    <!-- Container für das Standard-Dropdown + Preisfeld (wird bei Treffern ausgeblendet) -->
                    <div id="standardPreisContainer">
                        <select name="typ" id="typSelect" class="form-select">
                            <?php foreach ($preise as $k => $v): ?>
                                <option value="<?= $k ?>" <?= ($currentTyp == $k) ? 'selected' : '' ?>><?= $k ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="preisField" class="form-control mt-1" disabled>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">&#128179; Zahlungsart</label>
                <div id="zahlungsartInfo" class="form-text text-muted mb-2" style="font-style: italic; display: none;"></div>
                <select name="zahlungsart" id="zahlungsartSelect" class="form-select highlight">
                    <?php
                    $selectedZahlungsart = isset($editEntry['zahlungsart']) ? $editEntry['zahlungsart'] : 'Barzahlung';
                    ?>
                    <option value="Barzahlung" <?= ($selectedZahlungsart == 'Barzahlung') ? 'selected' : '' ?>>Barzahlung
                    </option>
                    <option value="Kartenzahlung" <?= ($selectedZahlungsart == 'Kartenzahlung') ? 'selected' : '' ?>>
                        Kartenzahlung</option>

                    <?php if (defined('SumUp_AVALIABLE') && SumUp_AVALIABLE === 'TRUE'): ?>
                        <option value="SumUp" <?= ($selectedZahlungsart == 'SumUp') ? 'selected' : '' ?>>SumUp</option>
                    <?php endif; ?>

                    <option value="Überweisung" <?= ($selectedZahlungsart == 'Überweisung') ? 'selected' : '' ?>>Überweisung
                    </option>
                </select>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" name="bezahlt" value="1" class="form-check-input" id="bezahltCheck"
                    <?= ($editEntry['bezahlt'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" name="inkl_defekt" id="inklDefektCheck" value="1" class="form-check-input"
                    <?= (!empty($editEntry['verrechnen_defekt']) || isset($_POST['inkl_defekt']) || isset($_GET['inkl_defekt'])) ? 'checked' : '' ?>>
                <label class="form-check-label" for="inklDefektCheck">
                    &#9888;&#65039; Defekte Löscher auf der Rechnung mitverrechnen
                </label>
            </div>

            <div class="modal fade" id="changeCalculatorModal" data-bs-backdrop="static" tabindex="-1"
                aria-labelledby="changeCalculatorModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title" id="changeCalculatorModalLabel">&#128181; Wechselgeldrechner</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                aria-label="Schließen"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Zu zahlen (Gesamt):</label>
                                <input type="text" id="calcTotalAmount"
                                    class="form-control form-control-lg fw-bold text-danger" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="calcGivenAmount">Erhalten vom Kunden (€):</label>
                                <input type="number" id="calcGivenAmount" class="form-control form-control-lg"
                                    step="0.01" min="0" placeholder="0,00" autocomplete="off">
                            </div>
                            <div class="p-3 bg-light rounded border text-center">
                                <span class="fs-5 d-block text-muted">Rückgeld:</span>
                                <span id="calcReturnAmount" class="fs-2 fw-bold text-success">0,00 €</span>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                            <button type="button" id="confirmChangeCalc" class="btn btn-success">Betrag passt &
                                Speichern</button>
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            

            <?php if (!is_null($editEntry) && $editEntry) { ?>

                <p>
                    &#128424; Bon Gedruckt: <span
                        id="druckStatusAnzeige"><?= ($editEntry['rechnung_gedruckt'] ?? 0) ? '&#9989;' : '&#10060;' ?></span>
                </p>

                <div class="d-flex gap-2 mb-3 flex-wrap">

                    <?php
                    $dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME);
                    $safePdfName = cleanWindowsFilename($editEntry['rechnungsnummer'], $editEntry['name']);
                    ?>
                    <button type="submit" name="reprint_rechnung" class="btn btn-outline-secondary">Bon nachdrucken (Rechnung und Beleg)</button>
                    <a id="openPdf" href="_Rechnungen/<?= $dbNameOnly ?>/Rechnung_<?= $safePdfName ?>.pdf" target="_blank"
                        class="btn btn-info">
                        &#128196; PDF Rechnung öffnen
                    </a>
                    <button type="button" id="reloadData" class="btn btn-secondary">&#128260; Daten neu laden</button>

                    <button type="submit" name="delete_rechnung_form" class="btn btn-danger"
                        onclick="return confirm('Möchten Sie diese Rechnung wirklich unwiderruflich stornieren und löschen? Das PDF wird ebenfalls gelöscht.');">
                        &#128465; Rechnung stornieren
                    </button>

                    <?php if (($editEntry['zahlungsart'] ?? '') === 'SumUp' && defined('SumUp_AVALIABLE') && SumUp_AVALIABLE === 'TRUE' && $editEntry['bezahlt'] === 0): ?>
                        <button type="button" id="sumupBtn" class="btn btn-primary">&#128179; SumUp Zahlung starten</button>
                    <?php endif; ?>

                </div>

                <input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">

            <?php } else { ?>
                <a id="openPdf" href="#" style="display:none;" class="btn btn-info mb-3">&#128196; PDF öffnen</a>
            <?php } ?>

            <button class="btn btn-success w-100" name="save_rechnung" id="submitFormBtn">&#128190; Speichern</button>
            <button type="button" class="btn btn-outline-secondary w-100 mt-2" id="clearForm">&#128465; Formular
                leeren</button>

        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const preisMap = <?= json_encode($preise) ?>;
        const nextRechnungsnummer = "<?= $nextRechnungsnummer ?>";
        let currentLoescherTypen = null;

        function updatePreis() {
            const typSelect = document.getElementById('typSelect');
            const preisField = document.getElementById('preisField');
            if (!typSelect || !preisField) return 0;

            const typ = typSelect.value;
            const preis = preisMap[typ] || 0;
            preisField.value = preis.toFixed(2).replace('.', ',') + " €";
            return preis;
        }

        function getGesamtBetrag() {
            if (currentLoescherTypen && Object.keys(currentLoescherTypen).length > 0) {
                let gesamt = 0;
                for (const [typ, count] of Object.entries(currentLoescherTypen)) {
                    const pVal = preisMap[typ] !== undefined ? preisMap[typ] : 0;
                    gesamt += count * pVal;
                }
                return gesamt;
            }

            const anzahlInput = document.querySelector('[name="anzahl"]');
            const anzahl = anzahlInput ? parseInt(anzahlInput.value) || 0 : 0;
            const einzelpreis = updatePreis();
            return anzahl * einzelpreis;
        }

        document.addEventListener('DOMContentLoaded', function () {
            const changeModalEl = document.getElementById('changeCalculatorModal');
            const changeModal = new bootstrap.Modal(changeModalEl);

            const rechnungsForm = document.getElementById('rechnungsForm');
            const bezahltCheck = document.getElementById('bezahltCheck');
            const calcTotalAmount = document.getElementById('calcTotalAmount');
            const calcGivenAmount = document.getElementById('calcGivenAmount');
            const calcReturnAmount = document.getElementById('calcReturnAmount');
            const confirmChangeCalc = document.getElementById('confirmChangeCalc');

            let bypassModal = false;

            function openWechselgeldRechner() {
                const gesamt = getGesamtBetrag();
                calcTotalAmount.value = gesamt.toFixed(2).replace('.', ',') + " €";
                calcGivenAmount.value = '';
                calcReturnAmount.innerText = "0,00 €";
                calcReturnAmount.className = "fs-2 fw-bold text-success";
                changeModal.show();
                changeModalEl.addEventListener('shown.bs.modal', function () {
                    calcGivenAmount.focus();
                }, { once: true });
            }

            rechnungsForm.addEventListener('submit', function (e) {
                const activeSubmitter = e.submitter ? e.submitter.name : '';
                const zahlungsart = document.getElementById('zahlungsartSelect').value;

                if (bezahltCheck.checked && zahlungsart === 'Barzahlung' && !bypassModal && activeSubmitter === 'save_rechnung') {
                    e.preventDefault();
                    openWechselgeldRechner();
                }
            });

            confirmChangeCalc.addEventListener('click', function () {
                bypassModal = true;
                changeModal.hide();

                const hiddenSubmit = document.createElement('input');
                hiddenSubmit.type = 'hidden';
                hiddenSubmit.name = 'save_rechnung';
                hiddenSubmit.value = '1';
                rechnungsForm.appendChild(hiddenSubmit);

                rechnungsForm.submit();
            });

            calcGivenAmount.addEventListener('input', function () {
                const gesamt = getGesamtBetrag();
                const gegeben = parseFloat(this.value) || 0;
                const wechselgeld = gegeben - gesamt;

                if (wechselgeld < 0) {
                    calcReturnAmount.innerText = "Noch offen: " + Math.abs(wechselgeld).toFixed(2).replace('.', ',') + " €";
                    calcReturnAmount.className = "fs-2 fw-bold text-danger";
                } else {
                    calcReturnAmount.innerText = wechselgeld.toFixed(2).replace('.', ',') + " €";
                    calcReturnAmount.className = "fs-2 fw-bold text-success";
                }
            });

            const zahlungsartSelect = document.getElementById('zahlungsartSelect');
            const zahlungsartInfo = document.getElementById('zahlungsartInfo');
            const editIdField = document.getElementById('edit_id_field');

            function updateZahlungsartInfo(isInitialLoad = false) {
                if (!zahlungsartSelect || !zahlungsartInfo) return;
                
                const val = zahlungsartSelect.value;
                const sumupBtn = document.getElementById('sumupBtn');

                if (val === 'SumUp') {
                    if (sumupBtn) sumupBtn.style.display = 'block';
                } else {
                    if (sumupBtn) sumupBtn.style.display = 'none';
                }

                let infoText = '';
                if (val === 'SumUp') {
                    infoText = 'ℹ️ Hinweis: Der Button "SumUp Zahlung starten" erscheint nach dem Speichern unten. Der Status "Bezahlt" setzt sich nach erfolgreicher Zahlung automatisch.';
                } else if (val === 'Barzahlung') {
                    infoText = 'ℹ️ Hinweis: Bei aktivierter Option "Bezahlt" öffnet sich beim Speichern automatisch der Wechselgeldrechner.';
                } else if (val === 'Überweisung') {
                    infoText = 'ℹ️ Hinweis: Bankdaten werden auf der Rechnung angezeigt. KEINE automatische Kontrolle der Zahlung!';
                } else if (val === 'Kartenzahlung') {
                    infoText = 'ℹ️ Hinweis: Es wird vermerkt, dass der Betrag dankend per Karte erhalten wurde.';
                }

                if (infoText !== '') {
                    zahlungsartInfo.innerHTML = infoText;
                    zahlungsartInfo.style.display = 'block';
                } else {
                    zahlungsartInfo.style.display = 'none';
                }

                if (bezahltCheck && (!editIdField.value || !isInitialLoad)) {
                    if (val === 'Barzahlung' || val === 'Kartenzahlung') {
                        bezahltCheck.checked = true;
                    } else if (val === 'SumUp' || val === 'Überweisung') {
                        bezahltCheck.checked = false;
                    }
                }
            }

            if (zahlungsartSelect) {
                zahlungsartSelect.addEventListener('change', () => updateZahlungsartInfo(false));
                updateZahlungsartInfo(true);
            }

            const typSelectEl = document.getElementById('typSelect');
            if (typSelectEl) {
                typSelectEl.addEventListener('change', updatePreis);
            }
            const anzahlInput = document.querySelector('[name="anzahl"]');
            if (anzahlInput) anzahlInput.addEventListener('input', updatePreis);
            updatePreis();

            const clearBtn = document.getElementById('clearForm');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                    window.location.href = cleanUrl;
                });
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === "Escape" || e.keyCode === 27) {
                    if (!changeModalEl.classList.contains('show')) {
                        const clearBtn = document.getElementById('clearForm');
                        if (clearBtn) {
                            e.preventDefault();
                            clearBtn.click();
                        }
                    }
                }
            });

            const sumupBtn = document.getElementById('sumupBtn');
            if (sumupBtn) {
                sumupBtn.addEventListener('click', () => {
                    const editId = document.querySelector('[name="edit_id"]').value;
                    if (!editId) return alert("Bitte Rechnung zuerst speichern!");

                    const width = 400;
                    const height = 500;
                    const left = (window.innerWidth / 2) - (width / 2);
                    const top = (window.innerHeight / 2) - (height / 2);

                    // Fenster öffnen
                    const sumupWindow = window.open(
                        `sumup.php?rechnung_id=${editId}`,
                        'SumUpTerminal',
                        `width=${width},height=${height},left=${left},top=${top},toolbar=no,location=no`
                    );

                    // Überwachung: Sobald das SumUp-Fenster geschlossen wird (Egal ob Erfolg, Abbruch oder Fehler)
                    const checkWindowClosed = setInterval(() => {
                        if (sumupWindow && sumupWindow.closed) {
                            clearInterval(checkWindowClosed);
                            
                            // Triggere das Neuladen der Rechnungsdaten via AJAX
                            const reloadBtn = document.getElementById('reloadData');
                            if (reloadBtn) {
                                reloadBtn.click();
                            }
                        }
                    }, 800);
                });
            }

            const reloadBtn = document.getElementById('reloadData');
            if (reloadBtn) {
                reloadBtn.addEventListener('click', () => {
                    const editId = document.querySelector('[name="edit_id"]').value;
                    if (!editId) return alert('Keine Rechnung ausgewählt!');

                    reloadBtn.disabled = true;
                    reloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Lade...';

                    fetch(`?action=reload_data&id=${editId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data) {
                                const statusSpan = document.getElementById('druckStatusAnzeige');
                                if (statusSpan) statusSpan.innerHTML = data.status_html;

                                const pdfLink = document.getElementById('openPdf');
                                if (pdfLink) pdfLink.href = data.pdf_url;

                                // Bezahlt-Häkchen synchronisieren
                                const bezahltCheck = document.getElementById('bezahltCheck');
                                if (bezahltCheck) {
                                    bezahltCheck.checked = (parseInt(data.bezahlt) === 1);
                                }

                                // SumUp-Button ausblenden, wenn bezahlt
                                const sumupBtn = document.getElementById('sumupBtn');
                                if (sumupBtn) {
                                    if (parseInt(data.bezahlt) === 1) {
                                        sumupBtn.style.display = 'none';
                                    } else {
                                        sumupBtn.style.display = 'block';
                                    }
                                }

                                console.log("Daten erfolgreich via AJAX aktualisiert.");
                            }
                        })
                        .catch(err => {
                            console.error("Fehler beim AJAX-Reload:", err);
                            alert("Fehler beim Laden der Daten.");
                        })
                        .finally(() => {
                            reloadBtn.disabled = false;
                            reloadBtn.innerHTML = '&#128260; Daten neu laden';
                        });
                });
            }

            if (window.location.search.includes('success=1')) {
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState({}, document.title, cleanUrl);
            }

            const alertBoxes = document.querySelectorAll('.alert');
            alertBoxes.forEach(alertBox => {
                setTimeout(() => {
                    alertBox.style.transition = "opacity 0.6s ease, transform 0.6s ease";
                    alertBox.style.opacity = "0";
                    alertBox.style.transform = "translateY(-20px)";
                    setTimeout(() => alertBox.remove(), 600);
                }, 2000);
            });

            const nameInput = document.querySelector('input[name="name"]');
            const anzahlInputBill = document.querySelector('input[name="anzahl"]');
            const preisAnzahlLabel = document.getElementById('preisAnzahlLabel');
            const standardPreisContainer = document.getElementById('standardPreisContainer');
            const nameInfoBox = document.getElementById('nameInfo');
            const datalistNamen = document.getElementById('namenListe');
            const inklDefektCheck = document.getElementById('inklDefektCheck');

            // Funktion zum dynamischen Nachladen der Namensliste
            function updateNamenDatalist() {
                fetch('?action=get_namen_list')
                    .then(response => {
                        if (!response.ok) throw new Error('Netzwerk-Fehler');
                        return response.json();
                    })
                    .then(namen => {
                        if (Array.isArray(namen) && datalistNamen) {
                            // Bestehende Optionen leeren
                            datalistNamen.innerHTML = '';
                            
                            // Neue Optionen einfügen
                            namen.forEach(name => {
                                const option = document.createElement('option');
                                option.value = name;
                                datalistNamen.appendChild(option);
                            });
                        }
                    })
                    .catch(err => console.error('Fehler beim Aktualisieren der Namensliste:', err));
            }

            if (nameInput) {
                // Namensliste jedes Mal neu laden, wenn der Benutzer in das Name-Feld klickt/fokussiert
                nameInput.addEventListener('focus', updateNamenDatalist);
            }

            if (nameInput && anzahlInputBill) {
                function fetchPaidCount() {
                    const val = nameInput.value.trim();
                    if (!val) {
                        currentLoescherTypen = null;
                        if (standardPreisContainer) standardPreisContainer.style.display = 'block';
                        if (preisAnzahlLabel) preisAnzahlLabel.innerHTML = '&#128176; Preis je Löscher';
                        anzahlInputBill.readOnly = false;
                        if (nameInfoBox) nameInfoBox.style.display = 'none';
                        return;
                    }

                    const inklDefektVal = (inklDefektCheck && inklDefektCheck.checked) ? '1' : '0';

                    fetch(`?action=get_loescher_count&name=${encodeURIComponent(val)}&inkl_defekt=${inklDefektVal}`)
                        .then(res => {
                            if (!res.ok) throw new Error(`HTTP-Fehler! Status: ${res.status}`);
                            return res.json();
                        })
                        .then(data => {
                            if (data && typeof data.anzahl !== 'undefined' && data.anzahl > 0) {
                                anzahlInputBill.value = data.anzahl;
                                if (typeof updatePreis === 'function') {
                                    updatePreis();
                                }
                            }
                            if (data && data.typen && Object.keys(data.typen).length > 0) {
                                currentLoescherTypen = data.typen;
                                let labelParts = [];
                                for (const [typ, count] of Object.entries(data.typen)) {
                                    const pVal = preisMap[typ] !== undefined ? preisMap[typ] : 0;
                                    const pFormatiert = pVal.toFixed(2).replace('.', ',') + '€';
                                    labelParts.push(`${count}x ${typ} (${pFormatiert})`);
                                }
                                if (preisAnzahlLabel) {
                                    preisAnzahlLabel.innerHTML = '&#128176; ' + labelParts.join(' | ');
                                }
                                if (standardPreisContainer) {
                                    standardPreisContainer.style.display = 'none';
                                }
                                anzahlInputBill.readOnly = true;

                                if (nameInfoBox) nameInfoBox.style.display = 'block';

                            } else {
                                currentLoescherTypen = null;
                                if (preisAnzahlLabel) {
                                    preisAnzahlLabel.innerHTML = '&#128176; Preis je Löscher';
                                }
                                if (standardPreisContainer) {
                                    standardPreisContainer.style.display = 'block';
                                }
                                anzahlInputBill.readOnly = false;

                                if (nameInfoBox) nameInfoBox.style.display = 'none';
                            }
                        })
                        .catch(err => {
                            console.error('Fehler beim Abrufen der Löscher-Anzahl:', err);
                            currentLoescherTypen = null;
                            if (standardPreisContainer) standardPreisContainer.style.display = 'block';
                            if (nameInfoBox) nameInfoBox.style.display = 'none';
                        });
                }

                if (nameInput.value.trim() !== '') {
                    fetchPaidCount();
                }

                // Event-Listener NUR registrieren, wenn wir eine NEUE Rechnung erstellen!
                const editIdVal = document.getElementById('edit_id_field') ? document.getElementById('edit_id_field').value : '';
                
                if (!editIdVal) {
                    // Wird nur beim Auswählen/Abschließen eines Namens bei neuen Rechnungen ausgelöst
                    nameInput.addEventListener('change', fetchPaidCount);
                }

                if (inklDefektCheck) {
                    inklDefektCheck.addEventListener('change', fetchPaidCount);
                }

                //Nur einkommentieren, wenn Name immer neu geldaden werden soll - sonst auskommentiert lassen!
                /*nameInput.addEventListener('change', fetchPaidCount);
                nameInput.addEventListener('blur', fetchPaidCount);  // beim Verlassen des feldes

                nameInput.addEventListener('input', function () {
                    const val = this.value.trim();

                    // Nur zurücksetzen, wenn der Name VOLLSTÄNDIG gelöscht wurde
                    if (val === '') {
                        currentLoescherTypen = null;
                        if (standardPreisContainer) standardPreisContainer.style.display = 'block';
                        if (preisAnzahlLabel) preisAnzahlLabel.innerHTML = '&#128176; Preis je Löscher';
                        anzahlInputBill.readOnly = false;
                        if (nameInfoBox) nameInfoBox.style.display = 'none';
                        return;
                    }

                    // Wenn der getippte Name exakt einer Datalist-Option entspricht, direkt neu laden
                    const datalist = document.getElementById('namenListe');
                    if (datalist) {
                        const options = Array.from(datalist.options).map(opt => opt.value.trim());
                        if (options.includes(val)) {
                            fetchPaidCount();
                        }
                    }
                });*/
            }

            // ==========================================
            // AUTOMATISCHER POLLING-INTERVALL (ALLE 2 SEKUNDEN)
            // ==========================================
            setInterval(() => {
                // Prüfen, ob eine edit_id vorhanden ist (d.h. eine Rechnung geladen ist)
                const editIdInput = document.querySelector('[name="edit_id"]');
                if (editIdInput && editIdInput.value) {
                    const editId = editIdInput.value;

                    fetch(`?action=reload_data&id=${editId}`)
                        .then(response => {
                            if (!response.ok) throw new Error('Netzwerk-Fehler');
                            return response.json();
                        })
                        .then(data => {
                            if (data) {
                                // 1. Druckstatus (Grünes Häkchen / Rotes X) aktualisieren
                                const statusSpan = document.getElementById('druckStatusAnzeige');
                                if (statusSpan && statusSpan.innerHTML !== data.status_html) {
                                    statusSpan.innerHTML = data.status_html;
                                }

                                // 2. PDF-Link dynamisch mitaktualisieren
                                const pdfLink = document.getElementById('openPdf');
                                if (pdfLink && data.pdf_url) {
                                    pdfLink.href = data.pdf_url;
                                }

                                // Bezahlt-Status & SumUp Button via Polling aktualisieren
                                const bezahltCheck = document.getElementById('bezahltCheck');
                                if (bezahltCheck && parseInt(data.bezahlt) === 1) {
                                    bezahltCheck.checked = true;
                                }

                                const sumupBtn = document.getElementById('sumupBtn');
                                if (sumupBtn && parseInt(data.bezahlt) === 1) {
                                    sumupBtn.style.display = 'none';
                                }
                            }
                        })
                        .catch(err => console.error("Fehler beim automatischen Polling:", err));
                }
            }, 2000); // 2000 ms = 2 Sekunden
        });
    </script>
</body>

</html>