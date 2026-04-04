<?php
require_once __DIR__ . '/../config/database.php';

class Rechnung {

    private $db;

    public function __construct() {
        $this->db = getDB();
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
            $rows[] = $r;
        }

        return $rows;
    }

    public function find($nr) {
        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE rechnungsnummer = :nr");
        $stmt->bindValue(':nr', $nr);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
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
        $stmt->bindValue(':anz', $d['anzahl_loescher']);
        $stmt->bindValue(':preis', $d['preis_pro_loescher']);
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
        foreach ($d as $k=>$v) $set[] = "$k = :$k";

        $sql = "UPDATE rechnungen SET " . implode(", ", $set) . " WHERE rechnungsnummer = :nr";

        $stmt = $this->db->prepare($sql);

        foreach ($d as $k=>$v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':nr', $nr);

        $stmt->execute();
    }
}
