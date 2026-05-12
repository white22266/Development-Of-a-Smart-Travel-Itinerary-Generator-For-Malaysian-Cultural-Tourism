<?php
// auth/remember_me.php

const REMEMBER_COOKIE_NAME = 'st_remember';
const REMEMBER_COOKIE_DAYS = 30;

function remember_table_exists(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'remember_tokens'");
    return $res && $res->num_rows > 0;
}

function remember_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ];
}

function create_remember_token(mysqli $conn, string $role, int $userId): void
{
    if ($userId <= 0 || !in_array($role, ['traveller', 'admin'], true) || !remember_table_exists($conn)) return;

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + REMEMBER_COOKIE_DAYS * 86400);

    $stmt = $conn->prepare("
        INSERT INTO remember_tokens (user_role, user_id, selector, token_hash, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param("sisss", $role, $userId, $selector, $hash, $expiresAt);
    $stmt->execute();
    $stmt->close();

    setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, remember_cookie_options(time() + REMEMBER_COOKIE_DAYS * 86400));
}

function clear_remember_token(mysqli $conn): void
{
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie !== '' && remember_table_exists($conn)) {
        [$selector] = array_pad(explode(':', $cookie, 2), 2, '');
        if ($selector !== '') {
            $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE selector = ?");
            if ($stmt) {
                $stmt->bind_param("s", $selector);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    setcookie(REMEMBER_COOKIE_NAME, '', remember_cookie_options(time() - 3600));
}

function restore_remembered_login(mysqli $conn): bool
{
    if (($_SESSION["logged_in"] ?? false) === true) return true;
    if (!remember_table_exists($conn)) return false;

    $cookie = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) return false;

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') return false;

    $stmt = $conn->prepare("
        SELECT token_id, user_role, user_id, token_hash, expires_at
        FROM remember_tokens
        WHERE selector = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("s", $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || strtotime((string)$row["expires_at"]) < time()) {
        clear_remember_token($conn);
        return false;
    }

    if (!hash_equals((string)$row["token_hash"], hash('sha256', $validator))) {
        clear_remember_token($conn);
        return false;
    }

    $role = (string)$row["user_role"];
    $userId = (int)$row["user_id"];

    if ($role === 'admin') {
        $u = $conn->prepare("SELECT admin_id, username FROM admins WHERE admin_id = ? LIMIT 1");
    } else {
        $u = $conn->prepare("SELECT traveller_id, full_name, force_password_change, is_active FROM travellers WHERE traveller_id = ? LIMIT 1");
    }
    if (!$u) return false;
    $u->bind_param("i", $userId);
    $u->execute();
    $user = $u->get_result()->fetch_assoc();
    $u->close();
    if (!$user) {
        clear_remember_token($conn);
        return false;
    }
    if ($role === 'traveller' && (int)($user["is_active"] ?? 1) !== 1) {
        clear_remember_token($conn);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION["logged_in"] = true;
    $_SESSION["role"] = $role;
    if ($role === 'admin') {
        $_SESSION["admin_id"] = (int)$user["admin_id"];
        $_SESSION["admin_name"] = (string)$user["username"];
    } else {
        $_SESSION["traveller_id"] = (int)$user["traveller_id"];
        $_SESSION["traveller_name"] = (string)$user["full_name"];
        $_SESSION["force_password_change"] = (int)($user["force_password_change"] ?? 0);
    }
    return true;
}
