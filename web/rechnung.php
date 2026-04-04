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
    'Voller Preis' => PREIS_VOLLER,
    'Rabatt' => PREIS_RABATT,
    'Gratis' => PREIS_GRATIS
];

// =====================
// NAMEN LADEN
// =====================
$namen = [];
$res = $db->query("SELECT DISTINCT TRIM(name) as name FROM loescher WHERE name IS NOT NULL AND TRIM(name) != '' ORDER BY name");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $namen[] = $row['name'];
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
    $stmt->bindValue(':rnr', $_POST['rechnungsnummer']);

    $stmt->execute();
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            &#128293; Feuerlöscher Software - &#129534; Rechnungen
        </span>

        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-light btn-sm">Zurück</a>
            <a href="?logout=1" class="btn btn-danger btn-sm">Abmelden</a>
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
        <input type="text" name="suchfeld" class="form-control">
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
        <option <?= ($editEntry['anrede'] ?? '')=='Herr'?'selected':'' ?>>Herr</option>
        <option <?= ($editEntry['anrede'] ?? '')=='Frau'?'selected':'' ?>>Frau</option>
        <option <?= ($editEntry['anrede'] ?? '')=='Firma'?'selected':'' ?>>Firma</option>
    </select>
</div>

<div class="mb-3">
    <label class="form-label">&#128100; Name</label>
    <input list="namenListe" name="name" class="form-control"
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
            <option value="<?= $k ?>" <?= ($currentTyp==$k)?'selected':'' ?>>
                <?= $k ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="preisField" class="form-control mt-1" disabled>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">&#128196; Rechnungsnummer</label>
    <input type="text" name="rechnungsnummer" class="form-control"
        value="<?= htmlspecialchars($editEntry['rechnungsnummer'] ?? '') ?>" required>
</div>

<?php if ($editEntry): ?>
<p>
    &#128424; Gedruckt:
    <?= ($editEntry['rechnung_gedruckt'] ?? 0) ? '&#9989;' : '&#10060;' ?>
</p>
<?php endif; ?>

<button class="btn btn-success w-100" name="save_rechnung">
    &#128190; Speichern
</button>

<button type="button" class="btn btn-secondary w-100 mt-2" id="clearForm">
    &#10060; Formular leeren
</button>

</form>
</div>

<script>
const preisMap = <?= json_encode($preise) ?>;

function updatePreis(){
    const typ = document.getElementById('typSelect').value;
    document.getElementById('preisField').value = preisMap[typ] + " €";
}

document.getElementById('typSelect').addEventListener('change', updatePreis);
updatePreis();

document.getElementById('clearForm').addEventListener('click', () => {

    // Formular reset
    const form = document.querySelector('form.card.p-4');
    form.reset();

    // Edit-Modus raus
    document.querySelector('[name="edit_id"]').value = '';

    // Preis neu berechnen
    updatePreis();

    // Alerts entfernen
    document.querySelectorAll('.alert').forEach(el => el.remove());

});
</script>

</body>
</html>