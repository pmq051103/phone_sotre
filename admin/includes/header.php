<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/auth.php';

if (!isset($pageTitle)) $pageTitle = 'Admin - ' . SITE_NAME;
$currentUser = getCurrentUser();
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','staff']);

$isAdmin = isAdmin();
$isStaff = isStaff();
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= e($pageTitle) ?></title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- reuse style user -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/index.css">
  <!-- admin layout -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/modal.css">

</head>
<body>

<?php $flash = getFlash(); ?>
<?php if ($flash): ?>
  <div class="toast toast-<?= e($flash['type']) ?>" id="flashToast">
    <span class="toast-msg"><?= e($flash['message']) ?></span>
    <button type="button" class="toast-close" onclick="document.getElementById('flashToast')?.remove()">×</button>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const t = document.getElementById('flashToast');
      if (!t) return;
      setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translateY(-10px)';
        setTimeout(() => t.remove(), 300);
      }, 2500);
    });
  </script>
<?php endif; ?>
<!-- jQuery -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<!-- Summernote -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>

<div class="admin-layout">
