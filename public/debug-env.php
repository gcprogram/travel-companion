<?php
/**
 * TEMPORÄRE Diagnose: zeigt, ob/wie die .env und MAPTILER_KEY geladen werden.
 * Nach dem Testen UNBEDINGT wieder löschen (cd ../ && rm public/debug-env.php)!
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$envPath = dirname(__DIR__) . '/.env';

echo "<pre>";
echo "=== .env Pfad ===\n";
echo "Erwartet: " . $envPath . "\n";
echo "Existiert: " . (file_exists($envPath) ? 'JA' : 'NEIN') . "\n";
echo "Lesbar:   " . (is_readable($envPath) ? 'JA' : 'NEIN') . "\n";

echo "\n=== getenv() (echte Umgebungsvariable) ===\n";
var_dump(getenv('MAPTILER_KEY'));

echo "\n=== Env::get NACH Env::load ===\n";
App\Support\Env::load($envPath);
var_dump(App\Support\Env::get('MAPTILER_KEY', ''));

echo "\n=== Rohe .env-Inhalte (MAPTILER-Zeilen) ===\n";
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        if (stripos($line, 'MAPTILER') !== false || stripos($line, 'APP_ENV') !== false) {
            // Key-Wert maskieren, damit er nicht im Browser steht
            if (preg_match('/^([^=]+)=\s*(.+)$/', $line, $m)) {
                echo "Zeile " . ($i + 1) . ": " . trim($m[1]) . " = [len:" . strlen(trim($m[2])) . "] " . (strlen(trim($m[2])) > 0 ? '(Wert vorhanden)' : '(LEER)') . "\n";
            } else {
                echo "Zeile " . ($i + 1) . ": " . $line . "\n";
            }
        }
    }
} else {
    echo ".env nicht lesbar!\n";
}

echo "\n=== Document Root / Entry ===\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? '?') . "\n";
echo "DOCUMENT_ROOT:   " . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . "\n";
echo "</pre>";
