<?php
// admin/user_manage/index.php
// Single-file User Management (travellers only). No delete. Views: list | create | edit | reset
// CHANGED: Reset Password now sets travellers.must_change_password = 1 and sends email with temporary password.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../../config/db_connect.php";

/* =============================
   PHPMailer (optional)
   ============================= */
// ADDED: Prevent fatal error if Composer vendor is missing
$autoloadPath = __DIR__ . "/../../vendor/autoload.php";
if (file_exists($autoloadPath)) {
  require_once $autoloadPath;
}
$MAIL_ENABLED = class_exists('\PHPMailer\PHPMailer\PHPMailer'); // ADDED

/* =============================
   Helpers
   ============================= */
function redirect_to(string $url): void
{
  header("Location: " . $url);
  exit;
}

function require_admin_guard(): void
{
  if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    redirect_to("../../auth/login.php?role=admin");
  }
}

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function flash_get(string $key): string
{
  $val = $_SESSION[$key] ?? "";
  if ($val !== "") unset($_SESSION[$key]);
  return is_string($val) ? $val : "";
}

function flash_get_errors(): array
{
  $errs = $_SESSION["form_errors"] ?? [];
  unset($_SESSION["form_errors"]);
  return is_array($errs) ? $errs : [];
}

function flash_set_success(string $msg): void
{
  $_SESSION["success_message"] = $msg;
}

function flash_set_errors(array $errs): void
{
  $_SESSION["form_errors"] = $errs;
}

function base_admin_sidebar_users(): void
{
  $adminName = $_SESSION["admin_name"] ?? "Administrator";
  $items = [
    ["Dashboard", "../../admin/admin_dashboard.php", false],
    ["State Cultural Knowledge Base", "../admin_cultural_kb.php", false],
    ["Content Validation", "../admin_pending.php", false],
    ["User Management", "./index.php", true],
    ["Logout", "../../auth/logout.php", false],
  ];
?>
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-badge">ST</div>
      <div class="brand-title">
        <strong>Smart Travel Itinerary Generator</strong>
        <span>User Management</span>
      </div>
    </div>

    <nav class="nav" aria-label="Sidebar Navigation">
      <?php foreach ($items as [$label, $href, $isActive]): ?>
        <a class="<?php echo $isActive ? 'active' : ''; ?>" href="<?php echo h($href); ?>">
          <span class="dot"></span> <?php echo h($label); ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="small">Logged in as:</div>
      <div style="margin-top:6px; font-weight:800;"><?php echo h($adminName); ?></div>
      <div class="chip">Role: Admin</div>
    </div>
  </aside>
<?php
}

/* =============================
   Email sender (PHPMailer)
   ============================= */
function sendResetPasswordEmail(string $toEmail, string $toName, string $newPassword): bool
{
  // ADDED: If PHPMailer not installed, do not crash the system
  if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) return false;

  // ===== SMTP CONFIG (EDIT THESE) =====
  $SMTP_HOST  = "smtp.gmail.com";
  $SMTP_PORT  = 587; // 587 TLS, 465 SSL
  $SMTP_USER  = "YOUR_GMAIL@gmail.com";
  $SMTP_PASS  = "YOUR_APP_PASSWORD"; // Gmail App Password (not normal password)
  $FROM_EMAIL = $SMTP_USER;
  $FROM_NAME  = "Smart Travel Itinerary Generator";
  // ===================================

  try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host     = $SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = $SMTP_USER;
    $mail->Password = $SMTP_PASS;

    if ($SMTP_PORT === 465) {
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->Port = $SMTP_PORT;

    $mail->CharSet = "UTF-8";
    $mail->setFrom($FROM_EMAIL, $FROM_NAME);
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = "Your password has been reset";

    $safeName = htmlspecialchars($toName, ENT_QUOTES, "UTF-8");
    $safePass = htmlspecialchars($newPassword, ENT_QUOTES, "UTF-8");

    $mail->Body = "
      <p>Hi {$safeName},</p>
      <p>Your account password has been reset by an administrator.</p>
      <p><strong>New temporary password:</strong> {$safePass}</p>
      <p>Please log in and change your password immediately.</p>
      <p>Regards,<br>{$FROM_NAME}</p>
    ";

    $mail->AltBody =
      "Hi {$toName},\n\n"
      . "Your account password has been reset by an administrator.\n"
      . "New temporary password: {$newPassword}\n"
      . "Please log in and change your password immediately.\n\n"
      . "{$FROM_NAME}";

    $mail->send();
    return true;
  } catch (Throwable $e) {
    // Optional debug:
    // error_log("Email send failed: " . $e->getMessage());
    return false;
  }
}

