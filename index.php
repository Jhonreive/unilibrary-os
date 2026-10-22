<?php
require __DIR__ . '/includes/auth.php';
header('Location: ' . (!empty($_SESSION['user_id']) ? 'dashboard.php' : 'login.php'));
exit;
