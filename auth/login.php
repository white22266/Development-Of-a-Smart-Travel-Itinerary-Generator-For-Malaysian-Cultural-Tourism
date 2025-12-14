<?php
// auth/login.php
session_start();
require_once "../config/db_connect.php";
// optional: 通过 ?role=admin / ?role=traveller 预选角色
$defaultRole = isset($_GET['role']) ? $_GET['role'] : 'traveller';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="main-container">
    <div class="left-panel">
        <h1>Welcome Back</h1>
        <p>
            Sign in to continue managing cultural information (Admin) or 
            to access your personalised travel itinerary (Traveller).
        </p>
    </div>
    <div class="right-panel">
        <h2 class="form-title">Login</h2>
        <p class="form-subtitle">Please enter your email and password to login.</p>

        <!-- 在这里插入你的 PHP 错误消息逻辑（如果有） -->

        <form method="post" action="login_process.php">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="role">Login as</label>
                <select id="role" name="role">
                    <option value="traveller" <?php echo $defaultRole === 'traveller' ? 'selected' : ''; ?>>Traveller</option>
                    <option value="admin" <?php echo $defaultRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Login</button>
        </form>

        <div class="form-footer">
            Haven’t registered?
            <a href="register.php">Create an account</a>
        </div>
    </div>
</div>
</body>
</html>