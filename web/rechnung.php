<?php
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
    WHERE rechnungsnummer LIKE '".RECHNUNGS_PREFIX."%' 
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
    $id = (int)$_GET['id'];
    $row = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
    
    // Wir bereiten die Antwort vor
    if ($row) {
        $row['status_html'] = ($row['rechnung_gedruckt'] == 1) ? '&#9989;' : '&#10060;';
        // Dateiname für den Link generieren
        $row['pdf_url'] = '_Rechnungen/Rechnung_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $row['rechnungsnummer']) . '.pdf';
    }
    
    header('Content-Type: application/json');
    echo json_encode($row);
    exit;
}

// =====================
// RECHNUNG AUS MASKE LÖSCHEN (STORNO)
// =====================
if (isset($_POST['delete_rechnung_form']) && !empty($_POST['edit_id'])) {
    $deleteId = (int)$_POST['edit_id'];
    
    $stmt = $db->prepare("SELECT rechnungsnummer FROM rechnungen WHERE id = :id");
    $stmt->bindValue(':id', $deleteId);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($res) {
        $rnr = $res['rechnungsnummer'];
        $pdfPath = __DIR__ . '/_Rechnungen/Rechnung_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $rnr) . '.pdf';
        
        $delStmt = $db->prepare("DELETE FROM rechnungen WHERE id = :id");
        $delStmt->bindValue(':id', $deleteId);
        if ($delStmt->execute()) {
            if (file_exists($pdfPath)) {
                @unlink($pdfPath);
            }
            // Nach dem Löschen leiten wir zurück zur Rechnungsübersicht mit einer Erfolgsmeldung
            header("Location: rechnungen_anzeigen.php");
            exit;
        }
    }
}

