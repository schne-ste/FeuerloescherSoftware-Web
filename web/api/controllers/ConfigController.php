<?php
// Controller für die /config Route, um die defines aus config.php zurückzugeben
//GET /api/index.php?route=/config
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/ErrorLog.php';

class ConfigController {
    public function defines() {
        // Lese config.php und parse die define-Zeilen
        $defines = [];
        $content = file_get_contents(__DIR__ . '/../../config.php');
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            // Match define('KEY', value);
            if (preg_match("/define\('([^']+)',\s*(.+)\);/", $line, $matches)) {
                $key = $matches[1];
                $valueStr = trim($matches[2]);
                
                // Parse value: remove quotes if string, or convert to int/float
                if (preg_match("/^'(.+)'$/", $valueStr, $valMatch)) {
                    $value = $valMatch[1];
                } elseif (is_numeric($valueStr)) {
                    $value = strpos($valueStr, '.') !== false ? floatval($valueStr) : intval($valueStr);
                } else {
                    $value = $valueStr; // fallback
                }
                
                $defines[$key] = $value;
            }
        }
        
        // Ausschließen
        $exclude = ['DB_FILE', 'PASSWORD', 'RESET_PASSWORD', 'API_TOKEN'];
        $filtered = [];
        foreach ($defines as $key => $value) {
            if (!in_array($key, $exclude)) {
                $filtered[$key] = $value;
            }
        }
        
        Response::json($filtered);
    }
}