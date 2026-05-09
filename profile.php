<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (!isLoggedIn()) {
    setFlash("warning", "Vui lòng đăng nhập");
    redirect("login.php");
}

$pageTitle = "Thông tin cá nhân - " . SITE_NAME;
$user = getCurrentUser();

include "includes/header.php";
?>

<div class="container">
    <div class="account-layout">
        <!-- Sidebar -->
        <aside class="account-sidebar card">
            <div class="account-sidebar-header">
                <i class="fas fa-user"></i>
                <span>Tài khoản</span>
            </div>

            <nav class="account-nav">
                <a href="profile.php" class="account-nav-item is-active">
                    <i class="fas fa-user-circle"></i>
                    <span>Thông tin cá nhân</span>
                </a>
                <a href="orders.php" class="account-nav-item">
                    <i class="fas fa-box"></i>
                    <span>Đơn hàng</span>
                </a>
                <a href="change-password.php" class="account-nav-item">
                    <i class="fas fa-key"></i>
                    <span>Đổi mật khẩu</span>
                </a>
            </nav>
        </aside>

        <!-- Main -->
        <main class="account-main">
            <div class="card account-card">
                <div class="account-card-header">
                    <div class="account-card-title">
                        <i class="fas fa-id-card"></i>
                        <h3>Thông tin cá nhân</h3>
                    </div>
                    <a href="edit-profile.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit"></i> Chỉnh sửa
                    </a>
                </div>

                <div class="account-card-body">
                    <div class="info-grid">
                        <div class="info-row">
                            <div class="info-label">Họ tên</div>
                            <div class="info-value"><?= e($user["full_name"] ?? "") ?></div>
                        </div>

                        <div class="info-row">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?= e($user["email"] ?? "") ?></div>
                        </div>

                        <div class="info-row">
                            <div class="info-label">Số điện thoại</div>
                            <div class="info-value"><?= e($user["phone"] ?? "Chưa cập nhật") ?></div>
                        </div>

                        <div class="info-row">
                            <div class="info-label">Địa chỉ</div>
                            <div class="info-value"><?= e($user["address"] ?? "Chưa cập nhật") ?></div>
                        </div>

                        <div class="info-row">
                            <div class="info-label">Ngày tham gia</div>
                            <div class="info-value" style="white-space:nowrap;">
                                <?= e(date('d/m/Y', strtotime($user["created_at"] ?? 'now'))) ?></div>
                        </div>
                    </div>

                    <div class="account-hint">
                        <i class="fas fa-circle-info"></i>
                        <span>Thông tin này dùng để giao hàng nhanh hơn khi bạn đặt đơn.</span>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include "includes/footer.php"; ?>