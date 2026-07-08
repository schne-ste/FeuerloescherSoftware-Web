<?php
require 'config.php';

$API_URL = SumUp_URL; 
$API_KEY = SumUp_API_KEY;

function apiRequest($method, $endpoint = "", $data = null) {
    global $API_URL, $API_KEY;
    $url = $API_URL . $endpoint . (strpos($endpoint, '?') === false ? "?" : "&") . "api_key=" . $API_KEY;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400 || !$response) {
        return ["error" => "api_error", "status" => $httpCode];
    }

    return json_decode($response, true);
}

$db = getDB();
$action = $_GET['action'] ?? null;

if ($action) {
    header("Content-Type: application/json");
    $rechnung_id = (int)($_REQUEST['rechnung_id'] ?? 0);

    // 1. TRANSAKTION ERSTELLEN
    if ($action === "create") {
        $row = $db->query("SELECT rechnungsnummer, anzahl_loescher, preis_pro_loescher FROM rechnungen WHERE id = $rechnung_id")->fetchArray(SQLITE3_ASSOC);
        if (!$row) die(json_encode(["error" => "Rechnung nicht gefunden"]));

        // API erwartet Betrag in Cents (Integer)
        $amount = (float)($row['anzahl_loescher'] * $row['preis_pro_loescher']);
        
        $res = apiRequest("POST", "", [
            "title" => $row['rechnungsnummer'], 
            "amount" => $amount // Die apiRequest wandelt intern korrekt um, falls nötig, oder wir senden es als float
        ]);

        if (!empty($res['id'])) {
            $stmt = $db->prepare("UPDATE rechnungen SET sumup_transaction_id = :tid, sumup_status = 'pending' WHERE id = :id");
            $stmt->bindValue(':tid', $res['id']);
            $stmt->bindValue(':id', $rechnung_id);
            $stmt->execute();
            echo json_encode(["success" => true, "transaction_id" => $res['id']]);
        } else {
            echo json_encode(["error" => "API Fehler beim Erstellen", "details" => $res]);
        }
        exit;
    }

    // 2. STATUS ABFRAGEN
    if ($action === "status") {
        $row = $db->query("SELECT sumup_transaction_id, bezahlt FROM rechnungen WHERE id = $rechnung_id")->fetchArray(SQLITE3_ASSOC);
        
        if (!$row || empty($row['sumup_transaction_id'])) {
            echo json_encode(["status" => "failed", "error" => "Keine Transaktions-ID"]);
            exit;
        }

        $res = apiRequest("GET", "?id=" . $row['sumup_transaction_id']);
        
        // Wenn API 404 liefert (hidden=1), ist res['error'] gesetzt durch unsere apiRequest Funktion
        if (isset($res['error'])) {
            echo json_encode(["status" => "failed", "error" => "Transaktion ungültig oder gelöscht"]);
            exit;
        }

        $paid = !empty($res['paid']);
        if ($paid && !$row['bezahlt']) {
            $db->exec("UPDATE rechnungen SET bezahlt = 1, sumup_status = 'paid' WHERE id = $rechnung_id");
        }
        echo json_encode(["paid" => $paid, "status" => $paid ? 'paid' : 'pending']);
        exit;
    }

    // 3. STORNIEREN (DELETE)
    if ($action === "cancel") {
        $row = $db->query("SELECT sumup_transaction_id FROM rechnungen WHERE id = $rechnung_id")->fetchArray(SQLITE3_ASSOC);
        
        if ($row && !empty($row['sumup_transaction_id'])) {
            // Deine API verlangt die ID im JSON-Body für DELETE
            apiRequest("DELETE", "", ["id" => $row['sumup_transaction_id']]);
            
            $db->exec("UPDATE rechnungen SET sumup_status = 'cancelled', sumup_transaction_id = NULL WHERE id = $rechnung_id");
        }
        echo json_encode(["success" => true]);
        exit;
    }
}

// FRONTEND
$rechnung_id = (int)$_GET['rechnung_id'];
$row = $db->query("SELECT anzahl_loescher, preis_pro_loescher FROM rechnungen WHERE id = $rechnung_id")->fetchArray(SQLITE3_ASSOC);
$displayAmount = number_format($row['anzahl_loescher'] * $row['preis_pro_loescher'], 2, ',', '.');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SumUp Terminal</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 40px; background: #f4f4f9; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: inline-block; min-width: 250px; }
        .amount { font-size: 42px; font-weight: bold; color: #333; margin-bottom: 10px; }
        .status-text { margin: 20px 0; font-size: 18px; color: #666; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .btn-cancel { background: #e74c3c; color: white; border: none; padding: 12px 24px; cursor: pointer; border-radius: 6px; font-size: 16px; transition: background 0.3s; }
        .btn-cancel:hover { background: #c0392b; }
        .btn-cancel:disabled { background: #ccc; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="card">
        <div class="amount"><?= $displayAmount ?> €</div>
        <div id="status" class="status-text">Initialisiere Terminal...</div>
        <div class="spinner" id="loader"></div>
        <button class="btn-cancel" onclick="cancelPayment()">Zahlung abbrechen</button>
    </div>

    <script>
        const rid = <?= $rechnung_id ?>;
        
        async function init() {
            try {
                let res = await fetch(`sumup.php?action=create&rechnung_id=${rid}`);
                let data = await res.json();
                if(data.success) {
                    document.getElementById('status').innerText = "Transaktion erstellt \n\nBitte in der SumUp-Adaper-App fortsetzen.\n\n Warte auf Zahlung...";
                    checkStatus();
                } else {
                    showError("API Fehler: " + (data.error || "Unbekannt"));
                }
            } catch (e) {
                showError("Verbindung zum Server fehlgeschlagen.");
            }
        }

        function checkStatus() {
            let interval = setInterval(async () => {
                try {
                    let res = await fetch(`sumup.php?action=status&rechnung_id=${rid}`);
                    let data = await res.json();

                    if (data.paid) {
                        clearInterval(interval);
                        document.getElementById('status').innerHTML = "<span style='color:green; font-weight:bold;'>✅ Zahlung erfolgreich!</span>";
                        document.getElementById('loader').style.display = "none";
                        if(window.opener) window.opener.location.reload();
                        setTimeout(() => window.close(), 2000);
                    } 
                    else if (data.status === 'failed') {
                        clearInterval(interval);
                        showError("Zahlung fehlgeschlagen oder storniert.");
                    }
                } catch (e) {
                    console.error("Status-Check Fehler");
                }
            }, 2000);
        }

        async function cancelPayment() {
            const btn = document.querySelector('.btn-cancel');
            btn.disabled = true;
            btn.innerText = "Wird storniert...";
            
            await fetch(`sumup.php?action=cancel&rechnung_id=${rid}`);
            document.getElementById('status').innerText = "Zahlung wurde abgebrochen.";
            setTimeout(() => window.close(), 1200);
        }

        function showError(msg) {
            document.getElementById('status').innerHTML = `<span style="color:red; font-weight:bold;">❌ ${msg}</span>`;
            document.getElementById('loader').style.display = "none";
        }

        init();
    </script>
</body>
</html>