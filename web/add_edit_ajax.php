<?php 
require 'config.php';
$db = getDB();
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (isset($_GET['number']) && is_numeric($_GET['number'])) {
    $number = (int)$_GET['number'];
} else {
    die("number not provided");
}

if(isset($_GET['module'])) {
    $module = $_GET['module'];
} else {
    die("module not provided");
}

$result = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $number");

if ($result) {
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    if (count($rows) === 1) {
        $entry = $rows[0];
    } elseif (count($rows) > 1) {
        die("multiple rows provided");
    } else {
        die("not found");
    }
}

?>
<? if($module == "print")  { ?>
    <p id="print">
        &#127991; Etikette gedruckt: <?= $entry['etikett_gedruckt'] ? '&#9989;': '&#10060;'?> <br>
        &#129534; Abholschein gedruckt: <?= $entry['abholschein_gedruckt'] ? '&#9989;': '&#10060;' ?>
    </p>
<? } ?>

<? if($module == "status") {  ?>
    <div id="status">
        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="bezahlt" class="form-check-input" id="bezahltCheck"
                <?= $entry['bezahlt'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
        </div>

        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="geprueft" class="form-check-input" id="geprueftCheck" <?= $entry['geprueft'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="geprueftCheck">&#129514; Geprüft</label>
        </div>

        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="abgeholt" class="form-check-input" id="abgeholtCheck" <?= $entry['abgeholt'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="abgeholtCheck">&#128230; Abgeholt</label>
        </div>
        
        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="defekt" class="form-check-input" id="defektCheck" <?= $entry['defekt'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="defektCheck">&#9940; Defekt</label>
        </div>
    </div>
<? } ?>

<? if($module=="infotext") { ?>
    <div class="mb-3" id="infotext">
        <label class="form-label">&#8505; Info</label>
        <textarea name="info" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" class="form-control" rows="3"><?= htmlspecialchars($entry['info']) ?></textarea>
    </div>
<? } ?>
