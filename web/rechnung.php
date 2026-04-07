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
    header('Content-Type: application/json');
    echo json_encode($row);
    exit;
}

// =====================
// RECHNUNG NACHDRUCKEN
// =====================
if (isset($_POST['reprint_rechnung']) && !empty($_POST['edit_id'])) {
    $id = (int)$_POST['edit_id'];
    $stmt = $db->prepare("UPDATE rechnungen SET rechnung_gedruckt = 0, zeitstempel_gedruckt = NULL WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $successMessage = "&#128424; Rechnung zurückgesetzt – jetzt erneut drucken möglich!";
    
    // Eintrag für Bearbeitung neu laden
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
            $successMessage = "❌ Keine Rechnung gefunden!";
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
                rechnung_gedruckt = 0,
                zeitstempel_gedruckt = NULL
            WHERE id = :id
        ");
        $stmt->bindValue(':id', (int)$_POST['edit_id']);
        $successMessage = "&#9989; Rechnung aktualisiert (Druckstatus zurückgesetzt)!";
    } else {
        // INSERT
        $stmt = $db->prepare("
            INSERT INTO rechnungen (
                anrede, name, adresse, plz, ort,
                anzahl_loescher, preis_pro_loescher,
                zeitstempel_erstellung, rechnungsnummer
            ) VALUES (
                :anrede, :name, :adresse, :plz, :ort,
                :anzahl, :preis, :zeit, :rnr
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
    $stmt->execute();

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

            $firma = FIRMA_NAME . " | " . FIRMA_ADRESSE . " | " . FIRMA_PLZORT;
            $info = "Web: " . FIRMA_WEB . " | Erstellt mit Feuerlöscher-Software";

            $this->Cell(0, 4, $firma, 0, 1, 'C');
            $this->Cell(0, 4, $info, 0, 1, 'C');
            $this->Cell(0, 4, 'Vielen Dank für Ihre geschätzte Unterstützung!', 0, 0, 'C');
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

    // --- 2. EMPFÄNGER & RECHNUNGSDATEN ---
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetY(52);

    $anrede = (!empty($_POST['anrede']) && $_POST['anrede'] !== '-') ? htmlspecialchars($_POST['anrede']) . '<br>' : '';
    $adresse = !empty($_POST['adresse']) ? htmlspecialchars($_POST['adresse']) . '<br>' . ' ' . htmlspecialchars($_POST['plz']) . ' ' . htmlspecialchars($_POST['ort']) : '';

    // Layout-Tabelle für Anschrift und Infoblock
    $headerTable = '
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="55%">
                ' . $anrede . '
                <strong>' . htmlspecialchars($_POST['name']) . '</strong><br>
                ' . $adresse . '
            </td>
            <td width="45%">
                <table border="0" cellpadding="2" cellspacing="0" width="100%">
                    <tr>
                        <td width="50%" align="right"><strong>Datum:</strong></td>
                        <td width="50%" align="right">' . date('d.m.Y') . '</td>
                    </tr>
                    <tr>
                        <td width="50%" align="right"><strong>Rechnungs-Nr:</strong></td>
                        <td width="50%" align="right">' . $rechnungsnummer . '</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>';

    $pdf->writeHTML($headerTable, true, false, true, false, '');

    // --- 3. TITEL ---
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, "Rechnung", 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, "über die Feuerlöscherüberprüfung", 0, 1, 'L');
    $pdf->Ln(10);

    // --- 4. LEISTUNGSTABELLE ---
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

    // --- 5. MWST-HINWEIS ---
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'I', 9);

    $hinweis = "Hinweis: Als Körperschaft öffentlichen Rechts ist die Freiwillige Feuerwehr gemäß § 2 Abs. 5 UStG nicht umsatzsteuerpflichtig. Der ausgewiesene Betrag entspricht dem Bruttobetrag (0% MwSt).";

    $pdf->MultiCell(0, 5, $hinweis, 0, 'L');

    // --- 6. DATEI SPEICHERN ---
    $folder = __DIR__ . '/_Rechnungen';
    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $filename = $folder.'/Rechnung_'.preg_replace('/[^a-zA-Z0-9_-]/', '', $rechnungsnummer).'.pdf';
    $pdf->Output($filename, 'F');

    header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
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

<!-- SUCHE -->
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

<!-- MEHRFACH -->
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

<!-- FORMULAR -->
<form method="post" class="card p-4">

<input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?? '' ?>">

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
    <input list="namenListe" name="name" class="form-control" autocomplete="off"
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
        <label class="form-label">&#128293; Anzahl</label>
        <input type="number" name="anzahl" class="form-control"
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
    <label class="form-label">&#128196; Rechnungsnummer</label>
    <input type="text" name="rechnungsnummer" class="form-control" 
       value="<?= htmlspecialchars($editEntry['rechnungsnummer'] ?? $nextRechnungsnummer) ?>" 
       readonly>
</div>

<?php if (!is_null($editEntry) && $editEntry) { ?>

<p>
    &#128424; Gedruckt: <?= ($editEntry['rechnung_gedruckt'] ?? 0) ? '&#9989;' : '&#10060;' ?>
</p>

<div class="d-flex gap-2 mb-3 flex-wrap">

    <a id="openPdf" 
       href="_Rechnungen/Rechnung_<?= preg_replace('/[^a-zA-Z0-9_-]/', '', $editEntry['rechnungsnummer']) ?>.pdf" 
       target="_blank" 
       class="btn btn-info">
        📄 PDF Rechnung öffnen
    </a>

    <button type="submit" name="reprint_rechnung" class="btn btn-warning">
        🔄 Bon Nachdrucken
    </button>

    <button type="button" id="reloadData" class="btn btn-secondary">
        🔄 Daten neu laden
    </button>

</div>

<input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">

<?php } else { ?>

<a id="openPdf" href="#" style="display:none;" class="btn btn-info mb-3">
    📄 PDF öffnen
</a>

<?php } ?>

<button class="btn btn-success w-100" name="save_rechnung">&#128190; Speichern</button>
<button type="button" class="btn btn-secondary w-100 mt-2" id="clearForm">&#10060; Formular leeren</button>

</form>
</div>

<script>
// 1. PHP-Daten sicher übernehmen
const preisMap = <?= json_encode($preise) ?>;
const nextRechnungsnummer = "<?= $nextRechnungsnummer ?>";

// 2. Preis-Update Funktion
function updatePreis(){
    const typSelect = document.getElementById('typSelect');
    const preisField = document.getElementById('preisField');
    if(!typSelect || !preisField) return;

    const typ = typSelect.value;
    const preis = preisMap[typ] || 0;
    preisField.value = preis.toFixed(2).replace('.', ',') + " €";
}

// 3. Hauptlogik beim Laden der Seite
document.addEventListener('DOMContentLoaded', function() {
    
    // Event-Listener für Preis-Dropdown
    document.getElementById('typSelect').addEventListener('change', updatePreis);
    updatePreis();

    // Formular leeren (lädt die Seite ohne Parameter neu)
    document.getElementById('clearForm').addEventListener('click', () => {
    window.location.href = window.location.pathname;
});

    // --- RECHNUNG NACHLADEN ---
    const reloadBtn = document.getElementById('reloadData');
    if (reloadBtn) {
        reloadBtn.addEventListener('click', () => {
            const editId = document.querySelector('[name="edit_id"]').value;
            if (!editId) return alert('Keine Rechnung ausgewählt!');
            fetch(`?action=reload_data&id=${editId}`)
                .then(res => res.json())
                .then(data => {
                    location.reload(); // Einfachste Methode um alles konsistent zu haben
                });
        });
    }

    // --- ERFOLGSMELDUNG & URL BEREINIGEN ---
    
    // Sofort die URL bereinigen (entfernt ?success=1 aus der Adresszeile)
    if (window.location.search.includes('success=1')) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }

    // Die Meldung nach 2 Sekunden ausblenden
    const alert = document.querySelector('.alert-success');
    if (alert) {
        setTimeout(() => {
            alert.style.transition = "opacity 0.6s ease, transform 0.6s ease";
            alert.style.opacity = "0";
            alert.style.transform = "translateY(-20px)"; // Schiebt es leicht hoch beim faden
            
            setTimeout(() => {
                alert.remove();
            }, 600);
        }, 2000);
    }
});


</script>

</body>
</html>