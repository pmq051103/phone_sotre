<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($pageTitle)) $pageTitle = SITE_NAME;
$currentUser = getCurrentUser();
$cartCount = getCartCount();
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/index.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/products.css">
    <?php if (basename($_SERVER['PHP_SELF']) === 'login.php' || basename($_SERVER['PHP_SELF']) === 'register.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/login.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'product-detail.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/product-detail.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'forgot-password.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/forgot-password.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'orders.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/order.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'profile.php' || basename($_SERVER['PHP_SELF']) === 'edit-profile.php' 
        || basename($_SERVER['PHP_SELF']) === 'change-password.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/profile.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'cart.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cart.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'checkout.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/checkout.css">
    <?php endif; ?>
    <?php if (basename($_SERVER['PHP_SELF']) === 'order-detail.php'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/order-detail.css">
    <?php endif; ?>
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container">
            <a class="navbar-brand" href="<?= BASE_URL ?>/index.php">
                <img src="<?= BASE_URL ?>/uploads/logo.jpg" alt="PhoneStore" class="navbar-logo">
                <span class="brand-text">PhoneStore</span>
            </a>


            <!-- Search Form -->
            <form class="search-form" action="<?= BASE_URL ?>/products.php" method="GET">
                <input type="search" name="search" placeholder="Tìm kiếm điện thoại..."
                    value="<?= e($_GET['search'] ?? '') ?>">
                <button type="submit">
                    <i class="fas fa-search"></i>
                </button>
            </form>

            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/index.php">
                        <i class="fas fa-home"></i>
                        <span>Trang chủ</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/products.php">
                        <i class="fas fa-mobile-alt"></i>
                        <span>Sản phẩm</span>
                    </a>
                </li>

                <?php if (isLoggedIn()): ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/cart.php">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Giỏ hàng</span>
                        <?php if ($cartCount > 0): ?>
                        <span class="cart-badge"><?= $cartCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="nav-item dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-user"></i>
                        <span><?= e($currentUser['full_name']) ?></span>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="<?= BASE_URL ?>/profile.php">
                                <i class="fas fa-user-circle"></i>
                                <span>Thông tin cá nhân</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= BASE_URL ?>/orders.php">
                                <i class="fas fa-box"></i>
                                <span>Đơn hàng của tôi</span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= BASE_URL ?>/change-password.php">
                                <i class="fas fa-key"></i>
                                <span>Đổi mật khẩu</span>
                            </a>
                        </li>

                        <?php if (canAccessAdmin()): ?>
                        <li class="dropdown-divider"></li>
                        <li>
                            <?php if (isAdmin()): ?>
                            <a href="<?= BASE_URL ?>/admin/index.php" style="color: var(--danger-color);">
                                <i class="fas fa-cog"></i>
                                <span>Quản trị Admin</span>
                            </a>
                            <?php elseif (isStaff()): ?>
                            <a href="<?= BASE_URL ?>/admin/products/list.php" style="color: var(--danger-color);">
                                <i class="fas fa-cog"></i>
                                <span>Nhân viên</span>
                            </a>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>


                        <li class="dropdown-divider"></li>
                        <li>
                            <a href="<?= BASE_URL ?>/logout.php">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Đăng xuất</span>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Đăng nhập</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= BASE_URL ?>/register.php">
                        <i class="fas fa-user-plus"></i>
                        <span>Đăng ký</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Flash Messages -->
    <?php $flash = getFlash(); ?>
    <?php if ($flash): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        showNotification(<?= json_encode($flash['message']) ?>, <?= json_encode($flash['type']) ?>, 1000);
    });
    </script>
    <?php endif; ?>


    <main>