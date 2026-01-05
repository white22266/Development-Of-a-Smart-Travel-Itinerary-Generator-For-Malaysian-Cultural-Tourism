<?php

/**
 * tools/migrate_images.php
 *
 * One-time migration:
 * - Find rows where image_url is NOT empty AND image_path is NULL/empty
 * - Download remote image URL to local uploads/places/
 * - Update image_path
 * - OPTIONAL: also replace image_url with the local path (requested)
 *
 * Run:
 * - Browser: http://localhost/.../tools/migrate_images.php
 * - CLI: php tools/migrate_images.php
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../config/db_connect.php';

/* =========================
   CONFIG (EDIT HERE)
   ========================= */

// Target table/columns (CHANGE if your table is different)
$TABLE    = "cultural_places";   // e.g. cultural_places / cultural_place_suggestions / etc.
$ID_COL   = "place_id";          // primary key column name
$URL_COL  = "image_url";         // remote URL column
$PATH_COL = "image_path";        // local path column (must exist)

// Where to save downloaded images
$UPLOAD_REL_DIR = "uploads/places";                       // stored in DB as "uploads/places/xxx.jpg"
$UPLOAD_ABS_DIR = realpath(__DIR__ . "/..") . "/$UPLOAD_REL_DIR";

// Behavior
$LIMIT = 0;                   // 0 = no limit; or set 200, 500, etc.
$DRY_RUN = false;             // true = no download/update, just show what would happen

// IMPORTANT: user requested "auto change sql image_url also"
$UPDATE_URL_TO_LOCAL = true;  // true = set image_url = image_path (local path) after download

// Network options
$CONNECT_TIMEOUT = 10;
$TIMEOUT = 25;
$MAX_BYTES = 100 * 1024 * 1024; // 5MB max

/* =========================
   Helpers
   ========================= */

function out($s)
{
    if (php_sapi_name() === 'cli') {
        echo $s . PHP_EOL;
    } else {
        echo htmlspecialchars($s) . "<br>";
    }
}

function ensureDir(string $absDir): bool
{
    if (is_dir($absDir)) return true;
    return mkdir($absDir, 0755, true);
}

function isHttpUrl(string $url): bool
{
    return (bool)preg_match('#^https?://#i', trim($url));
}

function sanitizeUrl(string $url): string
{
    return trim($url);
}

/**
 * Download image with cURL and return array:
 * [ok(bool), data(string), mime(string), err(string)]
 */
function downloadImage(string $url, int $connectTimeout, int $timeout, int $maxBytes): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false, // localhost convenience; in production set true
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => "Mozilla/5.0",
        CURLOPT_HEADER         => true,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [false, "", "", "cURL error: $err"];
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode >= 400) {
        return [false, "", "", "HTTP $httpCode"];
    }

    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);

    if ($body === "" || strlen($body) < 20) {
        return [false, "", "", "Empty body"];
    }

    if (strlen($body) > $maxBytes) {
        return [false, "", "", "Too large > {$maxBytes} bytes"];
    }

    // Normalize mime
    $mime = strtolower(trim(explode(";", $contentType)[0]));

    // Fallback: detect mime using finfo (does NOT require GD)
    if ($mime === "" || $mime === "application/octet-stream" || $mime === "text/html") {
        if (class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $det = $fi->buffer($body);
            if (is_string($det) && $det !== "") $mime = strtolower($det);
        }
    }

    return [true, $body, $mime, ""];
}

function mimeToExt(string $mime): ?string
{
    $map = [
        "image/jpeg" => "jpg",
        "image/jpg"  => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
        "image/gif"  => "gif",
    ];
    return $map[$mime] ?? null;
}

function makeFilename(int $id, string $ext, string $url): string
{
    // stable-ish + unique
    $hash = substr(sha1($url), 0, 10);
    return "place_{$id}_{$hash}_" . date("Ymd_His") . "_" . bin2hex(random_bytes(3)) . "." . $ext;
}

/* =========================
   MAIN
   ========================= */

