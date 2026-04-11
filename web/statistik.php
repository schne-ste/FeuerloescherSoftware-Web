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
// FILTER
// =====================
$statusFilter = $_GET['status'] ?? 'alle';

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
    'verrechenbar' => 0,
    'nicht_verrechenbar' => 0,
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
        case 'Standard': return PREIS_STANDARD;
        case 'Rabatt': return PREIS_RABATT;
        default: return 0;
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
        $status = 'nicht';
        $statusText = 'Nicht geprüft';
    } else {
        $status = 'ok';
        $statusText = 'OK';
    }

    // FILTER
    if ($statusFilter !== 'alle' && $statusFilter !== $status) {
        continue;
    }

    // Preise
    $vollpreis = getPreis($l['typ']);

    $istVerrechenbar = (
        !$l['defekt'] &&
        $l['bezahlt'] &&
        $l['typ'] !== 'Gratis' &&
        $l['geprueft']
    );

    // =====================
    // STATISTIK (GEFILTERT!)
    // =====================
    $stats['gesamt']++;

    if ($status === 'defekt') $stats['defekt']++;
    elseif ($status === 'ok') $stats['ok']++;
    elseif ($status === 'nicht') $stats['nicht_geprueft']++;

    if ($istVerrechenbar) {
        $stats['verrechenbar']++;
        $gesamtVollerPreis += $vollpreis;
    } else {
        $stats['nicht_verrechenbar']++;
    }

    // Werte anhängen
    $l['statusText'] = $statusText;
    $l['vollpreis'] = $vollpreis;

    $gefilterteLoscher[] = $l;
}

// Gewinn
$gesamtGewinnFirma = $stats['verrechenbar'] * PREIS_RABATT;
$gesamtGewinnFF = $gesamtVollerPreis - $gesamtGewinnFirma;
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
<style>
/* Statusfarben für die Tabelle */
.status-ok { background-color: #d4edda !important; }       /* grün */
.status-defekt { background-color: #f8d7da !important; }   /* rot */
.status-nicht { background-color: #fff3cd !important; }    /* orange */

/* Optional: Hover-Effekt für alle Status-Zeilen */
tr.status-ok:hover,
tr.status-defekt:hover,
tr.status-nicht:hover {
    opacity: 0.85;
    transition: 0.2s;
}

/* Optional: zentrierte Zahlen in der Tabelle */
td {
    vertical-align: middle;
}
</style>
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - &#128200; Statistik
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

<? 
    function status_link_resolver($status = "", $text = "") {
        $selectedStatus = "alle";
        if(array_key_exists("status", $_GET)) {
            $selectedStatus = $_GET["status"];
        }
        $btncls = "";
        switch($status) {
            case "":
            case "alle":
                $btncls = "secondary";
                break;
            case "ok":
                $btncls = "success";
                break;
            case "defekt":
                $btncls = "danger";
                break;
            case "nicht":
                $btncls = "warning";
                break;
        }

        $fullbtncls = "";
        if($selectedStatus == $status) {
            $fullbtncls = "btn-outline-" . $btncls;
        } else {
            $fullbtncls = "btn-" . $btncls;
        }

        echo '<a href="?status=' . $status . '" class="btn ' . $fullbtncls . ' btn-sm">' . $text . "</a>";
    }
?>

<div class="container mt-4">

    <h1>&#128293; Übersicht</h1>

    <!-- FILTER -->
    <div class="mb-3">
        <?= status_link_resolver("alle", "Alle"); ?>
        <?= status_link_resolver("ok", "OK"); ?>
        <?= status_link_resolver("defekt", "Defekt"); ?>
        <?= status_link_resolver("nicht", "Nicht geprüft"); ?>
        <a href="statistik_export_pdf.php?status=<?= $statusFilter ?>" 
           target="_blank" 
           rel="noopener noreferrer" 
           class="btn btn-dark btn-sm">
           📄 PDF
        </a>
    </div>

    <!-- STATISTIK -->
    <table class="table table-bordered w-100">
        <tr><th>Gesamt</th><td><?= $stats['gesamt'] ?></td></tr>
        <tr><th>Verrechenbar</th><td><?= $stats['verrechenbar'] ?></td></tr>
        <tr><th>Nicht verrechenbar</th><td><?= $stats['nicht_verrechenbar'] ?></td></tr>
        <tr><th>Nicht geprüft</th><td><?= $stats['nicht_geprueft'] ?></td></tr>
        <tr><th>OK</th><td><?= $stats['ok'] ?></td></tr>
        <tr><th>Defekt</th><td><?= $stats['defekt'] ?></td></tr>
        <tr><th>Geld gesamt</th><td><?= number_format($gesamtVollerPreis,2) ?> €</td></tr>
        <tr><th>Gewinn Firma</th><td><?= number_format($gesamtGewinnFirma,2) ?> €</td></tr>
        <tr><th>Gewinn FF</th><td><?= number_format($gesamtGewinnFF,2) ?> €</td></tr>
    </table>

    <!-- TABELLE -->
    <h3>Liste</h3>
    <table class="table table-bordered">
    <thead>
        <tr>
            <th>Nr</th>
            <th>Name</th>
            <th>Typ</th>
            <th>Preis</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($gefilterteLoscher as $v): ?>
            <?php
            // Status-Klasse bestimmen
            if ($v['defekt']) {
                $class = 'status-defekt'; // rot
            } elseif (!$v['geprueft']) {
                $class = 'status-nicht';  // orange
            } else {
                $class = 'status-ok';     // grün
            }
            ?>
            <tr>
                <td class="<?= $class ?>"><?= sprintf("%03d", $v['nummer']) ?></td>
                <td class="<?= $class ?>"><?= htmlspecialchars($v['name']) ?></td>
                <td class="<?= $class ?>"><?= htmlspecialchars($v['typ']) ?></td>
                <td class="<?= $class ?>"><?= number_format($v['vollpreis'],2) ?> €</td>
                <td class="<?= $class ?>"><?= $v['statusText'] ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>
</body>
</html>