// =====================
// RECHNUNG NACHDRUCKEN
// =====================
if (isset($_POST['reprint_rechnung']) && !empty($_POST['edit_id'])) {
    $id = (int)$_POST['edit_id'];
    $stmt = $db->prepare("UPDATE rechnungen SET rechnung_gedruckt = 0, zeitstempel_gedruckt = NULL WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $successMessage = "&#128424; Rechnung wird erneut gedruckt (BON)!";
    
    // Eintrag für Bearbeitung neu laden
    $editEntry = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// AUTOMATISCHES LADEN NACH SPEICHERN
// =====================
if (isset($_GET['id']) && empty($editEntry)) {
    $id = (int)$_GET['id'];
    $editEntry = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// SUCHE
// =====================
if (isset($_POST['suche'])) {
    $input = trim($_POST['suchfeld'] ?? '');

    if ($input !== '') {
        // Falls der User einen Vorschlag gewählt hat (Format: "R2024-0001 - Name")
        // nehmen wir nur den Teil vor dem ersten Bindestrich
        if (strpos($input, ' - ') !== false) {
            $parts = explode(' - ', $input);
            $searchVal = trim($parts[0]); // Das ist die Rechnungsnummer
        } else {
            $searchVal = $input; // Manuelle Eingabe (nur Name oder nur Nummer)
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
    $id = (int)$_POST['selected_entry'];
    $editEntry = $db->query("SELECT * FROM rechnungen WHERE id = $id")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// SPEICHERN / UPDATE
// =====================
if (isset($_POST['save_rechnung'])) {

    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;

    // Automatische Rechnungsnummer
    if (empty($_POST['edit_id'])) {
        $rechnungsnummer = $nextRechnungsnummer;
    } else {
        $rechnungsnummer = $_POST['rechnungsnummer'];
    }

    if (!empty($_POST['edit_id'])) {
        // UPDATE
        $lastId = (int)$_POST['edit_id'];
        
        // 1. Holen wir uns den aktuellen Stand aus der DB zum Vergleich
        $currentData = $db->query("SELECT * FROM rechnungen WHERE id = $lastId")->fetchArray(SQLITE3_ASSOC);
        
        // 2. Definieren, was eine kritische Änderung ist
        // Wir vergleichen: Anzahl, gewählter Preis-Typ (über den Preis) und die Rechnungsnummer
        $typ = $_POST['typ'] ?? '';
        $neuerPreis = $preise[$typ] ?? 0;
        
        $hatKritischeAenderung = (
            (int)$_POST['anzahl'] !== (int)$currentData['anzahl_loescher'] ||
            (float)$neuerPreis !== (float)$currentData['preis_pro_loescher'] ||
            $_POST['rechnungsnummer'] !== $currentData['rechnungsnummer']
        );

        // 3. Status nur zurücksetzen, wenn kritisch geändert ODER wenn wir manuell "Nachdrucken" gedrückt haben
        $resetDruck = $hatKritischeAenderung ? 0 : $currentData['rechnung_gedruckt'];
        $resetZeit  = $hatKritischeAenderung ? NULL : $currentData['zeitstempel_gedruckt'];

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
                zeitstempel_gedruckt = :z_gedruckt
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
        // INSERT
        $stmt = $db->prepare("
            INSERT INTO rechnungen (
                anrede, name, adresse, plz, ort,
                anzahl_loescher, preis_pro_loescher,
                zeitstempel_erstellung, rechnungsnummer, zahlungsart, bezahlt
            ) VALUES (
                :anrede, :name, :adresse, :plz, :ort,
                :anzahl, :preis, :zeit, :rnr, :zahlungsart, :bezahlt
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
    $stmt->bindValue(':anzahl', (int)$_POST['anzahl']);
    $stmt->bindValue(':preis', $preis);
    $stmt->bindValue(':rnr', $rechnungsnummer);
    $stmt->bindValue(':zahlungsart', $_POST['zahlungsart'] ?? 'Barzahlung');
    $stmt->bindValue(':bezahlt', isset($_POST['bezahlt']) ? 1 : 0);
    $stmt->execute();

    if (empty($_POST['edit_id'])) {
        $lastId = $db->lastInsertRowID(); // die neue ID holen
    }

    // =====================
    // TCPDF PDF GENERIEREN
    // =====================
    require_once('tcpdf/tcpdf.php');

    class MYPDF extends TCPDF {
        public function Footer() {
            // Position 25 mm vom unteren Rand
            $this->SetY(-25);
            $this->SetFont('helvetica', 'I', 8);
            
            // Dezente Trennlinie über dem Footer
            $this->Line(15, $this->GetY(), 195, $this->GetY());
            $this->Ln(2);

            $firma = FIRMA_NAME . " | " . FIRMA_ADRESSE . " | " . FIRMA_PLZORT. " | " . FIRMA_WEB;
            $this->Cell(0, 4, $firma, 0, 1, 'C');

            $this->SetFont('helvetica', 'B', 8);
            $this->Cell(0, 4, 'Vielen Dank für Ihren Besuch!', 0, 0, 'C');

            $info = "Erstellt mit Feuerlöscher-Software | © Schneebauer " . date("Y");
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

    // --- 1. LOGO & ABSENDERZEILE ---
    $logoPath = __DIR__ . '/images/Logo.png';
    if (file_exists($logoPath)) {
        // Logo rechts oben (Breite 50mm)
        $pdf->Image($logoPath, 145, 15, 50);
    }
    
    // Kleiner Absender (Einzeiler für Fensterkuvert)
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetXY(15, 42);
    $pdf->Cell(0, 5, FIRMA_NAME . " • " . FIRMA_ADRESSE . " • " . FIRMA_PLZORT, 0, 1, 'L');

	// =====================
	// EMPFÄNGER LINKS
	// =====================
	
	$pdf->SetFont('helvetica', '', 11);
	
	// Linke Spalte
	$leftX = 15;
	$leftWidth = 100;
	
	$pdf->SetXY($leftX, 50);
	
	// Anrede
	if (!empty($_POST['anrede']) && $_POST['anrede'] !== '-') {
	    $pdf->MultiCell($leftWidth, 6, $_POST['anrede'], 0, 'L');
	}
	
	// Name (fett)
	$pdf->SetX($leftX);
	$pdf->SetFont('helvetica', 'B', 11);
	$pdf->MultiCell($leftWidth, 6, $_POST['name'], 0, 'L');
	
	$pdf->SetFont('helvetica', '', 11);
	
	// Adresse
	if (!empty($_POST['adresse'])) {
	    $pdf->SetX($leftX);
	    $pdf->MultiCell($leftWidth, 6, $_POST['adresse'], 0, 'L');
	}
	
	// PLZ Ort
	$pdf->SetX($leftX);
	$pdf->MultiCell($leftWidth, 6, $_POST['plz'] . ' ' . $_POST['ort'], 0, 'L');
	
	
	// =====================
	// RECHTSBLOCK (fix rechts)
	// =====================
	
	// Rechte Spalte beginnt fix rechts
	$rightX = 130;
	
	$pdf->SetXY($rightX, 50);
	
	// Datum
	$pdf->Cell(40, 6, 'Datum:', 0, 0, 'R');
	$pdf->Cell(30, 6, date('d.m.Y'), 0, 1, 'L');
	
	// Rechnungsnummer
	$pdf->SetX($rightX);
	$pdf->Cell(40, 6, 'Rechnungs-Nr.:', 0, 0, 'R');
	$pdf->Cell(30, 6, $rechnungsnummer, 0, 1, 'L');
	
	
	// =====================
	// ABSTAND NACH UNTEN
	// =====================
	$pdf->Ln(15);

    $pdf->writeHTML($headerTable, true, false, true, false, '');

    // --- TITEL ---
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, "Rechnung", 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);
    //$pdf->Cell(0, 5, "über die Feuerlöscherüberprüfung", 0, 1, 'L');
    $pdf->Ln(10);

    // --- LEISTUNGSTABELLE ---
    $anzahl = (int)$_POST['anzahl'];
    $einzelpreis = floatval($preis);
    $gesamtpreis = $anzahl * $einzelpreis;

    // Definierte Breiten für absolute Fluchtung
    $w_bez = "55%";
    $w_menge = "15%";
    $w_einzel = "15%";
    $w_gesamt = "15%";

    $tbl = '
    <table border="0" cellpadding="6" cellspacing="0" width="100%">
        <thead>
            <tr style="background-color:#eeeeee; font-weight:bold;">
                <th width="'.$w_bez.'" style="border-bottom: 1px solid #333;">Bezeichnung</th>
                <th width="'.$w_menge.'" align="center" style="border-bottom: 1px solid #333;">Menge</th>
                <th width="'.$w_einzel.'" align="right" style="border-bottom: 1px solid #333;">Einzel</th>
                <th width="'.$w_gesamt.'" align="right" style="border-bottom: 1px solid #333;">Gesamt</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td width="'.$w_bez.'" style="border-bottom: 0.5px solid #ccc;">Fachmännische Überprüfung von tragbaren Feuerlöschern gemäß ÖNORM F 1053</td>
                <td width="'.$w_menge.'" align="center" style="border-bottom: 0.5px solid #ccc;">' . $anzahl . ' Stk.</td>
                <td width="'.$w_einzel.'" align="right" style="border-bottom: 0.5px solid #ccc;">' . number_format($einzelpreis, 2, ',', '.') . ' €</td>
                <td width="'.$w_gesamt.'" align="right" style="border-bottom: 0.5px solid #ccc;">' . number_format($gesamtpreis, 2, ',', '.') . ' €</td>
            </tr>
            <tr style="font-size: 12pt; font-weight:bold;">
                <td colspan="3" align="right">Gesamtsumme:</td>
                <td align="right">' . number_format($gesamtpreis, 2, ',', '.') . ' €</td>
            </tr>
        </tbody>
    </table>';

    $pdf->writeHTML($tbl, true, false, true, false, '');

    // --- MWST-HINWEIS ---
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 9);

    $hinweis = "Hinweis: Als Körperschaft öffentlichen Rechts ist die Freiwillige Feuerwehr gemäß § 2 Abs. 5 UStG nicht umsatzsteuerpflichtig. Der ausgewiesene Betrag entspricht dem Bruttobetrag (0% MwSt).";
    $pdf->MultiCell(0, 5, $hinweis, 0, 'L');


    // --- ZAHLUNGSINFORMATIONEN ---
    $pdf->Ln(5);
    $zahlungsart = $_POST['zahlungsart'] ?? 'Barzahlung';
    // Header für Zahlungsart
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Zahlungsart: ' . $zahlungsart, 0, 1, 'L');
    // Spezifischer Text je nach Zahlungsart
    $pdf->SetFont('helvetica', '', 10);
    if ($zahlungsart === 'Barzahlung') {
        $pdf->Cell(0, 6, 'Betrag dankend in Bar erhalten.', 0, 1, 'L');
    } elseif ($zahlungsart === 'SumUp' || $zahlungsart === 'Kartenzahlung') {
        $pdf->Cell(0, 6, 'Betrag dankend erhalten.', 0, 1, 'L');
    } else {
        // Standardfall: Überweisung (oder alles andere)
        $text = "Bitte überweisen Sie den Betrag innerhalb von 14 Tagen ohne Abzug an folgendes Konto:\n"
              . "Empfänger: " . BANK_EMPFAENGER . "\n"
              . "Bank: " . BANK_NAME . " | IBAN: " . BANK_IBAN;
        $pdf->MultiCell(0, 5, $text, 0, 'L');
    }

    // ---DATEI SPEICHERN ---
    $folder = __DIR__ . '/_Rechnungen';
    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $filename = $folder.'/Rechnung_'.preg_replace('/[^a-zA-Z0-9_-]/', '', $rechnungsnummer).'.pdf';
    $pdf->Output($filename, 'F');

    header("Location: " . $_SERVER['PHP_SELF'] . "?success=1&id=" . $lastId);
    exit;
}

// =====================
// PREIS-TYP ERMITTELN (für Edit)
// =====================
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
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.35) !important;
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
            &#128293; Feuerlöscher Software - &#128179; Rechnungen
        </span>

        <div class="d-flex gap-2">
            <a href="rechnungen_anzeigen.php" class="btn btn-outline-info btn-sm">Rechnungsübersicht</a>
            <a href="index.php" class="btn btn-outline-light btn-sm">
                Zurück
            </a>
            <a href="?logout=1" class="btn btn-danger btn-sm">
                Abmelden
            </a>
        </div>
    </div>
</nav>

<div class="container mt-5">

<?php if ($successMessage): ?>
<div class="alert alert-success"><?= $successMessage ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
<div class="alert alert-danger"><?= $errorMessage ?></div>
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

<div class="mb-3">
    <label class="form-label">&#128100; Anrede</label>
    <select name="anrede" class="form-select">
        <option <?= ($editEntry['anrede'] ?? '')==''?'selected':'' ?>>-</option>
        <option <?= ($editEntry['anrede'] ?? '')=='Herr'?'selected':'' ?>>Herr</option>
        <option <?= ($editEntry['anrede'] ?? '')=='Frau'?'selected':'' ?>>Frau</option>
        <option <?= ($editEntry['anrede'] ?? '')=='Firma'?'selected':'' ?>>Firma</option>
        <option <?= ($editEntry['anrede'] ?? '')=='Verein'?'selected':'' ?>>Verein</option>
    </select>
</div>

<div class="mb-3">
    <label class="form-label">&#128100; Name</label>
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

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">&#128293; Anzahl Löscher</label>
        <input type="number" name="anzahl" class="form-control highlight"
            value="<?= $editEntry['anzahl_loescher'] ?? 1 ?>">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">&#128176; Preis je Löscher</label>
        <select name="typ" id="typSelect" class="form-select">
            <?php foreach ($preise as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($currentTyp==$k)?'selected':'' ?>><?= $k ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="preisField" class="form-control mt-1" disabled>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">&#128179; Zahlungsart</label>
    <select name="zahlungsart" id="zahlungsartSelect" class="form-select highlight">
        <?php 
        // Barzahlung soll vorausgewählt sein, wenn kein Eintrag geladen wurde (Neu anlegen)
        $selectedZahlungsart = isset($editEntry['zahlungsart']) ? $editEntry['zahlungsart'] : 'Barzahlung'; 
        ?>
        <option value="Barzahlung" <?= ($selectedZahlungsart =='Barzahlung')?'selected':'' ?>>Barzahlung</option>
        <option value="Kartenzahlung" <?= ($selectedZahlungsart =='Kartenzahlung')?'selected':'' ?>>Kartenzahlung</option>
        
        <?php if (defined('SumUp_AVALIABLE') && SumUp_AVALIABLE === 'TRUE'): ?>
            <option value="SumUp" <?= ($selectedZahlungsart =='SumUp')?'selected':'' ?>>SumUp (Button erscheint nach dem Speichern - "Bezahlt" setzt sich autom. nach erfolgreicher Zahlung!)</option>
        <?php endif; ?>

        <option value="Überweisung" <?= ($selectedZahlungsart =='Überweisung')?'selected':'' ?>>Überweisung</option>
    </select>
</div>

<div class="mb-3 form-check">
    <input type="checkbox" name="bezahlt" value="1" class="form-check-input" id="bezahltCheck" <?= ($editEntry['bezahlt'] ?? 0) ? 'checked' : '' ?>>
    <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
</div>

<div class="modal fade" id="changeCalculatorModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="changeCalculatorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="changeCalculatorModalLabel">&#128181; Wechselgeldrechner</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Schließen"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Zu zahlen (Gesamt):</label>
          <input type="text" id="calcTotalAmount" class="form-control form-control-lg fw-bold text-danger" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label" for="calcGivenAmount">Erhalten vom Kunden (€):</label>
          <input type="number" id="calcGivenAmount" class="form-control form-control-lg" step="0.01" min="0" placeholder="0,00" autocomplete="off">
        </div>
        <div class="p-3 bg-light rounded border text-center">
          <span class="fs-5 d-block text-muted">Rückgeld:</span>
          <span id="calcReturnAmount" class="fs-2 fw-bold text-success">0,00 €</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
        <button type="button" id="confirmChangeCalc" class="btn btn-success">Betrag passt & Speichern</button>
      </div>
    </div>
  </div>
</div>

<hr>    

<div class="mb-3">
    <label class="form-label">&#128196; Rechnungsnummer</label>
    <input type="text" name="rechnungsnummer" class="form-control" 
       value="<?= htmlspecialchars($editEntry['rechnungsnummer'] ?? $nextRechnungsnummer) ?>" 
       readonly>
</div>

<?php if (!is_null($editEntry) && $editEntry) { ?>

<p>
    &#128424; Gedruckt: <span id="druckStatusAnzeige"><?= ($editEntry['rechnung_gedruckt'] ?? 0) ? '&#9989;' : '&#10060;' ?></span>
</p>

<div class="d-flex gap-2 mb-3 flex-wrap">

    <a id="openPdf" 
       href="_Rechnungen/Rechnung_<?= preg_replace('/[^a-zA-Z0-9_-]/', '', $editEntry['rechnungsnummer']) ?>.pdf" 
       target="_blank" 
       class="btn btn-info">
         &#128196; PDF Rechnung öffnen
    </a>

    <button type="submit" name="reprint_rechnung" class="btn btn-warning">
         Bon Nachdrucken
    </button>

    <button type="button" id="reloadData" class="btn btn-secondary">
        &#128260; Daten neu laden
    </button>

    <button type="submit" name="delete_rechnung_form" class="btn btn-danger" onclick="return confirm('Möchten Sie diese Rechnung wirklich unwiderruflich stornieren und löschen? Das PDF wird ebenfalls gelöscht.');">
        &#128465; Rechnung stornieren
    </button>

    <?php if (($editEntry['zahlungsart'] ?? '') === 'SumUp' && defined('SumUp_AVALIABLE') && SumUp_AVALIABLE === 'TRUE' && $editEntry['bezahlt'] === 0): ?>
        <button type="button" id="sumupBtn" class="btn btn-primary">
            &#128179; SumUp Zahlung starten
        </button>
    <?php endif; ?>

</div>

<input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">

<?php } else { ?>

<a id="openPdf" href="#" style="display:none;" class="btn btn-info mb-3">
    &#128196; PDF öffnen
</a>

<?php } ?>

<button class="btn btn-success w-100" name="save_rechnung" id="submitFormBtn">&#128190; Speichern</button>
<button type="button" class="btn btn-outline-secondary w-100 mt-2" id="clearForm">&#128465; Formular leeren</button>

</form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// 1. PHP-Daten sicher übernehmen
const preisMap = <?= json_encode($preise) ?>;
const nextRechnungsnummer = "<?= $nextRechnungsnummer ?>";

// 2. Preis-Update Funktion
function updatePreis(){
    const typSelect = document.getElementById('typSelect');
    const preisField = document.getElementById('preisField');
    if(!typSelect || !preisField) return 0;

    const typ = typSelect.value;
    const preis = preisMap[typ] || 0;
    preisField.value = preis.toFixed(2).replace('.', ',') + " €";
    return preis;
}

// Hilfsfunktion zur Ermittlung des aktuellen Gesamtpreises
function getGesamtBetrag() {
    const anzahlInput = document.querySelector('[name="anzahl"]');
    const anzahl = anzahlInput ? parseInt(anzahlInput.value) || 0 : 0;
    const einzelpreis = updatePreis();
    return anzahl * einzelpreis;
}

// 3. Hauptlogik beim Laden der Seite
document.addEventListener('DOMContentLoaded', function() {
    // Bootstrap Modal initialisieren
    const changeModalEl = document.getElementById('changeCalculatorModal');
    const changeModal = new bootstrap.Modal(changeModalEl);
    
    const rechnungsForm = document.getElementById('rechnungsForm');
    const bezahltCheck = document.getElementById('bezahltCheck');
    const calcTotalAmount = document.getElementById('calcTotalAmount');
    const calcGivenAmount = document.getElementById('calcGivenAmount');
    const calcReturnAmount = document.getElementById('calcReturnAmount');
    const confirmChangeCalc = document.getElementById('confirmChangeCalc');
    
    let bypassModal = false; // Flag, um das Modal beim echten Submit zu überspringen

    // Funktion zum Öffnen und Füllen des Modals
    function openWechselgeldRechner() {
        const gesamt = getGesamtBetrag();
        calcTotalAmount.value = gesamt.toFixed(2).replace('.', ',') + " €";
        calcGivenAmount.value = ''; // Reset Eingabe
        calcReturnAmount.innerText = "0,00 €";
        calcReturnAmount.className = "fs-2 fw-bold text-success";
        
        changeModal.show();
        
        // Autofokus auf das "Erhalten"-Feld nach dem Öffnen
        changeModalEl.addEventListener('shown.bs.modal', function () {
            calcGivenAmount.focus();
        }, { once: true });
    }

    // Beim Klick auf den großen "Speichern" Button das Submit abfangen
    rechnungsForm.addEventListener('submit', function(e) {
        const activeSubmitter = e.submitter ? e.submitter.name : '';
        const zahlungsart = document.getElementById('zahlungsartSelect').value;

        // NUR abfangen, wenn:
        // 1. "Bezahlt" angehakt ist
        // 2. Exakt "Barzahlung" ausgewählt ist
        // 3. Wir nicht schon das OK aus dem Modal bekommen haben (bypassModal)
        // 4. Nicht der "Nachdrucken" Button gedrückt wurde
        if (bezahltCheck.checked && zahlungsart === 'Barzahlung' && !bypassModal && activeSubmitter === 'save_rechnung') {
            e.preventDefault(); // Stoppt das Speichern vorerst
            openWechselgeldRechner(); // Zeigt stattdessen das Wechselgeld an
        }
    });

    // Klick auf "Betrag passt & Speichern" im Modal
    confirmChangeCalc.addEventListener('click', function() {
        bypassModal = true; // Erlaubt das Durchgehen des Submits
        changeModal.hide();
        
        // Erstellt einen versteckten Input für 'save_rechnung', da e.submitter beim manuellen .submit() verloren geht
        const hiddenSubmit = document.createElement('input');
        hiddenSubmit.type = 'hidden';
        hiddenSubmit.name = 'save_rechnung';
        hiddenSubmit.value = '1';
        rechnungsForm.appendChild(hiddenSubmit);
        
        rechnungsForm.submit(); // Sendet das Formular jetzt final ab
    });

    // Live-Wechselgeld-Berechnung bei der Eingabe
    calcGivenAmount.addEventListener('input', function() {
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

    // --- Dynamisches Anzeigen des SumUp Buttons bei Auswahl ---
    const zahlungsartSelect = document.getElementById('zahlungsartSelect');
    if(zahlungsartSelect) {
        zahlungsartSelect.addEventListener('change', function() {
            const sumupBtn = document.getElementById('sumupBtn');
            if (this.value === 'SumUp') {
                if(sumupBtn) sumupBtn.style.display = 'block';
            } else {
                if(sumupBtn) sumupBtn.style.display = 'none';
            }
        });
    }
    
    // --- PREIS INITIALISIERUNG ---
    document.getElementById('typSelect').addEventListener('change', updatePreis);
    const anzahlInput = document.querySelector('[name="anzahl"]');
    if(anzahlInput) anzahlInput.addEventListener('input', updatePreis);
    updatePreis();

    // --- FORMULAR LEEREN FUNKTION ---
    const clearBtn = document.getElementById('clearForm');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.location.href = cleanUrl;
        });
    }

    // --- ESC-TASTE ZUM LEEREN ---
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

    // --- SUMUP LOGIK ---
    const sumupBtn = document.getElementById('sumupBtn');
    if (sumupBtn) {
        sumupBtn.addEventListener('click', () => {
            const editId = document.querySelector('[name="edit_id"]').value;
            if (!editId) return alert("Bitte Rechnung zuerst speichern!");

            const width = 400;
            const height = 500;
            const left = (window.innerWidth / 2) - (width / 2);
            const top = (window.innerHeight / 2) - (height / 2);
            
            window.open(
                `sumup.php?rechnung_id=${editId}`, 
                'SumUpTerminal', 
                `width=${width},height=${height},left=${left},top=${top},toolbar=no,location=no`
            );
        });
    }

    // --- RECHNUNG VIA AJAX NACHLADEN ---
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

    // --- ERFOLGSMELDUNG BEREINIGEN ---
    if (window.location.search.includes('success=1')) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }

    const alertBox = document.querySelector('.alert');
    if (alertBox) {
        setTimeout(() => {
            alertBox.style.transition = "opacity 0.6s ease, transform 0.6s ease";
            alertBox.style.opacity = "0";
            alertBox.style.transform = "translateY(-20px)";
            setTimeout(() => alertBox.remove(), 600);
        }, 2000);
    }
});
</script>

</body>
</html>