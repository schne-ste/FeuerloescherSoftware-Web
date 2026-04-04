<?php

class ErrorLog {

    public static function write($message) {

        $logDir = __DIR__ . '/../logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $file = $logDir . '/error_' . date('Y-m-d') . '.log';

        $line = "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";

        file_put_contents($file, $line, FILE_APPEND);
    }
}