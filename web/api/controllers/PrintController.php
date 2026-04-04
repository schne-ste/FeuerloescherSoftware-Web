<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Loescher.php';
require_once __DIR__ . '/../models/Rechnung.php';
require_once __DIR__ . '/../helpers/ErrorLog.php';
require_once __DIR__ . '/../helpers/Response.php';

class PrintController {

    public function abholschein($nummer) {
        (new Loescher())->updatePartial($nummer, ['abholschein_gedruckt'=>1]);
        Response::json(["ok"=>true]);
    }

    public function etikett($nummer) {
        (new Loescher())->updatePartial($nummer, ['etikett_gedruckt'=>1]);
        Response::json(["ok"=>true]);
    }

    public function rechnungGedruckt($nummer) {
        (new Rechnung())->updatePartial($nummer, ['rechnung_gedruckt'=>1]);
        Response::json(["ok"=>true]);
    }
}