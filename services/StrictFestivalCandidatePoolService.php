<?php
// services/StrictFestivalCandidatePoolService.php
// Creates a connection-scoped read-only candidate pool that excludes festivals
// without verified dates. It shadows cultural_places only for the current DB
// connection, so itinerary generation never modifies global place records.

final class StrictFestivalCandidatePoolService
{
    public static function install(mysqli $conn): void
    {
        $databaseResult = $conn->query('SELECT DATABASE() AS db_name');
        $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : null;
        $databaseName = trim((string)($databaseRow['db_name'] ?? ''));
        if ($databaseName === '') {
            throw new RuntimeException('Unable to determine the active database for strict festival validation.');
        }

        $quotedDatabase = '`' . str_replace('`', '``', $databaseName) . '`';

        // Remove only an existing temporary shadow table in this connection.
        // The permanent cultural_places table is never dropped or updated.
        if (!$conn->query('DROP TEMPORARY TABLE IF EXISTS cultural_places')) {
            throw new RuntimeException('Unable to reset the strict cultural place candidate pool.');
        }

        $sql = "
            CREATE TEMPORARY TABLE cultural_places AS
            SELECT *
            FROM {$quotedDatabase}.cultural_places
            WHERE LOWER(TRIM(COALESCE(category, ''))) <> 'festival'
               OR (
                    festival_start_date IS NOT NULL
                    AND festival_end_date IS NOT NULL
                    AND festival_start_date <> '0000-00-00'
                    AND festival_end_date <> '0000-00-00'
                    AND festival_end_date >= festival_start_date
               )
        ";

        if (!$conn->query($sql)) {
            throw new RuntimeException('Unable to install the strict festival candidate pool.');
        }
    }
}
