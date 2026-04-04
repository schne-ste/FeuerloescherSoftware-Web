<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Loescher.php';
require_once __DIR__ . '/../helpers/ErrorLog.php';
require_once __DIR__ . '/../helpers/Input.php';
require_once __DIR__ . '/../helpers/Response.php';


class LoescherController {

    public function index() {
        $m = new Loescher();
        Response::json($m->getAll($_GET));
    }

    public function show($nummer) {
        $m = new Loescher();
        $d = $m->find($nummer);
        $d ? Response::json($d) : Response::json(["error"=>"Not found"],404);
    }

    public function store() {
        $m = new Loescher();
        Response::json(["nummer"=>$m->insert(Input::data())]);
    }

    public function update($nummer) {
        $m = new Loescher();
        $m->updateFull($nummer, Input::data());
        Response::json(["status"=>200]);
    }

    public function patch($nummer) {
        $m = new Loescher();
        $m->updatePartial($nummer, Input::data());
        Response::json(["status"=>200]);
    }
}