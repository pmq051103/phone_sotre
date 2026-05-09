<?php
$currentUser = $currentUser ?? getCurrentUser();

function isActive($needle){
  return (strpos($_SERVER['REQUEST_URI'], $needle) !== false) ? 'active' : '';
}
?>
<aside class="admin-sidebar">
    <a class="admin-brand" href="<?= BASE_URL ?>/admin/index.php">
        <i class="fa-solid fa-gauge"></i>
        <span>Admin Panel</span>
    </a>

    <nav class="admin-nav">
        <?php if (hasRole(['admin'])): ?>
        <a class="<?= isActive('/admin/index.php') ?>" href="<?= BASE_URL ?>/admin/index.php">
            <i class="fa-solid fa-chart-line"></i> Dashboard
        </a>
        <?php endif; ?>

        <a class="<?= isActive('/admin/products') ?>" href="<?= BASE_URL ?>/admin/products/list.php">
            <i class="fa-solid fa-box"></i> Sản phẩm
        </a>

        <a class="<?= isActive('/admin/brands') ?>" href="<?= BASE_URL ?>/admin/brands/list.php">
            <i class="fa-solid fa-tags"></i> Hãng
        </a>

        <a class="<?= isActive('/admin/categories') ?>" href="<?= BASE_URL ?>/admin/categories/list.php">
            <i class="fa-solid fa-layer-group"></i> Loại
        </a>

        <a class="<?= isActive('/admin/discounts') ?>" href="<?= BASE_URL ?>/admin/discounts/list.php">
            <i class="fa-solid fa-ticket"></i> Mã giảm giá
        </a>

        <a class="<?= isActive('/admin/orders') ?>" href="<?= BASE_URL ?>/admin/orders/list.php">
            <i class="fa-solid fa-receipt"></i> Đơn hàng
        </a>

        <a class="<?= isActive('/admin/users') ?>" href="<?= BASE_URL ?>/admin/users/list.php">
            <i class="fa-solid fa-users"></i> Người dùng
        </a>
        <?php if (hasRole(['admin'])): ?>
        <a class="<?= isActive('/admin/reviews') ?>" href="<?= BASE_URL ?>/admin/reviews/list.php">
            <i class="fa-solid fa-star"></i> Đánh giá
        </a>
        <?php endif; ?>
        <?php if (hasRole(['admin'])): ?>
        <a class="<?= isActive('/admin/approvals') ?>" href="<?= BASE_URL ?>/admin/approvals/list.php">
            <i class="fa-solid fa-circle-check"></i> Phê duyệt
        </a>
        <?php endif; ?>

    </nav>

    <div class="admin-userbox">
        <div class="name">
            <i class="fa-solid fa-user-shield"></i>
            <?= e($currentUser['full_name'] ?? 'Admin') ?>
        </div>

        <a class="btn btn-outline btn-sm" style="width:100%;" href="<?= BASE_URL ?>/index.php">
            <i class="fa-solid fa-arrow-left"></i> Về trang người dùng
        </a>

        <a class="btn btn-primary btn-sm" style="width:100%;" href="<?= BASE_URL ?>/logout.php">
            <i class="fa-solid fa-right-from-bracket"></i> Đăng xuất
        </a>
    </div>
</aside>