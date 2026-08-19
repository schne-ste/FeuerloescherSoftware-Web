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
$gesamtGewinnFirma = 0;
$gesamtGewinnFF = 0;

$anzahlEntsorgung = 0;
$gesamtEntsorgungskosten = 0;

// =====================
// HELPER
// =====================
function getPreis($l) {
    // Ignoriere die Datenbank-Spalte 'preis' und verwende nur noch die Config-Werte
    switch ($l['typ']) {
        case 'Standard': return PREIS_STANDARD; // Nutzt den Wert aus config.php
        case 'Rabatt':   return PREIS_RABATT;   // Nutzt den Wert aus config.php
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
        $status = 'nicht';
        $statusText = 'Nicht geprüft &#10060;';
    } else {
        $status = 'ok';
        $statusText = 'OK';
    }

    // FILTER
    if ($statusFilter === 'nicht_abgeholt') {
        if ($l['abgeholt']) {
            continue;
        }
    } elseif ($statusFilter !== 'alle' && $statusFilter !== $status) {
        continue;
    }

    // Preis ermitteln (aus Spalte 'preis' oder Typ)
    $dbPreis = getPreis($l);

    // Initialisierung der Anteile
    $anteilFirma = 0;
    $anteilFF = 0;

    // BETRAGS-AUFTEILUNG LOGIK:
    if ($l['bezahlt'] && $l['typ'] !== 'Gratis') {
        
        // 1. Defekt & Bezahlt -> Firma bekommt 0, FF bekommt den gesamten Betrag aus der DB
        if ($l['defekt']) {
            $anteilFirma = 0;
            $anteilFF = $dbPreis;
        } 
        // 2. OK & Bezahlt
        elseif ($l['geprueft']) {
            if ($l['typ'] === 'Standard') {
                $anteilFirma = PREIS_RABATT;
                $anteilFF = $dbPreis - PREIS_RABATT;
            } elseif ($l['typ'] === 'Rabatt') {
                $anteilFirma = $dbPreis;
                $anteilFF = 0;
            }
        }
    }

    // Entsorgungskosten erfassen (Defekt UND Bezahlt)
    if ($l['defekt'] && $l['bezahlt'] && !$l["abgeholt"]) {
        $anzahlEntsorgung++;
        $gesamtEntsorgungskosten += $dbPreis;
    }

    // Verrechenbarkeits-Prüfung für die Statistik-Zähler (NUR wenn nicht defekt, geprüft, bezahlt und nicht gratis)
    $istVerrechenbar = (
        !$l['defekt'] && 
        $l['geprueft'] && 
        $l['bezahlt'] && 
        $l['typ'] !== 'Gratis'
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
        $gesamtVollerPreis += $dbPreis;
        $gesamtGewinnFirma += $anteilFirma;
        $gesamtGewinnFF += $anteilFF;
    } else {
        $stats['nicht_verrechenbar']++;
    }

    // Werte für die Ansicht/Tabelle speichern
    $l['statusText'] = $statusText;
    $l['vollpreis'] = $dbPreis;
    $l['anteilFirma'] = $anteilFirma;
    $l['anteilFF'] = $anteilFF;

    $gefilterteLoscher[] = $l;
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
<style>
/* Statusfarben für die Tabelle */
.status-ok { background-color: #d4edda !important; }       /* grün */
.status-defekt { background-color: #f8d7da !important; }   /* rot */
.status-nicht { background-color: #fff3cd !important; }    /* orange */
.status-defekt-bezahlt { background-color: #e4edd4 !important; } /* hellgrün/dezenter Ton */

/* Statusfarben für die Tabelle */
.status-orange { background-color: #fff3cd !important; }   /* Orange (Nicht geprüft) */
.status-hellgruen { background-color: #e4edd4 !important; }/* Hellgrün */
.status-gruen { background-color: #d4edda !important; }    /* Grün */
.status-rot { background-color: #f8d7da !important; }      /* Rot (alles andere) */

/* Optional: Hover-Effekt für alle Status-Zeilen */
tr.status-ok:hover,
tr.status-defekt:hover,
tr.status-nicht:hover,
tr.status-defekt-bezahlt:hover {
    opacity: 0.85;
    transition: 0.2s;
}

/* Zentrierte Ausrichtung in Tabellenzellen */
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
                &#127968; Start
            </a>
            <!--<a href="?logout=1" class="btn btn-danger btn-sm">
                Abmelden
            </a>-->
        </div>
    </div>
</nav>

<?php 
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
            case "nicht_abgeholt":
                $btncls = "info";
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

    <div class="mb-3 d-flex gap-1 flex-wrap align-items-center">
        <?= status_link_resolver("alle", "Alle"); ?>
        <?= status_link_resolver("ok", "OK"); ?>
        <?= status_link_resolver("defekt", "Defekt"); ?>
        <?= status_link_resolver("nicht", "Nicht geprüft"); ?>
        <?= status_link_resolver("nicht_abgeholt", "Nicht abgeholt"); ?>
        
        <span class="ms-2 me-1 text-muted">|</span>
        
        <a href="statistik_export_pdf.php?status=<?= $statusFilter ?>&ansicht=liste" 
           target="_blank" 
           rel="noopener noreferrer" 
           class="btn btn-dark btn-sm">
           📄 PDF (Komplette Liste)
        </a>
        <a href="statistik_export_pdf.php?status=<?= $statusFilter ?>&ansicht=uebersicht" 
           target="_blank" 
           rel="noopener noreferrer" 
           class="btn btn-outline-dark btn-sm">
           📋 PDF (Nur Übersicht)
        </a>
    </div>

    <table class="table table-bordered w-100">
        <tr><th>Gesamt</th><td><?= $stats['gesamt'] ?> Stück</td></tr>
        <tr><th>Verrechenbar</th><td><?= $stats['verrechenbar'] ?> Stück</td></tr>
        <tr><th>Nicht verrechenbar</th><td><?= $stats['nicht_verrechenbar'] ?> Stück</td></tr>
        <tr><th>Nicht geprüft</th><td><?= $stats['nicht_geprueft'] ?> Stück</td></tr>
        <tr><th>OK</th><td><?= $stats['ok'] ?> Stück</td></tr>
        <tr><th>Defekt</th><td><?= $stats['defekt'] ?> Stück</td></tr>
        <tr><th>Entsorgung (Defekt & Geld nicht retour)</th><td><?= $anzahlEntsorgung ?> Stück</td></tr>
        <tr><th>Entsorgungskosten gesamt</th><td><?= number_format($gesamtEntsorgungskosten, 2) ?> €</td></tr>
        <tr><th>Geld gesamt</th><td><?= number_format($gesamtVollerPreis,2) ?> €</td></tr>
        <tr><th>Gewinn Firma</th><td><?= number_format($gesamtGewinnFirma,2) ?> €</td></tr>
        <tr><th>Gewinn FF</th><td><?= number_format($gesamtGewinnFF,2) ?> €</td></tr>
    </table>

    <h3>Liste</h3>
    <table class="table table-bordered">
    <thead>
        <tr>
            <th>Nr</th>
            <th>Name</th>
            <th>Preis</th>
            <th>Bezahlt</th>
            <th>Status</th>
            <th>Abgeholt</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($gefilterteLoscher as $v): ?>
    <?php
    $isGeprueft = (bool)$v['geprueft'];
    $isBezahlt = (bool)$v['bezahlt'];
    $isDefekt = (bool)$v['defekt'];
    $isAbgeholt = (bool)$v['abgeholt'];

    if (!$isGeprueft) {
        // 1. Nicht geprüft -> Orange
        $class = 'status-orange';
    } elseif ($isBezahlt && $isDefekt && !$isAbgeholt) {
        // 2. Bezahlt, defekt, nicht abgeholt -> Hellgrün
        $class = 'status-hellgruen';
    } elseif (!$isBezahlt && $isDefekt && $isAbgeholt) {
        // 3. Nicht bezahlt, defekt, abgeholt -> Hellgrün
        $class = 'status-hellgruen';
    } elseif ($isBezahlt && !$isDefekt && $isAbgeholt) {
        // 4. Bezahlt, ok (nicht defekt), abgeholt -> Grün
        $class = 'status-gruen';
    } else {
        // 5. Alles andere -> Rot
        $class = 'status-rot';
    }
    ?>
    <tr>
        <td class="<?= $class ?>"><?= sprintf("%03d", $v['nummer']) ?></td>
        <td class="<?= $class ?>"><?= htmlspecialchars($v['name']) ?></td>
        <td class="<?= $class ?>"><?= number_format($v['vollpreis'],2) ?> €</td>
        <td class="<?= $class ?>">
            <?= $v['bezahlt'] == 1 ? 'Ja &#10004;' : 'Nein &#10060;' ?>
        </td>
        <td class="<?= $class ?>"><?= $v['statusText'] ?></td>
        <td class="<?= $class ?>">
            <?= $v['abgeholt'] == 1 ? 'Abgeholt' : 'Nicht abgeholt &#10060;' ?>
        </td>
    </tr>
<?php endforeach; ?>
    </tbody>
</table>

</div>
</body>
</html>