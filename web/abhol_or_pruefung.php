<?php
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$db = getDB();

$eintrag = null;
$message = "";
$statusType = ""; // success, error, warning
$soundType = "";

$modus = $_POST['modus'] ?? "abholen";
$nummer = $_POST['nummer'] ?? null;
$bedienmodus = $_POST['bedienmodus'] ?? "scanner";

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// =====================
// AKTION
// =====================
if (isset($_POST['aktion']) && $nummer) {
    $nummerSafe = (int)$nummer;

    if ($modus === "abholen") {

        $check = $db->query("
            SELECT bezahlt, defekt, active FROM loescher 
            WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
        ");
        $row = $check->fetchArray();

        if (!$row) {
            $message = "&#10060; Nicht gefunden!";
            $statusType = "error";
            $soundType = "error";
        } elseif ($row['active'] && $row['defekt']) {
            $message = "&#9888; Löscher defekt – Geld bitte retour!";
            $statusType = "warning";
            $soundType = "warning";
        } elseif ($row['active'] && !$row['bezahlt']) {
            $message = "&#128176; Nicht bezahlt – zuerst kassieren!";
            $statusType = "error";
            $soundType = "warning";
        } else {
            $db->exec("
                UPDATE loescher 
                SET abgeholt = CASE WHEN abgeholt = 1 THEN 0 ELSE 1 END
                WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
            ");
            $message = "&#9989; Abholung erfolgreich!";
            $statusType = "success";
            $soundType = "success";
        }
    }

    if ($modus === "pruefen") {
        $db->exec("
            UPDATE loescher 
            SET geprueft = CASE WHEN geprueft = 1 THEN 0 ELSE 1 END
            WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
        ");
        $message = "&#9989; Prüfung erledigt!";
        $statusType = "success";
        $soundType = "success";
    }
}

// =====================
// DATEN LADEN
// =====================
if ($nummer) {
    $nummerSafe = (int)$nummer;

    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    if (!$eintrag) {
        $message = "&#10060; Nicht gefunden!";
        $statusType = "error";
        $soundType = "error";
    }
}

// =====================
// INFO SETZEN
// =====================
if (isset($_POST['setInfo']) && $nummer) {
    $nummerSafe = (int)$nummer;

    $db->exec("
        UPDATE loescher 
        SET info = 'Schaummittel muss getauscht werden'
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");

    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    $message = "&#9888; Hinweis gesetzt!";
    $statusType = "warning";
    $soundType = "warning";
}

// =====================
// DEFEKT SETZEN
// =====================
if (isset($_POST['setDefekt']) && $nummer) {
    $nummerSafe = (int)$nummer;

    $db->exec("
        UPDATE loescher
        SET defekt = 1
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");

    $result = $db->query("
        SELECT * FROM loescher
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    $message = "&#9940; Datensatz als defekt markiert!";
    $statusType = "error";
    $soundType = "warning";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>&#128293; Feuerlöscher Software</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body.flash-success { background-color: #d4edda !important; }
body.flash-error { background-color: #f8d7da !important; }
body.flash-warning { background-color: #fff3cd !important; }

@keyframes blink {
    0% { background-color: #f8d7da; }
    50% { background-color: #ffffff; }
    100% { background-color: #f8d7da; }
}
.flash-error {
    animation: blink 0.4s ease-in-out 2;
}
</style>
</head>

<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            &#128293; Feuerlöscher Software - &#128230; &#129514; Abhol- / Prüfstation
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

<div class="container mt-4">

<div class="row">

    <div class="col-md-3">

        <form method="post" id="mainForm" class="card p-3 mb-3">

            <div class="mb-2">
                <label class="form-label small">Station</label>
                <select name="modus" class="form-select form-select-sm">
                    <option value="abholen" <?= $modus === 'abholen' ? 'selected' : '' ?>>
                        &#128230;  Abholstation
                    </option>
                    <option value="pruefen" <?= $modus === 'pruefen' ? 'selected' : '' ?>>
                        &#129514; Prüfstation
                    </option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label small">Modus</label>
                <select name="bedienmodus" id="bedienmodus" class="form-select form-select-sm">
                    <option value="scanner" <?= $bedienmodus === 'scanner' ? 'selected' : '' ?>>
                        &#9889; Scanner
                    </option>
                    <option value="manuell" <?= $bedienmodus === 'manuell' ? 'selected' : '' ?>>
                        &#9000; Manuell
                    </option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label small">&#128269; Nummer</label>
                <input type="text"
                       name="nummer"
                       id="nummerInput"
                       class="form-control form-control-sm"
                       inputmode="numeric"
                       value="<?= htmlspecialchars($nummer ?? '') ?>">
            </div>

        </form>

        <?php if ($message): ?>
        <div class="alert alert-<?= $statusType === 'error' ? 'danger' : ($statusType === 'success' ? 'success' : 'warning') ?> p-2 small">
            <?= $message ?>
        </div>
        <?php endif; ?>

    </div>


    <!--  RECHTE SPALTE (groß) -->
    <div class="col-md-9">

    <?php if ($eintrag && $modus): ?>
    <div class="card p-4">

        <h4 class="mb-3">Details</h4>

        <p><strong>Nummer:</strong> <?= htmlspecialchars($eintrag['nummer']) ?></p>
        <p><strong>Name:</strong> <?= htmlspecialchars($eintrag['name']) ?></p>

        <p><strong>Erstellt:</strong>
            <span class="badge bg-secondary">
                <?= !empty($eintrag['zeitstempel']) 
                    ? date('d.m.Y H:i', strtotime($eintrag['zeitstempel'])) 
                    : '-' ?>
            </span>
        </p>

        <?php if ($modus === "abholen"): ?>

            <?php if ($eintrag['active'] && !$eintrag['bezahlt']): ?>
                <div class="alert alert-danger">
                    &#128176; NICHT BEZAHLT → Zur Kassa
                </div>
            <?php elseif ($eintrag['active'] && $eintrag['defekt']): ?>
                <div class="alert alert-warning">
                    &#9888; Defekt – Geld retour!
                </div>
            <?php endif; ?>

            <p><strong>Status:</strong>
                <?= $eintrag['abgeholt']
                    ? '<span class="badge bg-success">Abgeholt</span>'
                    : '<span class="badge bg-warning text-dark">Nicht abgeholt</span>' ?>
            </p>

        <?php else: ?>

            <p><strong>Status:</strong>
                <?= $eintrag['geprueft']
                    ? '<span class="badge bg-success">Geprüft</span>'
                    : '<span class="badge bg-danger">Nicht geprüft</span>' ?>
            </p>

        <?php endif; ?>

        <p><strong>Prüfstatus:</strong>
            <?= empty($eintrag['defekt'])
                ? '<span class="badge bg-success">OK</span>'
                : '<span class="badge bg-danger">DEFEKT</span>' ?>
        </p>

        <?php if (!empty($eintrag['info'])): ?>
            <div class="alert alert-warning mt-3">
                <strong>Hinweis:</strong><br>
                <?= nl2br(htmlspecialchars($eintrag['info'])) ?>
            </div>
        <?php endif; ?>


        <!--  BUTTONS -->
        <?php if ($modus === "pruefen"): ?>

            <form method="post" class="mt-3">
                <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
                <button type="submit" name="setInfo" class="btn btn-warning w-100">
                    &#9888; Schaummittel tauschen
                </button>
            </form>

            <?php if (empty($eintrag['defekt'])): ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
                <button type="submit" name="setDefekt" class="btn btn-danger w-100">
                    &#9940; Löscher defekt
                </button>
            </form>
            <?php endif; ?>

        <?php endif; ?>


        <?php if ($bedienmodus === "manuell"): ?>
        <form method="post" class="mt-3">
            <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
            <input type="hidden" name="modus" value="<?= $modus ?>">
            <input type="hidden" name="bedienmodus" value="manuell">

            <button type="submit" name="aktion" value="1"
                class="btn btn-success w-100"
                <?= (!$eintrag['bezahlt'] && $modus === "abholen") ? 'disabled' : '' ?>>
                &#128260; Status umschalten
            </button>
        </form>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    </div>

</div>

<script>
const input = document.getElementById("nummerInput");
const form = document.getElementById("mainForm");
const bedienmodus = document.getElementById("bedienmodus");

input.addEventListener("input", function () {
    this.value = this.value.replace(/\D/g, '');
});

input.addEventListener("keypress", function(e) {
    if (e.key === "Enter") {

        if (bedienmodus.value !== "scanner") return;
        e.preventDefault();

        if (input.value.trim() === "") return;

        let hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = "aktion";
        hidden.value = "1";
        form.appendChild(hidden);
        form.submit();
    }
});

document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        input.value = "";
        form.submit();
    }
});

window.onload = function() {

    const soundType = "<?= $soundType ?>";

    if (soundType === "success") {
	    document.body.classList.add("flash-success");
	    playTone("success");
	}
	
	if (soundType === "error") {
	    document.body.classList.add("flash-error");
	    playTone("error");
	}
	
	if (soundType === "warning") {
	    document.body.classList.add("flash-warning");
	    playTone("warning");
	}

    setTimeout(()=>{
        document.body.classList.remove("flash-success","flash-error","flash-warning");
    }, 600);

    input.focus();
    input.select();
};

// Web Audio API Töne
function playTone(type) {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = ctx.createOscillator();
    const gainNode = ctx.createGain();

    oscillator.connect(gainNode);
    gainNode.connect(ctx.destination);

    oscillator.type = 'sine';
    gainNode.gain.value = 0.1;

    switch(type) {
        case 'success':
            oscillator.frequency.value = 880; // hoher Ton
            break;
        case 'error':
            oscillator.frequency.value = 220; // tiefer Ton
            break;
        case 'warning':
            oscillator.frequency.value = 440; // mittlerer Ton
            break;
    }

    oscillator.start();
    setTimeout(() => oscillator.stop(), 150);
}
</script>

</body>
</html>