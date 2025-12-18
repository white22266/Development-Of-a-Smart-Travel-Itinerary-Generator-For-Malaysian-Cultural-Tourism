<?php
require_once __DIR__ . '/helpers.php';
require_admin();
require_once __DIR__ . '/../../config/db_connect.php';

$role = pick_role($_GET['role'] ?? 'traveller');
$q = trim($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$table = user_table($role);
$idField = user_id_field($role);
$nameField = user_name_field($role);
$phoneField = user_phone_field($role);

$success = flash_get('success_message');
$errors = flash_get_errors();

// Build query
$where = '';
$params = [];
$types = '';

if ($q !== '') {
  if ($role === 'admin') {
    $where = "WHERE (username LIKE ? OR email LIKE ?)";
    $like = "%{$q}%";
    $params = [$like, $like];
    $types = 'ss';
  } else {
    $where = "WHERE (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%{$q}%";
    $params = [$like, $like, $like];
    $types = 'sss';
  }
}

// Count
$countSql = "SELECT COUNT(*) AS c FROM {$table} {$where}";
$countStmt = $conn->prepare($countSql);
if ($countStmt === false) {
  die('Prepare failed.');
}
if ($types !== '') {
  $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$countStmt->close();

// Data
$fields = ($role === 'admin')
  ? "{$idField} AS id, {$nameField} AS name, email"
  : "{$idField} AS id, {$nameField} AS name, email, {$phoneField} AS phone";

$sql = "SELECT {$fields} FROM {$table} {$where} ORDER BY {$idField} DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
  die('Prepare failed.');
}

if ($types === '') {
  $stmt->bind_param('ii', $perPage, $offset);
} else {
  $bindTypes = $types . 'ii';
  $bindParams = array_merge($params, [$perPage, $offset]);
  $stmt->bind_param($bindTypes, ...$bindParams);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = (int)ceil($total / $perPage);
if ($totalPages < 1) $totalPages = 1;

function page_link(int $p, string $role, string $q): string
{
  $qs = http_build_query(['role' => $role, 'q' => $q, 'page' => $p]);
  return 'index.php?' . $qs;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Management | Admin</title>
  <link rel="stylesheet" href="../../assets/user_manage_style.css">
</head>

<body>
  <div class="app">
    <?php base_admin_sidebar('users'); ?>

    <main class="content">
      <div class="topbar">
        <div class="page-title">
          <h1>User Management</h1>
          <p>Manage traveller and admin accounts (view, create, update, reset password, delete).</p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="create.php?role=<?php echo h($role); ?>">Add <?php echo $role === 'admin' ? 'Admin' : 'Traveller'; ?></a>
          <a class="btn btn-primary" href="index.php?role=traveller">Travellers</a>
          <a class="btn btn-primary" href="index.php?role=admin">Admins</a>
        </div>
      </div>

      <?php if ($success !== ''): ?>
        <div class="notice success"><?php echo h($success); ?></div>
      <?php endif; ?>
      <?php if (!empty($errors)): ?>
        <div class="notice error">
          <strong>Action failed:</strong>
          <ul style="margin:8px 0 0; padding-left:18px;">
            <?php foreach ($errors as $e): ?><li><?php echo h((string)$e); ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <h3>Users List</h3>
        <p class="meta">Role: <strong><?php echo h($role); ?></strong> &middot; Total: <strong><?php echo (int)$total; ?></strong></p>

        <form class="filters" method="get" action="index.php">
          <input type="hidden" name="role" value="<?php echo h($role); ?>">
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search name / email<?php echo $role === 'traveller' ? ' / phone' : ''; ?>">
          <button class="btn btn-primary" type="submit">Search</button>
          <a class="btn btn-ghost" href="index.php?role=<?php echo h($role); ?>">Reset</a>
        </form>

        <div style="height:12px"></div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <?php if ($role === 'traveller'): ?><th>Phone</th><?php endif; ?>
                <th style="min-width:220px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr>
                  <td colspan="<?php echo $role === 'traveller' ? 5 : 4; ?>">No records found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo h((string)$r['name']); ?></td>
                    <td><?php echo h((string)$r['email']); ?></td>
                    <?php if ($role === 'traveller'): ?><td><?php echo h((string)($r['phone'] ?? '')); ?></td><?php endif; ?>
                    <td>
                      <div class="actions-inline">
                        <a class="btn btn-ghost" href="edit.php?role=<?php echo h($role); ?>&id=<?php echo (int)$r['id']; ?>">Edit</a>
                        <a class="btn btn-primary" href="edit.php?role=<?php echo h($role); ?>&id=<?php echo (int)$r['id']; ?>#password">Reset Password</a>
                        <form method="post" action="process.php" onsubmit="return confirm('Delete this user? This cannot be undone.');" style="display:inline;">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="role" value="<?php echo h($role); ?>">
                          <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                          <button class="btn btn-danger" type="submit">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="height:12px"></div>

        <div class="actions" style="justify-content:space-between;">
          <div class="meta">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
          <div class="actions">
            <a class="btn btn-ghost" href="<?php echo h(page_link(max(1, $page - 1), $role, $q)); ?>">Prev</a>
            <a class="btn btn-ghost" href="<?php echo h(page_link(min($totalPages, $page + 1), $role, $q)); ?>">Next</a>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>

</html>