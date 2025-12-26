<?php
require_once __DIR__ . '/helpers.php';
require_admin();

$role = pick_role($_GET['role'] ?? ($_POST['role'] ?? 'traveller'));
$errors = flash_get_errors();
$success = flash_get('success_message');
$old = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);

$name = (string)($old['name'] ?? '');
$email = (string)($old['email'] ?? '');
$phone = (string)($old['phone'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create User | Admin</title>
  <link rel="stylesheet" href="../../assets/admin_user_manage_style.css">
</head>

<body>
  <div class="app">
    <?php base_admin_sidebar('users'); ?>

    <main class="content">
      <div class="topbar">
        <div class="page-title">
          <h1>Create <?php echo $role === 'admin' ? 'Admin' : 'Traveller'; ?></h1>
          <p>Create a new account. Password must be at least 6 characters.</p>
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
        <h3>Account Details</h3>
        <p class="meta">Role determines which table is used: travellers or admins.</p>

        <form method="post" action="process.php">
          <input type="hidden" name="action" value="create">

          <div class="form-grid">
            <div class="field">
              <label for="role">Role</label>
              <select id="role" name="role" required>
                <option value="traveller" <?php echo $role === 'traveller' ? 'selected' : ''; ?>>Traveller</option>
                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
              </select>
              <div class="helper">Traveller uses <code>travellers</code> table; Admin uses <code>admins</code> table.</div>
            </div>

            <div class="field">
              <label for="name"><?php echo $role === 'admin' ? 'Username' : 'Full Name'; ?></label>
              <input id="name" type="text" name="name" value="<?php echo h($name); ?>" required>
            </div>

            <div class="field">
              <label for="email">Email</label>
              <input id="email" type="email" name="email" value="<?php echo h($email); ?>" required>
            </div>

            <div class="field" id="phoneField" style="<?php echo $role === 'traveller' ? '' : 'display:none;'; ?>">
              <label for="phone">Phone (Traveller only)</label>
              <input id="phone" type="text" name="phone" value="<?php echo h($phone); ?>" placeholder="e.g., 012-3456789">
            </div>

            <div class="field">
              <label for="password">Password</label>
              <input id="password" type="password" name="password" required minlength="6">
            </div>

            <div class="field">
              <label for="confirm_password">Confirm Password</label>
              <input id="confirm_password" type="password" name="confirm_password" required minlength="6">
            </div>

            <div class="field full">
              <button class="btn btn-primary" type="submit">Create</button>
              <a class="btn btn-ghost" href="index.php?role=<?php echo h($role); ?>">Cancel</a>
            </div>
          </div>
        </form>
      </section>
    </main>
  </div>

  <script>
    // Show/hide phone field based on role selection
    const roleSelect = document.getElementById('role');
    const phoneField = document.getElementById('phoneField');
    roleSelect.addEventListener('change', () => {
      phoneField.style.display = (roleSelect.value === 'traveller') ? '' : 'none';
    });
  </script>
</body>

</html>