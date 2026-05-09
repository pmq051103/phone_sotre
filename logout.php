<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    $_SESSION = [];
    session_destroy();
    setFlash('success', 'Đăng xuất thành công!');
}

redirect('index.php');
?>

