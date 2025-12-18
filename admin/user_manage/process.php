<?php
require_once __DIR__ . '/helpers.php';
require_admin();
require_once __DIR__ . '/../../config/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action = strtolower(trim($_POST['action'] ?? ''));
$role = pick_role($_POST['role'] ?? 'traveller');
$table = user_table($role);
$idField = user_id_field($role);
$nameField = user_name_field($role);
$phoneField = user_phone_field($role);

function back(string $role, string $msg, bool $isError = false, ?int $id = null): void {
    if ($isError) $_SESSION['form_errors'] = [$msg];
    else $_SESSION['success_message'] = $msg;

    if ($id !== null) {
        header('Location: edit.php?role=' . urlencode($role) . '&id=' . (int)$id);
    } else {
        header('Location: index.php?role=' . urlencode($role));
    }
    exit;
}

function back_with_errors(string $role, array $errors, array $old, string $redirect, ?int $id = null): void {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['old_input'] = $old;
    if ($redirect === 'create') {
        header('Location: create.php?role=' . urlencode($role));
    } else {
        header('Location: edit.php?role=' . urlencode($role) . '&id=' . (int)$id);
    }
    exit;
}

try {
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($name === '' || $email === '' || $password === '' || $confirm === '') {
            $errors[] = 'All required fields must be filled.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }
        if ($password !== '' && strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Password and confirm password do not match.';
        }

        if (!empty($errors)) {
            back_with_errors($role, $errors, ['name'=>$name,'email'=>$email,'phone'=>$phone], 'create');
        }

        // Duplicate checks
        if ($role === 'admin') {
            $check = $conn->prepare('SELECT admin_id FROM admins WHERE email = ? OR username = ? LIMIT 1');
            if (!$check) throw new Exception('Prepare failed');
            $check->bind_param('ss', $email, $name);
        } else {
            $check = $conn->prepare('SELECT traveller_id FROM travellers WHERE email = ? LIMIT 1');
            if (!$check) throw new Exception('Prepare failed');
            $check->bind_param('s', $email);
        }
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if ($exists) {
            back_with_errors($role, ['Email already exists' . ($role==='admin' ? ' (or username exists).' : '.')], ['name'=>$name,'email'=>$email,'phone'=>$phone], 'create');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($role === 'admin') {
            $stmt = $conn->prepare('INSERT INTO admins (username, email, password_hash) VALUES (?,?,?)');
            if (!$stmt) throw new Exception('Prepare failed');
            $stmt->bind_param('sss', $name, $email, $hash);
        } else {
            $stmt = $conn->prepare('INSERT INTO travellers (full_name, email, password_hash, phone) VALUES (?,?,?,?)');
            if (!$stmt) throw new Exception('Prepare failed');
            $stmt->bind_param('ssss', $name, $email, $hash, $phone);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Insert failed');
        }
        $newId = $stmt->insert_id;
        $stmt->close();

        $_SESSION['success_message'] = 'User created successfully.';
        header('Location: edit.php?role=' . urlencode($role) . '&id=' . (int)$newId);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) back($role, 'Invalid user id.', true);

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $errors = [];
        if ($name === '' || $email === '') $errors[] = 'Name and email are required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';

        if (!empty($errors)) {
            back_with_errors($role, $errors, ['name'=>$name,'email'=>$email,'phone'=>$phone], 'edit', $id);
        }

        // Duplicate email check (excluding current record)
        if ($role === 'admin') {
            $check = $conn->prepare('SELECT admin_id FROM admins WHERE email = ? AND admin_id <> ? LIMIT 1');
            if (!$check) throw new Exception('Prepare failed');
            $check->bind_param('si', $email, $id);
        } else {
            $check = $conn->prepare('SELECT traveller_id FROM travellers WHERE email = ? AND traveller_id <> ? LIMIT 1');
            if (!$check) throw new Exception('Prepare failed');
            $check->bind_param('si', $email, $id);
        }
        $check->execute();
        $dup = $check->get_result()->fetch_assoc();
        $check->close();
        if ($dup) {
            back_with_errors($role, ['Email already exists.'], ['name'=>$name,'email'=>$email,'phone'=>$phone], 'edit', $id);
        }

        if ($role === 'admin') {
            $stmt = $conn->prepare('UPDATE admins SET username = ?, email = ? WHERE admin_id = ?');
            if (!$stmt) throw new Exception('Prepare failed');
            $stmt->bind_param('ssi', $name, $email, $id);
        } else {
            $stmt = $conn->prepare('UPDATE travellers SET full_name = ?, email = ?, phone = ? WHERE traveller_id = ?');
            if (!$stmt) throw new Exception('Prepare failed');
            $stmt->bind_param('sssi', $name, $email, $phone, $id);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Update failed');
        }
        $stmt->close();

        back($role, 'Profile updated.', false, $id);
    }

    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) back($role, 'Invalid user id.', true);

        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($new === '' || $confirm === '') $errors[] = 'Password fields are required.';
        if ($new !== '' && strlen($new) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($new !== $confirm) $errors[] = 'Password and confirm password do not match.';

        if (!empty($errors)) {
            back_with_errors($role, $errors, [], 'edit', $id);
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        if ($role === 'admin') {
            $stmt = $conn->prepare('UPDATE admins SET password_hash = ? WHERE admin_id = ?');
            if (!$stmt) throw new Exception('Prepare failed');
            $stmt->bind_param('si', $hash, $id);
        } else {
            $stmt = $conn->prepare('UPDATE travellers SET password_hash = ? WHERE traveller_id = ?');
            if (!$stmt) throw new Exception('Prepare failed');
            $stmt->bind_param('si', $hash, $id);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Password update failed');
        }
        $stmt->close();

        back($role, 'Password updated.', false, $id);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) back($role, 'Invalid user id.', true);

        // Prevent deleting self (admin)
        if ($role === 'admin' && (int)($_SESSION['admin_id'] ?? 0) === $id) {
            back($role, 'You cannot delete the currently logged in admin account.', true);
        }

        if ($role === 'admin') {
            $stmt = $conn->prepare('DELETE FROM admins WHERE admin_id = ?');
        } else {
            $stmt = $conn->prepare('DELETE FROM travellers WHERE traveller_id = ?');
        }
        if (!$stmt) throw new Exception('Prepare failed');
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Delete failed');
        }
        $stmt->close();

        $_SESSION['success_message'] = 'User deleted.';
        header('Location: index.php?role=' . urlencode($role));
        exit;
    }

    back($role, 'Unknown action.', true);

} catch (Throwable $e) {
    $_SESSION['form_errors'] = ['Operation failed due to a system error.'];
    $redir = 'index.php?role=' . urlencode($role);
    if (isset($id) && (int)$id > 0) {
        $redir = 'edit.php?role=' . urlencode($role) . '&id=' . (int)$id;
    }
    header('Location: ' . $redir);
    exit;
}
