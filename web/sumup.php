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
        $baseAmount = (float)($row['anzahl_loescher'] * $row['preis_pro_loescher']);
        $amount = $baseAmount * (defined('SumUp_PRICE_FAKTOR') ? (float)SumUp_PRICE_FAKTOR : 1.0);
        
        $res = apiRequest("POST", "", [
            "title" => $row['rechnungsnummer'], 
            "amount" => $amount 
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
        
        if (isset($res['error']) || (isset($res['hidden']) && $res['hidden'] == 1)) {
            $db->exec("UPDATE rechnungen SET sumup_status = 'cancelled', sumup_transaction_id = NULL WHERE id = $rechnung_id");
            echo json_encode(["status" => "failed", "error" => "Zahlung wurde abgebrochen"]);
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
            apiRequest("DELETE", "", ["id" => $row['sumup_transaction_id']]);
            $db->exec("UPDATE rechnungen SET sumup_status = 'cancelled', sumup_transaction_id = NULL WHERE id = $rechnung_id");
        }
        echo json_encode(["success" => true]);
        exit;
    }
}

// FRONTEND-BERECHHNUNG
$rechnung_id = (int)$_GET['rechnung_id'];
$row = $db->query("SELECT anzahl_loescher, preis_pro_loescher FROM rechnungen WHERE id = $rechnung_id")->fetchArray(SQLITE3_ASSOC);
$baseAmount = $row['anzahl_loescher'] * $row['preis_pro_loescher'];

$faktor = defined('SumUp_PRICE_FAKTOR') ? (float)SumUp_PRICE_FAKTOR : 1.0;
$finalAmount = $baseAmount * $faktor;
$displayAmount = number_format($finalAmount, 2, ',', '.');

// Prozentwert für die Anzeige berechnen (z.B. 1.02 -> 2%)
$gebuehrProzent = 0;
if ($faktor > 1) {
    $gebuehrProzent = round(($faktor - 1) * 100, 2);
}
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
        .status-text { margin: 20px 0; font-size: 17px; color: #666; line-height: 1.5; }
        .fee-notice { font-size: 13px; color: #7f8c8d; margin-top: 8px; font-style: italic; display: block; }
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
        const gebuehrProzent = <?= $gebuehrProzent ?>;
        let interval = null;
        
        async function init() {
            try {
                let res = await fetch(`sumup.php?action=create&rechnung_id=${rid}`);
                let data = await res.json();
                if(data.success) {
                    // Setzt den Text und hängt den Gebührenhinweis unten an, falls vorhanden
                    let statusHtml = "Transaktion erstellt.<br><br>Bitte in der SumUp-Adapter-App fortsetzen.<br><br>Warte auf Zahlung...";
                    if(gebuehrProzent > 0) {
                        statusHtml += `<span class="fee-notice">(Inkl. ${gebuehrProzent.toString().replace('.', ',')}% Kartenzahlungsgebühr)</span>`;
                    }
                    document.getElementById('status').innerHTML = statusHtml;
                    checkStatus();
                } else {
                    showError("API Fehler: " + (data.error || "Unbekannt"));
                }
            } catch (e) {
                showError("Verbindung zum Server fehlgeschlagen.");
            }
        }

        function checkStatus() {
            interval = setInterval(async () => {
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
                        showError(data.error || "Zahlung fehlgeschlagen oder storniert.");
                        if(window.opener) window.opener.location.reload();
                        setTimeout(() => window.close(), 2500);
                    }
                } catch (e) {
                    console.error("Status-Check Fehler");
                }
            }, 2000);
        }

        async function cancelPayment() {
            if(interval) clearInterval(interval);

            const btn = document.querySelector('.btn-cancel');
            btn.disabled = true;
            btn.style.background = "#ccc";
            btn.innerText = "Wird storniert...";
            
            try {
                await fetch(`sumup.php?action=cancel&rechnung_id=${rid}`);
                document.getElementById('loader').style.display = "none";
                document.getElementById('status').innerHTML = '<span style="color:#e74c3c; font-weight:bold;">❌ Zahlung wurde abgebrochen.</span>';
                if(window.opener) window.opener.location.reload();
                setTimeout(() => window.close(), 2500);
            } catch (e) {
                showError("Fehler beim Abbrechen der Zahlung.");
            }
        }

        function showError(msg) {
            document.getElementById('status').innerHTML = `<span style="color:#e74c3c; font-weight:bold;">❌ ${msg}</span>`;
            document.getElementById('loader').style.display = "none";
        }

        init();
    </script>
</body>
</html>