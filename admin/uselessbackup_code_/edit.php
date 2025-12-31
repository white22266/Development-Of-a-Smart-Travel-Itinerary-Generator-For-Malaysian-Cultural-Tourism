<?php
require_once __DIR__ . '/helpers.php';
require_admin();
require_once __DIR__ . '/../../config/db_connect.php';

$role = pick_role($_GET['role'] ?? 'traveller');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  $_SESSION['form_errors'] = ['Invalid user id.'];
  header('Location: index.php?role=' . urlencode($role));
  exit;
}

$table = user_table($role);
$idField = user_id_field($role);
$nameField = user_name_field($role);
$phoneField = user_phone_field($role);

$success = flash_get('success_message');
$errors = flash_get_errors();

// Load record
$fields = ($role === 'admin')
  ? "{$idField} AS id, {$nameField} AS name, email"
  : "{$idField} AS id, {$nameField} AS name, email, {$phoneField} AS phone";

$stmt = $conn->prepare("SELECT {$fields} FROM {$table} WHERE {$idField} = ? LIMIT 1");
if (!$stmt) die('Prepare failed.');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
  $_SESSION['form_errors'] = ['User not found.'];
  header('Location: index.php?role=' . urlencode($role));
  exit;
}

// Overwrite with old input if validation failed before
$old = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);
if (!empty($old)) {
  $user['name'] = $old['name'] ?? $user['name'];
  $user['email'] = $old['email'] ?? $user['email'];
  if ($role === 'traveller') $user['phone'] = $old['phone'] ?? ($user['phone'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit User | Admin</title>
  <link rel="stylesheet" href="../../assets/admin_user_manage_style.css">
</head>

<body>
  <div class="app">
    <?php base_admin_sidebar('users'); ?>

    <main class="content">
      <div class="topbar">
        <div class="page-title">
          <h1>Edit <?php echo $role === 'admin' ? 'Admin' : 'Traveller'; ?></h1>
          <p>ID: <strong><?php echo (int)$user['id']; ?></strong></p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="index.php?role=<?php echo h($role); ?>">Back to list</a>
        </div>
      </div>

      <?php if ($success !== ''): ?>
        <div class="notice success"><?php echo h($success); ?></div>
      <?php endif; ?>
      <?php if (!empty($errors)): ?>
        <div class="notice error">
          <strong>Please fix the following:</strong>
          <ul style="margin:8px 0 0; padding-left:18px;">
            <?php foreach ($errors as $e): ?><li><?php echo h((string)$e); ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <h3>Profile</h3>
        <p class="meta">Update basic info (name/email<?php echo $role === 'traveller' ? '/phone' : ''; ?>).</p>

        <form method="post" action="process.php">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="role" value="<?php echo h($role); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">

          <div class="form-grid">
            <div class="field">
              <label for="name"><?php echo $role === 'admin' ? 'Username' : 'Full Name'; ?></label>
              <input id="name" type="text" name="name" value="<?php echo h((string)$user['name']); ?>" required>
            </div>

            <div class="field">
              <label for="email">Email</label>
              <input id="email" type="email" name="email" value="<?php echo h((string)$user['email']); ?>" required>
            </div>

            <?php if ($role === 'traveller'): ?>
              <div class="field">
                <label for="phone">Phone</label>
                <input id="phone" type="text" name="phone" value="<?php echo h((string)($user['phone'] ?? '')); ?>">
              </div>
            <?php endif; ?>

            <div class="field full">
              <button class="btn btn-primary" type="submit">Save Changes</button>
              <a class="btn btn-ghost" href="index.php?role=<?php echo h($role); ?>">Cancel</a>
            </div>
          </div>
        </form>
      </section>

      <div style="height:16px"></div>
    </main>
  </div>
</body>

</html>