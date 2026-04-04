<?php

class Input {

    public static function data() {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}