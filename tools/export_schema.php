<?php
// tools/export_schema.php
// Writes the two files of a clean install from the connected database (DB_NAME from the
// environment or .env, through config/db_connect.php, so no password is handled here):
//
//   database/schema.sql          CREATE TABLE statements only, no rows, no AUTO_INCREMENT counters,
//                                inside SET FOREIGN_KEY_CHECKS = 0 so the order of the tables does not matter
//   database/seed_reference.sql  the rows of the small reference tables (malaysia_states and
//                                travel_interests) and the list of applied migration files
//                                (schema_migrations)
//
//   php tools/export_schema.php
//
// It only reads the database. Run it on the test copy after a migration has been added:
//   DB_NAME=travel_itinerary_db_ctest php tools/export_schema.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);

require_once __DIR__ . '/../config/db_connect.php';

/** Tables whose rows are part of the reference data, with the column that orders them. */
const REFERENCE_TABLES = [
    'malaysia_states' => 'state_id',
    'travel_interests' => 'interest_id',
    'schema_migrations' => 'file_name',
];

$dbName = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];

// ---- schema.sql ------------------------------------------------------------------------
$tables = [];
foreach ($conn->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
    $tables[] = (string)array_values($row)[0];
}
sort($tables, SORT_STRING);

$schema = "-- Schema of the Smart Travel Itinerary Generator: structure only, no rows.\n"
    . "-- Written by tools/export_schema.php. Load it into an empty database, then database/seed_reference.sql.\n\n"
    . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
foreach ($tables as $table) {
    $create = (string)$conn->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch_row()[1];
    $create = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $create);
    $schema .= "-- Table " . $table . "\n" . $create . ";\n\n";
}
$schema .= "SET FOREIGN_KEY_CHECKS = 1;\n";

// ---- seed_reference.sql ----------------------------------------------------------------
$seed = "-- Reference rows: states, travel interests and the migration files already applied.\n"
    . "-- Written by tools/export_schema.php. Load it after database/schema.sql.\n\n"
    . "SET NAMES utf8mb4;\n\n";
foreach (REFERENCE_TABLES as $table => $orderBy) {
    if (!in_array($table, $tables, true)) {
        fwrite(STDERR, "Table {$table} does not exist in {$dbName}.\n");
        exit(1);
    }
    $columns = [];
    foreach ($conn->query('SHOW COLUMNS FROM `' . $table . '`') as $c) {
        // A column the database fills itself (a timestamp default) is left to the new database.
        if ($table === 'schema_migrations' && $c['Field'] === 'applied_at') continue;
        $columns[] = (string)$c['Field'];
    }
    $list = '`' . implode('`, `', $columns) . '`';
    $seed .= "-- " . $table . "\n";
    $values = [];
    foreach ($conn->query('SELECT ' . $list . ' FROM `' . $table . '` ORDER BY `' . $orderBy . '`') as $row) {
        $cells = [];
        foreach ($columns as $col) {
            $cells[] = $row[$col] === null ? 'NULL' : "'" . $conn->real_escape_string((string)$row[$col]) . "'";
        }
        $values[] = '(' . implode(', ', $cells) . ')';
    }
    if ($values) {
        $seed .= 'INSERT IGNORE INTO `' . $table . '` (' . $list . ") VALUES\n  " . implode(",\n  ", $values) . ";\n";
    }
    $seed .= "\n";
}

$dir = __DIR__ . '/../database';
$write = static function (string $name, string $text) use ($dir): void {
    $path = $dir . '/' . $name;
    if (file_put_contents($path, str_replace("\r\n", "\n", $text)) === false) {
        fwrite(STDERR, "Cannot write {$path}\n");
        exit(1);
    }
    echo 'Wrote database/' . $name . ' (' . strlen($text) . " bytes)\n";
};
$write('schema.sql', $schema);
$write('seed_reference.sql', $seed);
echo 'From database ' . $dbName . ': ' . count($tables) . " tables.\n";
