<?php
// auth/register.php
session_start();
require_once "../config/db_connect.php";

/**
 * Preselect role via URL: register.php?role=admin / register.php?role=traveller
 */
$defaultRole = (isset($_GET["role"]) && in_array($_GET["role"], ["admin", "traveller"], true))
    ? $_GET["role"]
    : "traveller";

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $role     = $_POST["role"] ?? $defaultRole;
    $fullName = trim($_POST["full_name"] ?? "");
    $email    = trim($_POST["email"] ?? "");
    $phone    = trim($_POST["phone"] ?? ""); // traveller only, optional
    $password = $_POST["password"] ?? "";
    $confirm  = $_POST["confirm_password"] ?? "";

    if (!in_array($role, ["admin", "traveller"], true)) {
        $role = "traveller";
    }

    // Validate inputs
    if ($fullName === "" || $email === "" || $password === "" || $confirm === "") {
        $errors[] = "All required fields must be filled.";
    }

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if ($password !== "" && strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    if ($password !== $confirm) {
        $errors[] = "Password and confirm password do not match.";
    }

    if (empty($errors)) {
        // Check duplicates in the correct table
        if ($role === "admin") {
            // For admin: we store username in "username" column
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? OR username = ?");
            $check->bind_param("ss", $email, $fullName);
        } else {
            // For traveller: we store full name in "full_name" column, duplicate check mainly on email
            $check = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ?");
            $check->bind_param("s", $email);
        }

        if (!$check) {
            $errors[] = "System error. Please try again later.";
        } else {
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $errors[] = ($role === "admin")
                    ? "Admin username or email already exists."
                    : "Traveller email already exists.";
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert into correct table
                if ($role === "admin") {
                    $stmt = $conn->prepare(
                        "INSERT INTO admins (username, email, password_hash) VALUES (?,?,?)"
                    );
                    $stmt->bind_param("sss", $fullName, $email, $passwordHash);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO travellers (full_name, email, password_hash, phone) VALUES (?,?,?,?)"
                    );
                    $stmt->bind_param("ssss", $fullName, $email, $passwordHash, $phone);
                }

                if (!$stmt) {
                    $errors[] = "System error. Please try again later.";
                } else {
                    if ($stmt->execute()) {
                        $_SESSION["success_message"] = "Registration successful. Please login.";
                        // Redirect to login with same role preselected
                        header("Location: login.php?role=" . urlencode($role));
                        exit;
                    } else {
                        $errors[] = "Registration failed. Please try again.";
                    }
                    $stmt->close();
                }
            }

            $check->close();
        }
    }

    $defaultRole = $role; // keep selected role on error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>

<div class="main-container">
    <div class="left-panel">
        <h1>Create Account</h1>
        <p>
            Register as a traveller to access itinerary features, or as an admin
            to manage system content and cultural data.
        </p>
    </div>

    <div class="right-panel">
        <h2 class="form-title">Register</h2>
        <p class="form-subtitle">Fill in the details below to create your account.</p>

        <?php
        if (!empty($errors)) {
            echo "<ul style='color:red; margin:0 0 12px 18px;'>";
            foreach ($errors as $e) {
                echo "<li>" . htmlspecialchars($e) . "</li>";
            }
            echo "</ul>";
        }
        ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="role">Register as</label>
                <select id="role" name="role" required>
                    <option value="traveller" <?php echo ($defaultRole === "traveller") ? "selected" : ""; ?>>Traveller</option>
                    <option value="admin" <?php echo ($defaultRole === "admin") ? "selected" : ""; ?>>Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label for="full_name">Full Name / Username</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    required
                    value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                >
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                >
            </div>

            <div class="form-group">
                <label for="phone">Phone (Traveller only, optional)</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary">Register</button>
        </form>

        <div class="form-footer">
            Already registered?
            <a href="login.php?role=<?php echo urlencode($defaultRole); ?>">Login here</a>
        </div>
    </div>
</div>

</body>
</html>
