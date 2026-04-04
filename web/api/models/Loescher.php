<?php
require_once __DIR__ . '/../config/database.php';

class Loescher {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function getAll($filters) {
        $sql = "SELECT * FROM loescher WHERE 1=1";
        $p = [];

        if (isset($filters['abholschein_gedruckt'])) {
            $sql .= " AND abholschein_gedruckt = :ag";
            $p[':ag'] = (int)$filters['abholschein_gedruckt'];
        }

        if (isset($filters['etikett_gedruckt'])) {
            $sql .= " AND etikett_gedruckt = :eg";
            $p[':eg'] = (int)$filters['etikett_gedruckt'];
        }

        $stmt = $this->db->prepare($sql);
        foreach ($p as $k=>$v) $stmt->bindValue($k,$v);

        $res = $stmt->execute();
        $rows = [];

        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }

        return $rows;
    }

    public function find($nummer) {
        $stmt = $this->db->prepare("SELECT * FROM loescher WHERE nummer = :nr");
        $stmt->bindValue(':nr', $nummer);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    public function insert($d) {
        $stmt = $this->db->prepare("
            INSERT INTO loescher (nummer, name, typ, preis, loeschertyp, menge, einheit)
            VALUES (:n, :name, :typ, :preis, :ltyp, :menge, :einheit)
        ");

        foreach (['n'=>'nummer','name','typ','preis','loeschertyp','menge','einheit'] as $k=>$v) {
            $stmt->bindValue(':' . (is_numeric($k)?$v:$k), $d[$v]);
        }

        $stmt->execute();
        return $d['nummer'];
    }

    public function updateFull($nummer, $d) {
        $fields = ['name','typ','preis','loeschertyp','menge','einheit','etikett_gedruckt','abholschein_gedruckt','geprueft','abgeholt'];

        $sql = "UPDATE loescher SET "
             . implode(', ', array_map(fn($f)=>"$f = :$f", $fields))
             . " WHERE nummer = :nr";

        $stmt = $this->db->prepare($sql);

        foreach ($fields as $f) {
            $stmt->bindValue(":$f", $d[$f] ?? null);
        }

        $stmt->bindValue(':nr', $nummer);
        $stmt->execute();
    }

    public function updatePartial($nummer, $d) {
        $set = [];
        foreach ($d as $k=>$v) $set[] = "$k = :$k";

        $sql = "UPDATE loescher SET " . implode(", ", $set) . " WHERE nummer = :nr";

        $stmt = $this->db->prepare($sql);

        foreach ($d as $k=>$v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':nr', $nummer);

        $stmt->execute();
    }
}