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
        $safe = $db->escapeString($input);

        $result = $db->query("
            SELECT * FROM rechnungen
            WHERE name LIKE '%$safe%'
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
            // Position 15 mm vom unteren Rand
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);

            // Firmeninfos aus config.php (Konstanten)
            $firma = FIRMA_NAME . " | " . FIRMA_ADRESSE . " | " . FIRMA_PLZORT . " | " . FIRMA_WEB;

            // Text zentriert ausgeben
            $this->Cell(0, 8, 'Danke für Ihren Besuch!', 0, 1, 'C');
            $this->Cell(0, 10, $firma, 0, false, 'C', 0, '', 0, false, 'T', 'M');
        }
    }

    $pdf = new MYPDF();
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Stefan Schneebauer - FF Wallern');
    $pdf->SetTitle('Rechnung '.$rechnungsnummer);
    $pdf->SetMargins(15, 20, 15);
    $pdf->AddPage();

    // Logo
    $logoPath = __DIR__ . '/images/Logo.png';
    if (file_exists($logoPath)) {
        $bildBreite = 40;
        $seitenBreite = $pdf->getPageWidth();
        $rechteMargin = $pdf->getMargins()['right'];
        $xPos = $seitenBreite - $rechteMargin - $bildBreite;
        $pdf->Image($logoPath, $xPos, 15, $bildBreite);
    }

    $pdf->SetFont('helvetica', '', 12);
    $html = "
    <h2>Rechnung</h2>
    <p><strong>Rechnungsnummer:</strong> {$rechnungsnummer}</p>
    <p><strong>Datum:</strong> ".date('d.m.Y')."</p>
    <hr>
    <p><strong>Anrede:</strong> {$_POST['anrede']}</p>
    <p><strong>Name:</strong> {$_POST['name']}</p>
    <p><strong>Adresse:</strong> {$_POST['adresse']}, {$_POST['plz']} {$_POST['ort']}</p>
    <hr>
    <p><strong>Anzahl Löscher:</strong> {$_POST['anzahl']}</p>
    <p><strong>Preis pro Löscher:</strong> " . number_format(floatval($preis), 2, ',', '.') . " €</p>
    <p><strong>Gesamt:</strong> " . number_format(floatval((int)$_POST['anzahl'] * $preis), 2, ',', '.') . " €</p>
    ";
    $pdf->writeHTML($html, true, false, true, false, '');

    $folder = __DIR__ . '/_Rechnungen';
    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $filename = $folder.'/Rechnung_'.preg_replace('/[^a-zA-Z0-9_-]/', '', $rechnungsnummer).'.pdf';
    $pdf->Output($filename, 'F');
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
<body>

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
        <input type="text" name="suchfeld" class="form-control" autocomplete="off">
        <button name="suche" class="btn btn-primary">Suchen</button>
    </div>
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
const preisMap = <?= json_encode($preise) ?>;
const nextRechnungsnummer = "<?= $nextRechnungsnummer ?>";

function updatePreis(){
    const typ = document.getElementById('typSelect').value;
    const preis = preisMap[typ] || 0;

    document.getElementById('preisField').value =
        preis.toFixed(2).replace('.', ',') + " €";
}

document.getElementById('typSelect').addEventListener('change', updatePreis);
updatePreis();

document.getElementById('clearForm').addEventListener('click', () => {
    window.location = window.location.href;
});

document.getElementById('reloadData').addEventListener('click', () => {
    const editId = document.querySelector('[name="edit_id"]').value;
    if (!editId) return alert('Keine Rechnung ausgewählt!');

    fetch(`?action=reload_data&id=${editId}`)
        .then(res => res.json())
        .then(data => {
            // Formularfelder aktualisieren
            const form = document.querySelector('form.card.p-4');
            form.querySelector('[name="anrede"]').value = data.anrede || 'Herr';
            form.querySelector('[name="name"]').value = data.name || '';
            form.querySelector('[name="adresse"]').value = data.adresse || '';
            form.querySelector('[name="plz"]').value = data.plz || '4702';
            form.querySelector('[name="ort"]').value = data.ort || 'Wallern an der Trattnach';
            form.querySelector('[name="anzahl"]').value = data.anzahl_loescher || 1;
            
            // Preis-Typ setzen
            let typ = 'Standard';
            for (const [k, v] of Object.entries(preisMap)) {
                if (v == data.preis_pro_loescher) typ = k;
            }
            form.querySelector('[name="typ"]').value = typ;
            updatePreis();

            // PDF-Button aktualisieren
            const pdfButton = document.getElementById('openPdf');
            pdfButton.style.display = 'inline-block';
            pdfButton.href = `_Rechnungen/Rechnung_${data.rechnungsnummer.replace(/[^a-zA-Z0-9_-]/g,'')}.pdf`;
        });
});
</script>

</body>
</html>