/* =============================
   Guard
   ============================= */
require_admin_guard();

/* =============================
   POST handlers (same file)
   ============================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = strtolower(trim($_POST["action"] ?? ""));

  try {
    /* ---------- CREATE TRAVELLER ---------- */
    if ($action === "create") {
      $name = trim($_POST["name"] ?? "");
      $email = trim($_POST["email"] ?? "");
      $phone = trim($_POST["phone"] ?? "");
      $password = (string)($_POST["password"] ?? "");
      $confirm  = (string)($_POST["confirm_password"] ?? "");

      $errors = [];
      if ($name === "" || $email === "" || $password === "" || $confirm === "") $errors[] = "All required fields must be filled.";
      if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
      if ($password !== "" && strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
      if ($password !== $confirm) $errors[] = "Password and confirm password do not match.";

      if (!empty($errors)) {
        flash_set_errors($errors);
        $_SESSION["old_input"] = ["name" => $name, "email" => $email, "phone" => $phone];
        redirect_to("index.php?view=create");
      }

      $check = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ? LIMIT 1");
      if (!$check) throw new Exception("Prepare failed (duplicate check).");
      $check->bind_param("s", $email);
      $check->execute();
      $dup = $check->get_result()->fetch_assoc();
      $check->close();

      if ($dup) {
        flash_set_errors(["Traveller email already exists."]);
        $_SESSION["old_input"] = ["name" => $name, "email" => $email, "phone" => $phone];
        redirect_to("index.php?view=create");
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("INSERT INTO travellers (full_name, email, password_hash, phone) VALUES (?,?,?,?)");
      if (!$stmt) throw new Exception("Prepare failed (insert).");
      $stmt->bind_param("ssss", $name, $email, $hash, $phone);

      if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Insert failed.");
      }
      $newId = (int)$stmt->insert_id;
      $stmt->close();

      unset($_SESSION["old_input"]);
      flash_set_success("Traveller created successfully.");
      redirect_to("index.php?view=edit&id=" . $newId);
    }

    /* ---------- UPDATE TRAVELLER PROFILE ---------- */
    if ($action === "update") {
      $id = (int)($_POST["id"] ?? 0);
      if ($id <= 0) {
        flash_set_errors(["Invalid traveller id."]);
        redirect_to("index.php");
      }

      $name = trim($_POST["name"] ?? "");
      $email = trim($_POST["email"] ?? "");
      $phone = trim($_POST["phone"] ?? "");

      $errors = [];
      if ($name === "" || $email === "") $errors[] = "Name and email are required.";
      if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";

      if (!empty($errors)) {
        flash_set_errors($errors);
        $_SESSION["old_input"] = ["name" => $name, "email" => $email, "phone" => $phone];
        redirect_to("index.php?view=edit&id=" . $id);
      }

      $check = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ? AND traveller_id <> ? LIMIT 1");
      if (!$check) throw new Exception("Prepare failed (duplicate check).");
      $check->bind_param("si", $email, $id);
      $check->execute();
      $dup = $check->get_result()->fetch_assoc();
      $check->close();

      if ($dup) {
        flash_set_errors(["Email already exists."]);
        $_SESSION["old_input"] = ["name" => $name, "email" => $email, "phone" => $phone];
        redirect_to("index.php?view=edit&id=" . $id);
      }

      $stmt = $conn->prepare("UPDATE travellers SET full_name = ?, email = ?, phone = ? WHERE traveller_id = ?");
      if (!$stmt) throw new Exception("Prepare failed (update).");
      $stmt->bind_param("sssi", $name, $email, $phone, $id);

      if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Update failed.");
      }
      $stmt->close();

      unset($_SESSION["old_input"]);
      flash_set_success("Profile updated.");
      redirect_to("index.php?view=edit&id=" . $id);
    }

    /* ---------- RESET PASSWORD ---------- */
    if ($action === "reset_password") {
      $id = (int)($_POST["id"] ?? 0);
      if ($id <= 0) {
        flash_set_errors(["Invalid traveller id."]);
        redirect_to("index.php");
      }

      $new = (string)($_POST["new_password"] ?? "");
      $confirm = (string)($_POST["confirm_password"] ?? "");

      $errors = [];
      if ($new === "" || $confirm === "") $errors[] = "Password fields are required.";
      if ($new !== "" && strlen($new) < 6) $errors[] = "Password must be at least 6 characters.";
      if ($new !== $confirm) $errors[] = "Password and confirm password do not match.";

      if (!empty($errors)) {
        flash_set_errors($errors);
        redirect_to("index.php?view=reset&id=" . $id);
      }

      // CHANGED: Set must_change_password=1 so traveller is forced to change it on next login
      $hash = password_hash($new, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("UPDATE travellers SET password_hash = ?, must_change_password = 1 WHERE traveller_id = ?");
      if (!$stmt) throw new Exception("Prepare failed (password update).");
      $stmt->bind_param("si", $hash, $id);

      if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Password update failed.");
      }
      $stmt->close();

      // ADDED: Load traveller contact for email notification
      $u = null;
      $st = $conn->prepare("SELECT full_name, email FROM travellers WHERE traveller_id=? LIMIT 1");
      if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        $st->close();
      }

      // ADDED: Send email with temporary password
      $sent = false;
      if ($u && !empty($u["email"])) {
        $sent = sendResetPasswordEmail((string)$u["email"], (string)$u["full_name"], $new);
      }

      if ($sent) {
        flash_set_success("Password updated. Email sent to the traveller.");
      } else {
        // If SMTP config/vendor missing, system still works; only email fails.
        flash_set_success("Password updated. Email sending failed (check SMTP/vendor/autoload).");
      }

      redirect_to("index.php?view=reset&id=" . $id);
    }

    flash_set_errors(["Unknown action."]);
    redirect_to("index.php");
  } catch (Throwable $e) {
    // Optional debug:
    // error_log("User manage error: " . $e->getMessage());
    flash_set_errors(["Operation failed due to a system error."]);
    redirect_to("index.php");
  }
}

