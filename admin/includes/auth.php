<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','staff']);

$isAdmin = isAdmin();
$isStaff = isStaff();

if (!isLoggedIn()) {
  setFlash('danger', 'Vui lòng đăng nhập');
  redirect(BASE_URL . '/login.php');
}

$user = getCurrentUser();
$role = $user['role'] ?? 'user';

if (!in_array($role, ['admin', 'staff'], true)) {
  setFlash('danger', 'Bạn không có quyền truy cập Admin');
  redirect(BASE_URL . '/index.php');
}
