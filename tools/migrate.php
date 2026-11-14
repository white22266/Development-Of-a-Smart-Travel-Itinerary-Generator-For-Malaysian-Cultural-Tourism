<?php
// tools/migrate.php
// Command-line migration runner for database/migrations/*.sql. It records every applied file
// in the table schema_migrations (file_name, applied_at).
//
//   php tools/migrate.php                          apply the files not recorded yet, in name order
//   php tools/migrate.php --status                 list applied and pending files
//   php tools/migrate.php --baseline=<file name>   record that file and every earlier one as applied,
//                                                  without running them
//   php tools/migrate.php --confirm=<database>     needed to change a database whose name does not
//                                                  end in "_ctest"
//
// The database is DB_NAME from the environment or .env. Migration files must be safe to re-run.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);

require_once __DIR__ . '/../config/db_connect.php';

/** Split SQL text into statements: ignores "--" comments and semicolons inside quotes. */
function migrate_split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        if ($quote === '') {
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\n" || ($sql[$i + 2] ?? '') === "\r")) {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $current .= "\n";
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === ';') {
                if (trim($current) !== '') $statements[] = trim($current);
                $current = '';
                continue;
            }
        } else {
            if ($ch === '\\' &&$quote !== '`') {
                $current .= $ch . ($sql[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($ch === $quote) $quote = '';
        }
        $current .= $ch;
    }
    if (trim($current) !== '') $statements[] = trim($current);
    return $statements;
}

$options = getopt('', ['status', 'baseline:', 'confirm:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/migrate.php [--status] [--baseline=<file name>] [--confirm=<database name>]\n";
    exit(0);
}

$dbName = (string)(getenv('DB_NAME') ?: 'travel_itinerary_db');
echo "Database: {$dbName}\n";

$dir = __DIR__ . '/../database/migrations';
$files = [];
foreach (glob($dir . '/*.sql') ?: [] as $path) $files[] = basename($path);
sort($files, SORT_STRING);

$readOnly = isset($options['status']);
$isTestCopy = str_ends_with($dbName, '_ctest');
if (!$readOnly && !$isTestCopy && (string)($options['confirm'] ?? '') !== $dbName) {
    fwrite(STDERR, "Refusing to change \"{$dbName}\": it is not a test copy. Run again with --confirm={$dbName} if that is intended.\n");
    exit(1);
}

$applied = [];
$tableRes = $conn->query("SHOW TABLES LIKE 'schema_migrations'");
$tableExists = $tableRes && $tableRes->num_rows > 0;
if (!$tableExists && !$readOnly) {
    $conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
        file_name VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $tableExists = true;
}
if ($tableExists) {
    $res = $conn->query("SELECT file_name, applied_at FROM schema_migrations");
    while ($row = $res->fetch_assoc()) $applied[$row['file_name']] = $row['applied_at'];
}

if ($readOnly) {
    foreach ($files as $file) {
        echo (isset($applied[$file]) ? "applied  {$file}  ({$applied[$file]})" : "pending  {$file}") . "\n";
    }
    exit(0);
}

$record = $conn->prepare("INSERT IGNORE INTO schema_migrations (file_name) VALUES (?)");

if (isset($options['baseline'])) {
    $target = basename((string)$options['baseline']);
    if (!in_array($target, $files, true)) {
        fwrite(STDERR, "Unknown migration file \"{$target}\".\n");
        exit(1);
    }
    foreach ($files as $file) {
        if (strcmp($file, $target) > 0) break;
        if (isset($applied[$file])) continue;
        $record->bind_param('s', $file);
        $record->execute();
        echo "baseline {$file}\n";
    }
    exit(0);
}

$conn->report_mode = MYSQLI_REPORT_OFF;
$ran = 0;
foreach ($files as $file) {
    if (isset($applied[$file])) continue;
    $statements = migrate_split_sql((string)file_get_contents($dir . '/' . $file));
    echo "applying {$file} (" . count($statements) . " statements)\n";
    foreach ($statements as $n => $sql) {
        try {
            $ok = $conn->query($sql);
            $error = $ok ? '' : $conn->error;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($error !== '') {
            fwrite(STDERR, "FAILED in {$file}, statement " . ($n + 1) . ": {$error}\nStopped; later files were not run.\n");
            exit(2);
        }
    }
    $record->bind_param('s', $file);
    $record->execute();
    $ran++;
}
echo $ran === 0 ? "Nothing to apply.\n" : "Applied {$ran} file(s).\n";
exit(0);
