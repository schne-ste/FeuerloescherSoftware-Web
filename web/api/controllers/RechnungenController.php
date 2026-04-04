<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Rechnung.php';
require_once __DIR__ . '/../helpers/ErrorLog.php';
require_once __DIR__ . '/../helpers/Input.php';
require_once __DIR__ . '/../helpers/Response.php';

class RechnungenController {

    public function index() {
        $m = new Rechnung();
        Response::json($m->getAll($_GET));
    }

    public function show($nummer) {
        $m = new Rechnung();
        $d = $m->find($nummer);
        $d ? Response::json($d) : Response::json(["error"=>"Not found"],404);
    }

    public function store() {
        $m = new Rechnung();
        Response::json(["rechnungsnummer"=>$m->insert(Input::data())]);
    }

    public function patch($nummer) {
        $m = new Rechnung();
        $m->updatePartial($nummer, Input::data());
        Response::json(["status"=>200]);
    }
}