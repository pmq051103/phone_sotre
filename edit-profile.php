<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (!isLoggedIn()) {
    redirect("login.php");
}

$pageTitle = "Sửa thông tin - " . SITE_NAME;
$user = getCurrentUser();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name  = trim($_POST["full_name"] ?? "");
    $phone      = trim($_POST["phone"] ?? "");
    $address    = trim($_POST["address"] ?? "");
    $csrf_token = $_POST["csrf_token"] ?? "";

    if (!verifyToken($csrf_token)) $errors[] = "Token không hợp lệ";
    if ($full_name === "") $errors[] = "Vui lòng nhập họ tên";

    if ($phone !== "" && !preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
        $errors[] = "Số điện thoại không hợp lệ";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE users 
            SET full_name = ?, phone = ?, address = ? 
            WHERE id = ?
        ");

        $userId = (int)$_SESSION["user_id"];

        $stmt->bind_param("sssi", $full_name, $phone, $address, $userId);
        $ok = $stmt->execute();

        if ($ok) {
            setFlash("success", "Cập nhật thành công!");
            redirect("profile.php");
        } else {
            $errors[] = "Có lỗi xảy ra. Vui lòng thử lại.";
        }
    }
}

include "includes/header.php";
?>

<div class="container">
  <div class="account-layout">

    <aside class="account-sidebar card">
      <div class="account-sidebar-header">
        <i class="fas fa-user"></i><span>Tài khoản</span>
      </div>

      <nav class="account-nav">
        <a href="profile.php" class="account-nav-item">
          <i class="fas fa-user-circle"></i><span>Thông tin cá nhân</span>
        </a>
        <a href="orders.php" class="account-nav-item">
          <i class="fas fa-box"></i><span>Đơn hàng</span>
        </a>
        <a href="change-password.php" class="account-nav-item">
          <i class="fas fa-key"></i><span>Đổi mật khẩu</span>
        </a>
      </nav>
    </aside>

    <main class="account-main">
      <div class="card account-card">
        <div class="account-card-header">
          <div class="account-card-title">
            <i class="fas fa-user-pen"></i>
            <h3>Chỉnh sửa thông tin</h3>
          </div>
          <a href="profile.php" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Quay lại
          </a>
        </div>

        <div class="account-card-body">

          <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
              <div class="d-flex align-items-center gap-2">
                <i class="fas fa-triangle-exclamation"></i>
                <strong>Có lỗi xảy ra</strong>
              </div>
              <ul class="auth-errors">
                <?php foreach ($errors as $error): ?>
                  <li><?= e($error) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="POST" class="account-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">

            <div class="form-group">
              <label for="full_name">Họ tên <span class="req">*</span></label>
              <input id="full_name" type="text" name="full_name" class="input"
                     value="<?= e($_POST["full_name"] ?? ($user["full_name"] ?? "")) ?>" required>
            </div>

            <div class="form-group">
              <label for="phone">Số điện thoại</label>
              <input id="phone" type="text" name="phone" class="input"
                     value="<?= e($_POST["phone"] ?? ($user["phone"] ?? "")) ?>">
            </div>

            <div class="form-group">
              <label for="address">Địa chỉ</label>
              <textarea id="address" name="address" class="input textarea" rows="4"><?= e($_POST["address"] ?? ($user["address"] ?? "")) ?></textarea>
            </div>

            <div class="auth-actions">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Lưu thay đổi
              </button>
              <a href="profile.php" class="btn btn-outline">
                <i class="fas fa-times"></i> Hủy
              </a>
            </div>
          </form>

        </div>
      </div>
    </main>

  </div>
</div>


<?php include "includes/footer.php"; ?>
