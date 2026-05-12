<?php
session_start();
require_once "../config/db_connect.php";
require_once __DIR__ . "/remember_me.php";
clear_remember_token($conn);
session_unset();
session_destroy();
header("Location: ../index.php");
exit;
