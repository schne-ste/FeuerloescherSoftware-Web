<?php
require_once __DIR__ . '/../config/database.php';

class Rechnung {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Hilfsmethode: Reichert ein Rechnungs-Array anhand des Namens mit den 
     * korrekten Löschern, Anzahlen und Preisstrukturen aus der Datenbank an.
     */
    private function enrichRechnungData($r) {
        if (!$r) return $r;

        $name = $r['name'] ?? '';
        $searchName = mb_strtolower(trim($name));

        // 1. Gesamtzahl der Löscher aus der Löscher-Tabelle ermitteln
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) AS anzahl 
            FROM loescher 
            WHERE LOWER(TRIM(name)) = :name 
              AND (active = 1 OR active = '1')
        ");
        $stmtCount->bindValue(':name', $searchName, SQLITE3_TEXT);
        $resCount = $stmtCount->execute()->fetchArray(SQLITE3_ASSOC);
        $gesamtAnzahl = $resCount ? (int)$resCount['anzahl'] : 0;

        // 2. Wenn KEINE Löscher in der Tabelle gefunden wurden, die direkt in der Rechnung gespeicherten Werte nutzen
        if ($gesamtAnzahl === 0) {
            // Behalte die in der Rechnung hinterlegte Anzahl und den Preis bei
            $anzahlRechnung = isset($r['anzahl_loescher']) ? (int)$r['anzahl_loescher'] : 0;
            $preisRechnung = isset($r['preis_pro_loescher']) ? (float)$r['preis_pro_loescher'] : 0.0;
            
            $preisFmt = number_format($preisRechnung, 2, ',', '');
            
            if ($preisRechnung == 0.0) {
                $r['loescher_text'] = "{$anzahlRechnung}x Löscher gratis";
            } else {
                $r['loescher_text'] = "{$anzahlRechnung}x Löscher je {$preisFmt}€";
            }

            $r['preis_pro_loescher'] = [
                [
                    'anzahl' => $anzahlRechnung,
                    'preis_pro_loescher' => $preisRechnung
                ]
            ];
            return $r;
        }

        // 3. Wenn Löscher vorhanden sind, Anzahl setzen und Preisgruppen aufbauen
        $r['anzahl_loescher'] = $gesamtAnzahl;

        $stmtTypen = $this->db->prepare("
            SELECT typ, COUNT(*) AS anzahl, preis 
            FROM loescher 
            WHERE LOWER(TRIM(name)) = :name 
              AND (active = 1 OR active = '1')
            GROUP BY preis, typ
        ");
        $stmtTypen->bindValue(':name', $searchName, SQLITE3_TEXT);
        $resTypen = $stmtTypen->execute();
        
        $textParts = [];
        $preisDetails = [];
        
        while ($rowT = $resTypen->fetchArray(SQLITE3_ASSOC)) {
            $anzahl = (int)$rowT['anzahl'];
            $rawPreis = (float)$rowT['preis'];
            $preis = number_format($rawPreis, 2, ',', '');

            if ($rawPreis == 0.0) {
                $textParts[] = "{$anzahl}x Löscher gratis";
            } else {
                $textParts[] = "{$anzahl}x Löscher je {$preis}€";
            }

            $preisDetails[] = [
                'anzahl' => $anzahl,
                'preis_pro_loescher' => $rawPreis
            ];
        }

        $r['loescher_text'] = implode("\n", $textParts);
        $r['preis_pro_loescher'] = $preisDetails;

        return $r;
    }

    public function getAll($filters = []) {
        $sql = "SELECT * FROM rechnungen WHERE 1=1";
        $p = [];

        if (isset($filters['rechnung_gedruckt'])) {
            $sql .= " AND rechnung_gedruckt = :rg";
            $p[':rg'] = (int)$filters['rechnung_gedruckt'];
        }

        $stmt = $this->db->prepare($sql);

        foreach ($p as $k => $v) {
            $stmt->bindValue($k, $v);
        }

        $res = $stmt->execute();
        $rows = [];

        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $this->enrichRechnungData($r);
        }

        return $rows;
    }

    public function find($nr) {
        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE rechnungsnummer = :nr");
        $stmt->bindValue(':nr', $nr);
        $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$r) {
            return null;
        }

        return $this->enrichRechnungData($r);
    }

    public function insert($d) {
        $stmt = $this->db->prepare("
            INSERT INTO rechnungen (anrede, name, adresse, plz, ort, anzahl_loescher, preis_pro_loescher, zeitstempel_erstellung, rechnungsnummer, rechnung_gedruckt)
            VALUES (:a, :n, :adr, :plz, :ort, :anz, :preis, :zeit, :rnr, 0)
        ");

        $stmt->bindValue(':a', $d['anrede']);
        $stmt->bindValue(':n', $d['name']);
        $stmt->bindValue(':adr', $d['adresse']);
        $stmt->bindValue(':plz', $d['plz']);
        $stmt->bindValue(':ort', $d['ort']);
        $stmt->bindValue(':anz', $d['anzahl_loescher'] ?? 0);
        $stmt->bindValue(':preis', $d['preis_pro_loescher'] ?? 0);
        $stmt->bindValue(':zeit', date("Y-m-d H:i:s"));
        $stmt->bindValue(':rnr', $d['rechnungsnummer']);

        $stmt->execute();

        return $d['rechnungsnummer'];
    }

    public function updatePartial($nr, $d) {
        if (isset($d['rechnung_gedruckt']) && (int)$d['rechnung_gedruckt'] === 1) {
            $d['zeitstempel_gedruckt'] = date("Y-m-d H:i:s");
        }
        $set = [];
        foreach ($d as $k => $v) $set[] = "$k = :$k";

        $sql = "UPDATE rechnungen SET " . implode(", ", $set) . " WHERE rechnungsnummer = :nr";

        $stmt = $this->db->prepare($sql);

        foreach ($d as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':nr', $nr);

        $stmt->execute();
    }
}