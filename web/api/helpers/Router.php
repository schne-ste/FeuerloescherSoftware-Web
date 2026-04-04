<?php

class Router {

    private $routes = [];

    public function get($pattern, $action)    { $this->add('GET', $pattern, $action); }
    public function post($pattern, $action)   { $this->add('POST', $pattern, $action); }
    public function put($pattern, $action)    { $this->add('PUT', $pattern, $action); }
    public function patch($pattern, $action)  { $this->add('PATCH', $pattern, $action); }

    private function add($method, $pattern, $action) {
        $this->routes[] = compact('method', 'pattern', 'action');
    }

    public function run($incoming) {

        $incoming = '/' . trim($incoming, '/');
        $method = $_SERVER['REQUEST_METHOD'];

        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;

            $pattern = preg_replace('#\{([^}]+)\}#', '([^/]+)', $r['pattern']);
            $pattern = '#^' . $pattern . '$#';

            if (!preg_match($pattern, $incoming, $m)) continue;

            array_shift($m);

            list($ctrlName, $func) = explode('@', $r['action']);
            require_once __DIR__ . '/../controllers/' . $ctrlName . '.php';

            $ctrl = new $ctrlName();
            return call_user_func_array([$ctrl, $func], $m);
        }

        Response::json(["error" => "Not found", "route" => $incoming], 404);
    }
}
