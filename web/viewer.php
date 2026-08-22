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

    $stats['geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1 AND active = 1");
    $stats['nicht_geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 0 AND active = 1");

    $stats['abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 1 AND active = 1");
    $stats['nicht_abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 0 AND active = 1");

    $stats['bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 1 AND active = 1 AND defekt=0");
    $stats['nicht_bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 0 AND active = 1 AND defekt=0");

    $stats['ok'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 0 AND active = 1");
    $stats['defekt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 1 AND active = 1");

    $stats['gesamt_ok'] = $stats['gesamt'] - $stats['defekt'];

    $stats['p_defekt'] = percent($stats['defekt'], $stats['gesamt']);
    $stats['p_geprueft'] = percent($stats['geprueft'], $stats['gesamt']);
    $stats['p_abgeholt'] = percent($stats['abgeholt'], $stats['gesamt']);
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
html, body {
    height: 100vh;
    width: 100vw;
    margin: 0;
    padding: 0;
    overflow: hidden;
    background-color: #f5f5f5;
    color: #000;
    font-family: Arial, sans-serif;
}

.container-fluid {
    height: 100vh;
    max-width: 98vw;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 1.5vh 2vw !important;
}

h1 {
    text-align: center;
    font-size: min(4vh, 3.5vw);
    margin: 0 0 1vh 0;
    font-weight: bold;
}

.card-grid {
    display: flex;
    gap: 1vw;
    margin-bottom: 1.5vh;
}

.card-item {
    flex: 1;
}

.card {
    height: 110%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 1vh !important;
    border-radius: 12px;
    border: 2px solid #ccc;
    background: #fff;
}

.card h6 {
    font-size: min(2.2vh, 1.8vw);
    margin-bottom: 0.5vh;
    white-space: nowrap;
}

.big-number {
    font-size: min(5vh, 4vw);
    font-weight: bold;
    line-height: 1;
}

.split {
    display: flex;
    justify-content: center;
    gap: 1.5vw;
    font-size: min(3.8vh, 3vw);
    font-weight: bold;
    line-height: 1;
}

.ok {
    color: green;
}

.nok {
    color: red;
}

.progress-section {
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    flex-grow: 1;
    max-height: 55vh;
}

.progress-group {
    display: flex;
    flex-direction: column;
}

label {
    font-size: min(2.5vh, 2vw);
    font-weight: bold;
    margin-bottom: 0.3vh;
}

label span {
    font-size: min(2.7vh, 2.2vw);
}

.progress {
    height: min(8vh, 150px);
    background-color: #ddd;
    border-radius: 8px;
}

.progress-bar {
    font-size: min(2.5vh, 1.8vw);
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
</head>

<body>

<div class="container-fluid">

<h1>🔥 Feuerlöscher Übersicht</h1>

<div class="card-grid">

    <div class="card-item">
        <div class="card text-center">
            <div>
                <h5>Gesamt</h5>
                <div id="stat-gesamt" class="big-number"><?= $stats['gesamt'] ?></div>
            </div>
        </div>
    </div>

    <!--<div class="card-item">
        <div class="card text-center">
            <div>
                <h5>Bezahlt</h5>
                <div id="stat-bezahlt" class="split">
                    <span class="ok">✅ <?= $stats['bezahlt'] ?></span>
                    <span class="nok">❌ <?= $stats['nicht_bezahlt'] ?></span>
                </div>
            </div>
        </div>
    </div>-->

    <div class="card-item">
        <div class="card text-center">
            <div>
                <h5>Geprüft</h5>
                <div id="stat-geprueft" class="split">
                    <span class="ok">✅ <?= $stats['geprueft'] ?></span>
                    <span class="nok">❌ <?= $stats['nicht_geprueft'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!--<div class="card-item">
        <div class="card text-center">
            <div>
                <h5>OK | DEFEKT</h5>
                <div id="stat-ok" class="split">
                    <span class="ok">✅ <?= $stats['ok'] ?></span>
                    <span class="nok">❌ <?= $stats['defekt'] ?></span>
                </div>
            </div>
        </div>
    </div>-->

    <div class="card-item">
        <div class="card text-center">
            <div>
                <h5>Abgeholt</h5>
                <div id="stat-abgeholt" class="split">
                    <span class="ok">✅ <?= $stats['abgeholt'] ?></span>
                    <span class="nok">❌ <?= $stats['nicht_abgeholt'] ?></span>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="progress-section">
    <!--<div class="progress-group">
        <label>Bezahlt</label>
        <div class="progress">
            <div class="progress-bar bg-success" id="bar-bezahlt" style="width: <?= $stats['p_bezahlt'] ?>%">
                <?= $stats['p_bezahlt'] ?>%
            </div>
        </div>
    </div>-->

    <div class="progress-group">
        <label>Geprüft</label>
        <div class="progress">
            <div class="progress-bar" id="bar-geprueft" style="width: <?= $stats['p_geprueft'] ?>%">
                <?= $stats['p_geprueft'] ?>%
            </div>
        </div>
    </div>

    <div class="progress-group">
        <label>Defekt</label>
        <div class="progress">
            <div class="progress-bar bg-danger" id="bar-defekt" style="width: <?= $stats['p_defekt'] ?>%">
                <?= $stats['p_defekt'] ?>%
            </div>
        </div>
    </div>
    <hr>
    <div class="progress-group">
        <label>Abgeholt</label>
        <div class="progress">
            <div class="progress-bar" id="bar-abgeholt" style="width: <?= $stats['p_abgeholt'] ?>%">
                <?= $stats['p_abgeholt'] ?>%
            </div>
        </div>
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