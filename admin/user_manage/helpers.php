<?php
// admin/user_manage/helpers.php

function require_admin(): void
{
  session_start();

  if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../../auth/login.php?role=admin");
    exit;
  }
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
  if (!is_array($errs)) return [];
  return $errs;
}

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function pick_role(string $role): string
{
  $role = strtolower(trim($role));
  return in_array($role, ["traveller", "admin"], true) ? $role : "traveller";
}

function user_table(string $role): string
{
  return $role === "admin" ? "admins" : "travellers";
}

function user_id_field(string $role): string
{
  return $role === "admin" ? "admin_id" : "traveller_id";
}

function user_name_field(string $role): string
{
  return $role === "admin" ? "username" : "full_name";
}

function user_phone_field(string $role): ?string
{
  return $role === "traveller" ? "phone" : null;
}

function base_admin_sidebar(string $active): void
{
  $adminName = $_SESSION["admin_name"] ?? "Administrator";

  $items = [
    ["Dashboard", "../../admin/admin_dashboard.php", $active === "dashboard"],
    ["State Cultural Knowledge Base", "../admin_cultural_kb.php", $active === "kb"],
    ["Content Validation", "../admin_pending.php", $active === "pending"],
    ["User Management", "./index.php", $active === "users"],
    ["Logout", "../../auth/logout.php", false],
  ];
?>
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-badge">ST</div>
      <div class="brand-title">
        <strong>Smart Travel Itinerary Generator</strong>
        <span>Admin</span>
      </div>
    </div>

    <nav class="nav" aria-label="Sidebar Navigation">
      <?php foreach ($items as [$label, $href, $isActive]): ?>
        <a class="<?php echo $isActive ? 'active' : ''; ?>" href="<?php echo h($href); ?>"><span class="dot"></span> <?php echo h($label); ?></a>
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
