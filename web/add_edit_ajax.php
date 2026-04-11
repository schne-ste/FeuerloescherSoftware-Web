<?php 
require 'config.php';
$db = getDB();

// Sicherheits-Check: Eingeloggt?
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Logout-Logik (optional, falls direkt aufgerufen)
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Parameter prüfen
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

// Datenbankabfrage
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

$isActive = ($entry['active'] ?? 0) == 1;

?>
<?php if($module == "print") { ?>
    <div class="col-md-6" id="print">
        <label class="form-label fw-bold small text-uppercase text-muted mb-3">Druck</label>
        
	<div class="mb-3">
	    <!-- Etikette -->
	    <div class="d-flex justify-content-between align-items-center mb-1">
	        <span class="small text-muted">
	            Etikette: 
	            <span class="badge <?= $entry['etikett_gedruckt'] == 1 ? 'bg-success' : 'bg-danger' ?>">
	                <?= $entry['etikett_gedruckt'] == 1 ? 'Gedruckt' : 'Offen' ?>
	            </span>
	        </span>
	        <span>
	            <?= $entry['etikett_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
	        </span>
	    </div>
	    <button type="submit" name="redruck_etikett" class="btn btn-outline-secondary btn-sm w-100" <?= !$isActive ? 'disabled' : '' ?>>
	        &#127991; Nachdrucken
	    </button>
	</div>
	
	<div class="mb-3">
	    <!-- Abholschein -->
	    <div class="d-flex justify-content-between align-items-center mb-1">
	        <span class="small text-muted">
	            Abholschein: 
	            <span class="badge <?= $entry['abholschein_gedruckt'] == 1 ? 'bg-success' : 'bg-danger' ?>">
	                <?= $entry['abholschein_gedruckt'] == 1 ? 'Gedruckt' : 'Offen' ?>
	            </span>
	        </span>	
	        <span>
	            <?= $entry['abholschein_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
	        </span>
	    </div>
	    <button type="submit" name="redruck_abholschein" class="btn btn-outline-secondary btn-sm w-100" <?= !$isActive ? 'disabled' : '' ?>>
	        &#129534; Nachdrucken
	    </button>
	</div>
<?php } ?>

<?php if($module == "status") { ?>
    <div class="col-md-6 border-end" id="status">
        <label class="form-label fw-bold small text-uppercase text-muted mb-3">Statusübersicht</label>
        
        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="bezahlt" class="form-check-input" id="bezahltCheck" <?= $entry['bezahlt'] ? 'checked' : '' ?> <?= !$isActive ? 'disabled' : '' ?>>
            <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
        </div>

        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="geprueft" class="form-check-input" id="geprueftCheck" <?= $entry['geprueft'] ? 'checked' : '' ?> <?= !$isActive ? 'disabled' : '' ?>>
            <label class="form-check-label" for="geprueftCheck">&#129514; Geprüft</label>
        </div>

        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="abgeholt" class="form-check-input" id="abgeholtCheck" <?= $entry['abgeholt'] ? 'checked' : '' ?> <?= !$isActive ? 'disabled' : '' ?>>
            <label class="form-check-label" for="abgeholtCheck">&#128230; Abgeholt</label>
        </div>
        
		<div class="form-check mb-2" style="<?= ($entry['defekt'] == -1) ? 'filter: grayscale(1); opacity: 0.5;' : '' ?>">
		    <input type="checkbox" 
		           onfocus="pausePolling()" 
		           onblur="resumePolling()" 
		           oninput="markDirty()" 
		           name="defekt" 
		           class="form-check-input" 
		           id="defektCheck" 
		           <?= ($entry['defekt'] == 1) ? 'checked' : '' ?>
                   <?= !$isActive ? 'disabled' : '' ?>>
		    <label class="form-check-label text-danger" for="defektCheck">
		        &#9940; Defekt <?= ($entry['defekt'] == -1) ? '<small class="text-muted">(noch ungeprüft)</small>' : '' ?>
		    </label>
		</div>
    </div>
<?php } ?>

<?php if($module == "infotext") { 
    $infoText = $entry['info'] ?? '';
    $lineCount = substr_count($infoText, "\n") + 1;
    $rows = max(1, $lineCount);
?>
    <div class="mb-3" id="infotext">
        <label class="form-label">&#128161; Info</label>
        <textarea 
            name="info" 
            class="form-control" 
            onfocus="pausePolling()" 
            onblur="resumePolling()" 
            oninput="markDirty(); this.rows = (this.value.split('\n').length || 1);" 
            style="resize:none; overflow:hidden;"
            rows="<?= $rows ?>" 
            <?= !$isActive ? 'disabled' : '' ?>
        ><?= htmlspecialchars($infoText) ?></textarea>
    </div>
<?php } ?>