<?php
// config/db_connect.php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "travel_itinerary_db";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
 