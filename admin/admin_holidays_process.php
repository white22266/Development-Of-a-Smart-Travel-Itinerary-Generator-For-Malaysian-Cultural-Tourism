<?php
// admin/admin_holidays_process.php
// Adds or deletes one public holiday entered by the admin (table public_holidays).
session_start();
require_once "../config/db_connect.php";
require_once "../services/PlannerCalendar.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminId = (int)($_SESSION["admin_id"] ?? 0);
$year = (int)($_POST["year"] ?? date("Y"));
if ($year < 2000 || $year > 2100) $year = (int)date("Y");

function back($msg, $isError = false, int $year = 0)
{
    if ($isError) $_SESSION["form_errors"] = [$msg];
    else $_SESSION["success_message"] = $msg;
    header("Location: admin_holidays.php" . ($year > 0 ? "?year=" . $year : ""));
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") back("Invalid request.", true, $year);

$action = strtolower(trim((string)($_POST["action"] ?? "")));

if ($action === "delete") {
    $id = (int)($_POST["holiday_id"] ?? 0);
    if ($id <= 0) back("Invalid holiday id.", true, $year);
    if (PlannerCalendar::deleteHoliday($conn, $id)) back("Holiday deleted.", false, $year);
    back("Holiday not found.", true, $year);
}

if ($action === "add") {
    $date = trim((string)($_POST["holiday_date"] ?? ""));
    $name = (string)($_POST["name"] ?? "");
    $scope = (string)($_POST["scope"] ?? "all");
    $states = null;
    if ($scope === "states") {
        $posted = $_POST["states"] ?? [];
        if (!is_array($posted) || !$posted) back("Tick at least one state, or choose whole country.", true, $year);
        $states = array_values($posted);
    } elseif ($scope !== "all") {
        back("Invalid scope.", true, $year);
    }

    $error = PlannerCalendar::addHoliday($conn, $date, $name, $states, $adminId);
    $dateYear = (int)substr($date, 0, 4);
    if ($dateYear >= 2000 && $dateYear <= 2100) $year = $dateYear;
    if ($error !== "") back($error, true, $year);
    back("Holiday added.", false, $year);
}

back("Invalid action.", true, $year);
