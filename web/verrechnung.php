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

// =====================
// DATEN LADEN
// =====================
$result = $db->query("SELECT * FROM loescher WHERE active=1");
$allLoscher = [];

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $allLoscher[] = $row;
}

// =====================
// INITIALISIERUNG
// =====================
$stats = [
    'gesamt' => 0,
    'ok' => 0,
    'defekt' => 0,
    'nicht_geprueft' => 0
];

$gesamtVollerPreis = 0;

// =====================
// HELPER
// =====================
function getPreis($typ) {
    switch ($typ) {
        case 'Standard': return PREIS_RABATT;
        case 'Rabatt':   return PREIS_RABATT;
        case 'Gratis':   return 0.0;
        default:         return 0.0;
    }
}

// =====================
// HAUPTLOGIK
// =====================
$gefilterteLoscher = [];

foreach ($allLoscher as $l) {

    // Status bestimmen
    if ($l['defekt']) {
        $status = 'defekt';
        $statusText = 'Defekt';
    } elseif (!$l['geprueft']) {
        $status = 'nicht_geprueft';
        $statusText = 'Nicht geprüft';
    } else {
        $status = 'ok';
        $statusText = 'OK';
    }

    // Filter: nur verrechenbare Löscher
    $istVerrechenbar = (
        !$l['defekt'] &&
        $l['bezahlt'] &&
        $l['typ'] !== 'Gratis' &&
        $l['geprueft']
    );

    if (!$istVerrechenbar) {
        continue;
    }

    // Preise
    $vollpreis = getPreis($l['typ']);

    // =====================
    // STATISTIK (nur verrechenbare!)
    // =====================
    $stats['gesamt']++;

    if ($status === 'defekt') $stats['defekt']++;
    elseif ($status === 'ok') $stats['ok']++;
    elseif ($status === 'nicht_geprueft') $stats['nicht_geprueft']++;

    $gesamtVollerPreis += $vollpreis;

    // Werte anhängen
    $l['statusText'] = $statusText;
    $l['vollpreis'] = $vollpreis;

    $gefilterteLoscher[] = $l;
}

// Gewinn (Firma bekommt Rabatt, FF bekommt Differenz)
$gesamtGewinnFirma = $stats['gesamt'] * PREIS_RABATT;
$gesamtGewinnFF = $gesamtVollerPreis - $gesamtGewinnFirma;
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>&#128293; Feuerlöscher Software</title>
<link rel="icon" href="./images/Feuerlöscher.ico" type="image/x-icon" />
<link rel="shortcut icon" href="./images/Feuerlöscher.ico" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<style>
.status-ok { background-color: #d4edda; }
.status-defekt { background-color: #f8d7da; }
.status-nicht_geprueft { background-color: #fff3cd; }
</style>
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - &#128228; Verrechnung
        </span>

        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-light btn-sm">
                &#127968; Start
            </a>
            <!--<a href="?logout=1" class="btn btn-danger btn-sm">
                Abmelden
            </a>-->
        </div>
    </div>
</nav>
<div class="container mt-4">

    <h1>&#128293; Löscher</h1>

    <!-- PDF Export -->
    <div class="mb-3 d-flex gap-2">
       <a href="verrechnung_export_pdf.php" target="_blank" rel="noopener noreferrer" class="btn btn-dark btn-sm">
            📄 PDF Export (mit Liste)
        </a>
       <a href="verrechnung_export_pdf.php?hide_list=1" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm">
            📄 PDF Export (ohne Liste)
        </a>
    </div>

    <!-- STATISTIK -->
    <table class="table table-bordered w-100">
        <tr><th>Löscher Gesamt</th><td><?= $stats['gesamt'] ?></td></tr>
        <tr><th>Gesamtbetrag</th><td><?= number_format($gesamtGewinnFirma,2) ?> €</td></tr>
    </table>

    <!-- TABELLE -->
    <h3>Liste der Löscher</h3>
    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>Nr</th>
                <th>Preis</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($gefilterteLoscher as $v): ?>
            <?php
                if ($v['defekt']) $class='status-defekt';
                elseif (!$v['geprueft']) $class='status-nicht_geprueft';
                else $class='status-ok';
            ?>
            <tr class="<?= $class ?>">
                <td><?= sprintf("%03d", $v['nummer']) ?></td>
                <td><?= number_format($v['vollpreis'],2) ?> €</td>
                <td><?= $v['statusText'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</div>
</body>
</html>