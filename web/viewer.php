<?php
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$db = getDB();

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

function percent($teil, $gesamt) {
    return $gesamt > 0 ? round(($teil / $gesamt) * 100) : 0;
}

function getStats($db) {
    $stats = [];

    $stats['gesamt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE active = 1");


    $stats['geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1 AND active = 1 AND defekt = 0");
    $stats['nicht_geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 0 AND active = 1 AND defekt = 0");

    $stats['abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 1 AND active = 1 AND defekt = 0");
    $stats['nicht_abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 0 AND active = 1 AND defekt = 0");

    $stats['bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 1 AND active = 1 AND defekt = 0");
    $stats['nicht_bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 0 AND active = 1 AND defekt = 0");

    $stats['ok'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 0 AND active = 1");
    $stats['defekt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 1 AND active = 1");

    $stats['gesamt_ok'] = $stats['gesamt'] - $stats['defekt'];

    $stats['p_defekt'] = percent($stats['defekt'], $stats['gesamt']);
    $stats['p_geprueft'] = percent($stats['geprueft'], $stats['gesamt_ok']);
    $stats['p_abgeholt'] = percent($stats['abgeholt'], $stats['gesamt_ok']);
    $stats['p_bezahlt'] = percent($stats['bezahlt'], $stats['gesamt_ok']);

    return $stats;
}

if (isset($_GET['ajax_stats'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status'=>'ok', 'stats'=>getStats($db)]);
    exit;
}

$stats = getStats($db);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🔥 Feuerlöscher TV Viewer</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
    background-color: #f5f5f5;
    color: #000;
    font-family: Arial, sans-serif;
}

h1 {
    text-align: center;
    font-size: 4rem;
    margin-bottom: 3rem;
}

.container {
    max-width: 95%;
}

/* Gleich hohe Karten */
.row.equal-height > [class*='col'] {
    display: flex;
}

.card {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 180px;
    border-radius: 12px;
    border: 2px solid #ccc;
    background: #fff;
}

.card h6 {
    font-size: 2rem;
    margin-bottom: 1rem;
}

.big-number {
    font-size: 3.5rem;
    font-weight: bold;
}

.split {
    display: flex;
    justify-content: space-between;
    font-size: 2.5rem;
    font-weight: bold;
}

.ok {
    color: green;
}

.nok {
    color: red;
}

/* Progress */
label {
    font-size: 3rem;
    font-weight: bold;
}

label span {
    font-size: 3.2rem;
}

.progress {
    height: 4rem;
    background-color: #ddd;
    border-radius: 10px;
}

.progress-bar {
    font-size: 2.5rem;
    font-weight: bold;
}

.split {
    display: flex;
    justify-content: center;
    gap: 40px;
}
</style>
</head>

<body>

<div class="container mt-5">

<h1>🔥 Feuerlöscher Übersicht</h1>

<div class="row row-cols-2 row-cols-md-5 mb-5 equal-height">

    <div class="col">
        <div class="card text-center p-3">
            <div>
                <h6>Gesamt</h6>
                <div id="stat-gesamt" class="big-number"><?= $stats['gesamt'] ?></div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card text-center p-3">
            <div>
                <h6>Bezahlt</h6>
                <div id="stat-bezahlt" class="split">
                    <span class="ok">✅ <?= $stats['bezahlt'] ?></span>
                    <span class="nok">❌ <?= $stats['nicht_bezahlt'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card text-center p-3">
            <div>
                <h6>Geprüft</h6>
                <div id="stat-geprueft" class="split">
                    <span class="ok">✅ <?= $stats['geprueft'] ?></span>
                    <span class="nok">❌ <?= $stats['nicht_geprueft'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card text-center p-3">
            <div>
                <h6>OK | DEFEKT</h6>
                <div id="stat-ok" class="split">
                    <span class="ok">✅ <?= $stats['ok'] ?></span>
                    <span class="nok">❌ <?= $stats['defekt'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card text-center p-3">
            <div>
                <h6>Abgeholt</h6>
                <div id="stat-abgeholt" class="split">
                    <span class="ok">✅ <?= $stats['abgeholt'] ?></span>
                    <span class="nok">❌ <?= $stats['nicht_abgeholt'] ?></span>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Progressbars -->
<label>Bezahlt<!-- (<span id="label-p-bezahlt"><?= $stats['p_bezahlt'] ?></span>%)--></label>
<div class="progress mb-4">
    <div class="progress-bar bg-success" id="bar-bezahlt" style="width: <?= $stats['p_bezahlt'] ?>%">
        <?= $stats['p_bezahlt'] ?>%
    </div>
</div>

<label>Geprüft<!-- (<span id="label-p-geprueft"><?= $stats['p_geprueft'] ?></span>%)--></label>
<div class="progress mb-4">
    <div class="progress-bar" id="bar-geprueft" style="width: <?= $stats['p_geprueft'] ?>%">
        <?= $stats['p_geprueft'] ?>%
    </div>
</div>

<label>Defekt<!-- (<span id="label-p-defekt"><?= $stats['p_defekt'] ?></span>%)--></label>
<div class="progress mb-4">
    <div class="progress-bar bg-danger" id="bar-defekt" style="width: <?= $stats['p_defekt'] ?>%">
        <?= $stats['p_defekt'] ?>%
    </div>
</div>

<label>Abgeholt<!-- (<span id="label-p-abgeholt"><?= $stats['p_abgeholt'] ?></span>%)--></label>
<div class="progress mb-4">
    <div class="progress-bar" id="bar-abgeholt" style="width: <?= $stats['p_abgeholt'] ?>%">
        <?= $stats['p_abgeholt'] ?>%
    </div>
</div>

</div>

<script>
function updateStatsDOM(stats){
    document.getElementById('stat-gesamt').innerText = stats.gesamt;

    document.getElementById('stat-geprueft').innerHTML =
        `<span class="ok">✅ ${stats.geprueft}</span><span class="nok">❌ ${stats.nicht_geprueft}</span>`;

    document.getElementById('stat-abgeholt').innerHTML =
        `<span class="ok">✅ ${stats.abgeholt}</span><span class="nok">❌ ${stats.nicht_abgeholt}</span>`;

    document.getElementById('stat-bezahlt').innerHTML =
        `<span class="ok">✅ ${stats.bezahlt}</span><span class="nok">❌ ${stats.nicht_bezahlt}</span>`;

    document.getElementById('stat-ok').innerHTML =
        `<span class="ok">✅ ${stats.ok}</span><span class="nok">❌ ${stats.defekt}</span>`;

    updateBar('geprueft', stats.p_geprueft);
    updateBar('abgeholt', stats.p_abgeholt);
    updateBar('bezahlt', stats.p_bezahlt);
    updateBar('defekt', stats.p_defekt);
}

function updateBar(name, value){
    document.getElementById('bar-'+name).style.width = value+'%';
    document.getElementById('bar-'+name).innerText = value+'%';
    //document.getElementById('label-p-'+name).innerText = value;
}

function refreshStats(){
    fetch('viewer.php?ajax_stats=1')
    .then(res => res.json())
    .then(data => {
        if(data.status === 'ok'){
            updateStatsDOM(data.stats);
        }
    });
}

setInterval(refreshStats, 3000);
</script>

</body>
</html>