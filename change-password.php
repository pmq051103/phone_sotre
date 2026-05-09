<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (!isLoggedIn()) {
    setFlash("warning", "Vui lòng đăng nhập");
    redirect("login.php");
}

$pageTitle = "Đổi mật khẩu - " . SITE_NAME;
$user = getCurrentUser();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current_password = $_POST["current_password"] ?? "";
    $new_password     = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";
    $csrf_token       = $_POST["csrf_token"] ?? "";

    if (!verifyToken($csrf_token)) $errors[] = "Token không hợp lệ";
    if (trim($current_password) === "") $errors[] = "Vui lòng nhập mật khẩu hiện tại";
    if (trim($new_password) === "") $errors[] = "Vui lòng nhập mật khẩu mới";
    if (strlen($new_password) < 6) $errors[] = "Mật khẩu phải có ít nhất 6 ký tự";
    if ($new_password !== $confirm_password) $errors[] = "Mật khẩu xác nhận không khớp";

    if (empty($errors)) {
        $userId = (int)$_SESSION["user_id"];

        // ===== LẤY PASSWORD HIỆN TẠI =====
        $stmt = $conn->prepare("
            SELECT password 
            FROM users 
            WHERE id = ? AND status = 1 
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $u = $result->fetch_assoc();

        if (!password_verify($current_password, $u["password"])) {
            $errors[] = "Mật khẩu hiện tại không đúng";
        } else {
            // ===== UPDATE PASSWORD =====
            $newHash = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                UPDATE users 
                SET password = ? 
                WHERE id = ?
            ");
            $stmt->bind_param("si", $newHash, $userId);
            $ok = $stmt->execute();

            if ($ok) {
                setFlash("success", "Đổi mật khẩu thành công!");
                redirect("profile.php");
            } else {
                $errors[] = "Không thể đổi mật khẩu. Vui lòng thử lại.";
            }
        }
    }
}

include "includes/header.php";
?>

<div class="container">
  <div class="account-layout">

    <!-- Sidebar -->
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
        <a href="change-password.php" class="account-nav-item is-active">
          <i class="fas fa-key"></i><span>Đổi mật khẩu</span>
        </a>
      </nav>
    </aside>

    <!-- Main -->
    <main class="account-main">
      <div class="card account-card">
        <div class="account-card-header">
          <div class="account-card-title">
            <i class="fas fa-key"></i>
            <h3>Đổi mật khẩu</h3>
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
              <label for="current_password">Mật khẩu hiện tại <span class="req">*</span></label>
              <div class="input-wrap">
                <input id="current_password" type="password" name="current_password" class="input" required autocomplete="current-password">
                <button type="button" class="toggle-pass" data-target="current_password" aria-label="Hiện/ẩn mật khẩu">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>

            <div class="form-group">
              <label for="new_password">Mật khẩu mới <span class="req">*</span></label>
              <div class="input-wrap">
                <input id="new_password" type="password" name="new_password" class="input" required minlength="6" autocomplete="new-password">
                <button type="button" class="toggle-pass" data-target="new_password" aria-label="Hiện/ẩn mật khẩu">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <div class="help-text">
                <i class="fas fa-circle-info"></i>
                Tối thiểu 6 ký tự
              </div>
            </div>

            <div class="form-group">
              <label for="confirm_password">Xác nhận mật khẩu <span class="req">*</span></label>
              <div class="input-wrap">
                <input id="confirm_password" type="password" name="confirm_password" class="input" required autocomplete="new-password">
                <button type="button" class="toggle-pass" data-target="confirm_password" aria-label="Hiện/ẩn mật khẩu">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>

            <div class="auth-actions">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Lưu mật khẩu
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

<script>
(function(){
  document.querySelectorAll('.toggle-pass').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-target'); // current_password
      var input = document.getElementById(id);
      if(!input) return;
      var icon = btn.querySelector('i');
      var isPass = input.type === 'password';
      input.type = isPass ? 'text' : 'password';
      if(icon){
        icon.classList.toggle('fa-eye', !isPass);
        icon.classList.toggle('fa-eye-slash', isPass);
      }
    });
  });
})();
</script>

<?php include "includes/footer.php"; ?>
