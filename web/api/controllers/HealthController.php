<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/ErrorLog.php';
require_once __DIR__ . '/../models/Loescher.php';
require_once __DIR__ . '/../models/Rechnung.php';

class HealthController {
    public function status() {

        $result = [
            "status" => "ok",
            "timestamp" => date('Y-m-d H:i:s')
        ];

        try {
            global $dbPath;
            $db = getDB();

            // Tabellen lesen
            $tables = [];
            $res = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
            
            $result["_debug_res_type"] = gettype($res);
            $result["_debug_res_false"] = ($res === false);
            
            if ($res === false) {
                ErrorLog::write("SQLite Query fehlgeschlagen: " . $db->lastErrorMsg());
                $result["_error"] = $db->lastErrorMsg();
            } else {
                $count = 0;
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                    $tables[] = $r['name'];
                    $count++;
                }
                $result["_debug_rows"] = $count;
            }

            $result["database"] = "connected";
            $result["tables"] = $tables;

            // Tabellen zählen
            $result["count"] = [
                "loescher" => in_array("loescher", $tables)
                    ? $db->querySingle("SELECT COUNT(*) FROM loescher")
                    : null,

                "rechnungen" => in_array("rechnungen", $tables)
                    ? $db->querySingle("SELECT COUNT(*) FROM rechnungen")
                    : null
            ];

        } catch (Throwable $t) {
            ErrorLog::write("HealthCheck Fehler: " . $t->getMessage());
            return Response::json(
                ["status" => "error", "message" => $t->getMessage()],
                500
            );
        }

        return Response::json($result);
    }
}