/* =============================
   GET views
   ============================= */
$view = strtolower(trim($_GET["view"] ?? "list")); // list | create | edit | reset
if (!in_array($view, ["list", "create", "edit", "reset"], true)) $view = "list";

$success = flash_get("success_message");
$errors = flash_get_errors();
$old = $_SESSION["old_input"] ?? [];
unset($_SESSION["old_input"]);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Management | Admin</title>
  <link rel="stylesheet" href="../../assets/admin_user_manage_style.css">
</head>

<body>
  <div class="app">
    <?php base_admin_sidebar_users(); ?>

    <main class="content">
      <?php if ($success !== ""): ?>
        <div class="notice success"><?php echo h($success); ?></div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="notice error">
          <strong>Action failed:</strong>
          <ul style="margin:8px 0 0; padding-left:18px;">
            <?php foreach ($errors as $e): ?>
              <li><?php echo h((string)$e); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($view === "create"): ?>

        <div class="topbar">
          <div class="page-title">
            <h1>Create Traveller</h1>
            <p>Create a new traveller account. Password must be at least 6 characters.</p>
          </div>
          <div class="actions">
            <a class="btn btn-ghost" href="index.php">Back to list</a>
          </div>
        </div>

        <section class="card">
          <h3>Account Details</h3>
          <p class="meta">Traveller accounts only. Deletion is disabled.</p>

          <form method="post" action="index.php?view=create">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
              <div class="field">
                <label for="name">Full Name</label>
                <input id="name" type="text" name="name" value="<?php echo h((string)($old["name"] ?? "")); ?>" required>
              </div>

              <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo h((string)($old["email"] ?? "")); ?>" required>
              </div>

              <div class="field">
                <label for="phone">Phone (optional)</label>
                <input id="phone" type="text" name="phone" value="<?php echo h((string)($old["phone"] ?? "")); ?>" placeholder="e.g., 012-3456789">
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
                <a class="btn btn-ghost" href="index.php">Cancel</a>
              </div>
            </div>
          </form>
        </section>

      <?php elseif ($view === "edit"): ?>

        <?php
        $id = (int)($_GET["id"] ?? 0);
        if ($id <= 0) {
          flash_set_errors(["Invalid traveller id."]);
          redirect_to("index.php");
        }

        $stmt = $conn->prepare("SELECT traveller_id AS id, full_name AS name, email, phone FROM travellers WHERE traveller_id = ? LIMIT 1");
        if (!$stmt) {
          flash_set_errors(["System error."]);
          redirect_to("index.php");
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
          flash_set_errors(["Traveller not found."]);
          redirect_to("index.php");
        }

        if (!empty($old)) {
          $user["name"]  = $old["name"]  ?? $user["name"];
          $user["email"] = $old["email"] ?? $user["email"];
          $user["phone"] = $old["phone"] ?? ($user["phone"] ?? "");
        }
        ?>

        <div class="topbar">
          <div class="page-title">
            <h1>Edit Traveller</h1>
            <p>ID: <strong><?php echo (int)$user["id"]; ?></strong></p>
          </div>
          <div class="actions">
            <a class="btn btn-ghost" href="index.php">Back to list</a>
            <a class="btn btn-primary" href="index.php?view=reset&id=<?php echo (int)$user["id"]; ?>">Reset Password</a>
          </div>
        </div>

        <section class="card">
          <h3>Profile</h3>
          <p class="meta">Update traveller basic info (name/email/phone). Deletion is disabled.</p>

          <form method="post" action="index.php?view=edit&id=<?php echo (int)$user["id"]; ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$user["id"]; ?>">

            <div class="form-grid">
              <div class="field">
                <label for="name">Full Name</label>
                <input id="name" type="text" name="name" value="<?php echo h((string)$user["name"]); ?>" required>
              </div>

              <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo h((string)$user["email"]); ?>" required>
              </div>

              <div class="field">
                <label for="phone">Phone</label>
                <input id="phone" type="text" name="phone" value="<?php echo h((string)($user["phone"] ?? "")); ?>">
              </div>

              <div class="field full">
                <button class="btn btn-primary" type="submit">Save Changes</button>
                <a class="btn btn-ghost" href="index.php">Cancel</a>
              </div>
            </div>
          </form>
        </section>

      <?php elseif ($view === "reset"): ?>

        <?php
        $id = (int)($_GET["id"] ?? 0);
        if ($id <= 0) {
          flash_set_errors(["Invalid traveller id."]);
          redirect_to("index.php");
        }

        $stmt = $conn->prepare("SELECT traveller_id AS id, full_name AS name, email FROM travellers WHERE traveller_id=? LIMIT 1");
        if (!$stmt) {
          flash_set_errors(["System error."]);
          redirect_to("index.php");
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
          flash_set_errors(["Traveller not found."]);
          redirect_to("index.php");
        }
        ?>

        <div class="topbar">
          <div class="page-title">
            <h1>Reset Password</h1>
            <p><?php echo h((string)$user["name"]); ?> (ID: <strong><?php echo (int)$user["id"]; ?></strong>)</p>
          </div>
          <div class="actions">
            <a class="btn btn-ghost" href="index.php">Back to list</a>
            <a class="btn btn-ghost" href="index.php?view=edit&id=<?php echo (int)$user["id"]; ?>">Edit Profile</a>
          </div>
        </div>

        <section class="card">
          <h3>Set New Password</h3>
          <p class="meta">Password must be at least 6 characters.</p>

          <form method="post" action="index.php?view=reset&id=<?php echo (int)$user["id"]; ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" value="<?php echo (int)$user["id"]; ?>">

            <div class="form-grid">
              <div class="field">
                <label for="new_password">New Password</label>
                <input id="new_password" type="password" name="new_password" minlength="6" required>
              </div>

              <div class="field">
                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" type="password" name="confirm_password" minlength="6" required>
              </div>

              <div class="field full">
                <button class="btn btn-primary" type="submit">Update Password</button>
                <a class="btn btn-ghost" href="index.php">Cancel</a>
              </div>
            </div>
          </form>
        </section>

      <?php else: ?>

        <?php
        // LIST VIEW
        $q = trim($_GET["q"] ?? "");
        $page = (int)($_GET["page"] ?? 1);
        if ($page < 1) $page = 1;

        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = "";
        $params = [];
        $types = "";

        if ($q !== "") {
          $where = "WHERE (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
          $like = "%" . $q . "%";
          $params = [$like, $like, $like];
          $types = "sss";
        }

        // Count travellers only
        $total = 0;
        $countSql = "SELECT COUNT(*) AS c FROM travellers {$where}";
        $countStmt = $conn->prepare($countSql);
        if ($countStmt) {
          if ($types !== "") $countStmt->bind_param($types, ...$params);
          $countStmt->execute();
          $total = (int)($countStmt->get_result()->fetch_assoc()["c"] ?? 0);
          $countStmt->close();
        }

        // Data
        $rows = [];
        $sql = "SELECT traveller_id AS id, full_name AS name, email, phone
                  FROM travellers {$where}
                  ORDER BY traveller_id DESC
                  LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
          if ($types === "") {
            $stmt->bind_param("ii", $perPage, $offset);
          } else {
            $bindTypes = $types . "ii";
            $bindParams = array_merge($params, [$perPage, $offset]);
            $stmt->bind_param($bindTypes, ...$bindParams);
          }
          $stmt->execute();
          $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
          $stmt->close();
        }

        $totalPages = (int)ceil(max(1, $total) / $perPage);
        if ($totalPages < 1) $totalPages = 1;

        $mk = function (int $p) use ($q): string {
          $qs = http_build_query(["q" => $q, "page" => $p]);
          return "index.php?" . $qs;
        };
        ?>

        <div class="topbar">
          <div class="page-title">
            <h1>User Management</h1>
            <p>Traveller accounts only (view, create, update, reset password). Deletion is disabled.</p>
          </div>
          <div class="actions">
            <a class="btn btn-primary" href="index.php?view=create">Add Traveller</a>
          </div>
        </div>

        <section class="card">
          <h3>Travellers List</h3>
          <p class="meta">Total Travellers: <strong><?php echo (int)$total; ?></strong></p>

          <form class="filters" method="get" action="index.php">
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search name / email / phone">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-ghost" href="index.php">Reset</a>
          </form>

          <div style="height:12px"></div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Phone</th>
                  <th style="min-width:220px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr>
                    <td colspan="5">No records found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td><?php echo (int)$r["id"]; ?></td>
                      <td><?php echo h((string)$r["name"]); ?></td>
                      <td><?php echo h((string)$r["email"]); ?></td>
                      <td><?php echo h((string)($r["phone"] ?? "")); ?></td>
                      <td>
                        <div class="actions-inline">
                          <a class="btn btn-ghost" href="index.php?view=edit&id=<?php echo (int)$r["id"]; ?>">Edit</a>
                          <a class="btn btn-primary" href="index.php?view=reset&id=<?php echo (int)$r["id"]; ?>">Reset Password</a>
                          <!-- NO DELETE -->
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
              <a class="btn btn-ghost" href="<?php echo h($mk(max(1, $page - 1))); ?>">Prev</a>
              <a class="btn btn-ghost" href="<?php echo h($mk(min($totalPages, $page + 1))); ?>">Next</a>
            </div>
          </div>
        </section>

      <?php endif; ?>
    </main>
  </div>
</body>

</html>