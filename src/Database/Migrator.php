<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Führt nummerierte .sql-Dateien aus /migrations genau einmal aus.
 * Angewendete Migrationen werden in der Tabelle `migrations` protokolliert.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDir,
    ) {
    }

    /**
     * @return list<string> Dateinamen der neu angewendeten Migrationen
     */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                filename VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        );

        $applied = $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $appliedSet = array_flip($applied);

        $files = glob($this->migrationDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($appliedSet[$name])) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            foreach ($this->splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }

            $insert = $this->pdo->prepare('INSERT INTO migrations (filename, applied_at) VALUES (?, ?)');
            $insert->execute([$name, gmdate('Y-m-d H:i:s')]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Naive, aber für unsere eigenen Migrationsdateien ausreichende Statement-Trennung:
     * Semikolon am Zeilenende trennt Statements; Zeilen mit '--' sind Kommentare.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        foreach (explode("\n", $sql) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $current .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $statements[] = trim($current);
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