out("=== Image Migration Script ===");
out("Table: {$TABLE}");
out("Columns: id={$ID_COL}, url={$URL_COL}, path={$PATH_COL}");
out("Save to: {$UPLOAD_REL_DIR}  (ABS: {$UPLOAD_ABS_DIR})");
out("DRY_RUN=" . ($DRY_RUN ? "true" : "false") . ", UPDATE_URL_TO_LOCAL=" . ($UPDATE_URL_TO_LOCAL ? "true" : "false"));
out("-----------------------------------");

if (!ensureDir($UPLOAD_ABS_DIR)) {
    out("ERROR: Cannot create uploads dir: {$UPLOAD_ABS_DIR}");
    exit;
}

// Build SQL
$sql = "SELECT {$ID_COL} AS id, {$URL_COL} AS image_url, {$PATH_COL} AS image_path
        FROM {$TABLE}
        WHERE {$URL_COL} IS NOT NULL AND TRIM({$URL_COL}) <> ''
          AND ({$PATH_COL} IS NULL OR TRIM({$PATH_COL}) = '')";

if ($LIMIT > 0) {
    $sql .= " LIMIT " . (int)$LIMIT;
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    out("ERROR: Prepare failed: " . $conn->error);
    exit;
}
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

$total = count($rows);
out("Found rows to migrate: {$total}");
if ($total === 0) {
    out("Nothing to do.");
    exit;
}

$updateSql = $UPDATE_URL_TO_LOCAL
    ? "UPDATE {$TABLE} SET {$PATH_COL} = ?, {$URL_COL} = ? WHERE {$ID_COL} = ?"
    : "UPDATE {$TABLE} SET {$PATH_COL} = ? WHERE {$ID_COL} = ?";

$upd = $conn->prepare($updateSql);
if (!$upd) {
    out("ERROR: Update prepare failed: " . $conn->error);
    exit;
}

$ok = 0;
$skip = 0;
$fail = 0;

foreach ($rows as $r) {
    $id = (int)$r["id"];
    $url = sanitizeUrl((string)$r["image_url"]);

    if (!isHttpUrl($url)) {
        out("[SKIP] id={$id} invalid url={$url}");
        $skip++;
        continue;
    }

    out("Processing id={$id} ...");

    if ($DRY_RUN) {
        out("  DRY_RUN would download: {$url}");
        $ok++;
        continue;
    }

    [$downloadOk, $data, $mime, $err] = downloadImage($url, $CONNECT_TIMEOUT, $TIMEOUT, $MAX_BYTES);

    if (!$downloadOk) {
        out("  [FAIL] download: {$err} | {$url}");
        $fail++;
        continue;
    }

    $ext = mimeToExt($mime);
    if ($ext === null) {
        out("  [FAIL] unsupported mime={$mime} | {$url}");
        $fail++;
        continue;
    }

    $filename = makeFilename($id, $ext, $url);
    $absPath = rtrim($UPLOAD_ABS_DIR, "/\\") . DIRECTORY_SEPARATOR . $filename;
    $relPath = $UPLOAD_REL_DIR . "/" . $filename; // store in DB

    $written = @file_put_contents($absPath, $data);
    if ($written === false) {
        out("  [FAIL] write file: {$absPath}");
        $fail++;
        continue;
    }

    // Update DB
    if ($UPDATE_URL_TO_LOCAL) {
        $upd->bind_param("ssi", $relPath, $relPath, $id);
    } else {
        $upd->bind_param("si", $relPath, $id);
    }

    if (!$upd->execute()) {
        out("  [FAIL] DB update: " . $upd->error);
        // rollback file if DB failed
        @unlink($absPath);
        $fail++;
        continue;
    }

    out("  [OK] saved={$relPath} mime={$mime}");
    $ok++;
}

$upd->close();

out("-----------------------------------");
out("DONE. ok={$ok}, skip={$skip}, fail={$fail}");
out("If fail>0, check URLs or remote server hotlink rules.");
