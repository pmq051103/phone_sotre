<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (isLoggedIn()) {
    redirect("index.php");
}

$pageTitle = "Quên mật khẩu - " . SITE_NAME;
$errors = [];
$success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    
    if (empty($email)) {
        $errors[] = "Vui lòng nhập email";
    } elseif (!isValidEmail($email)) {
        $errors[] = "Email không hợp lệ";
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id, email 
            FROM users 
            WHERE email = ? AND status = 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            $success = true;
        } else {
            $errors[] = "Email không tồn tại trong hệ thống";
        }
    }
}

include "includes/header.php";
?>

<div class="container">
    <div class="auth-wrap">
        <div class="card auth-card">
            <div class="auth-head">
                <div class="auth-icon">
                    <i class="fas fa-key"></i>
                </div>
                <h1 class="auth-title">Quên mật khẩu</h1>
                <p class="auth-subtitle">Nhập email để nhận hướng dẫn khôi phục</p>
            </div>

            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <div style="display:flex; gap:10px; align-items:flex-start;">
                            <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                            <div>
                                <strong>Đã gửi hướng dẫn</strong>
                                <div style="margin-top:6px;">
                                    Hướng dẫn reset mật khẩu đã được gửi đến email của bạn.
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="login.php" class="btn btn-primary btn-lg auth-btn">
                        <i class="fas fa-arrow-left"></i> Về trang đăng nhập
                    </a>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <div style="display:flex; gap:10px; align-items:flex-start;">
                                <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
                                <div>
                                    <strong>Không thể gửi yêu cầu</strong>
                                    <ul class="auth-errors">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= e($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="auth-note">
                        Nhập email bạn đã dùng để đăng ký. Nếu email tồn tại, hệ thống sẽ gửi hướng dẫn khôi phục mật khẩu.
                    </p>

                    <form method="POST" action="" class="auth-form">
                        <label class="field">
                            <span class="label">Email</span>
                            <input
                                type="email"
                                name="email"
                                class="input"
                                value="<?= e($_POST["email"] ?? "") ?>"
                                placeholder="vd: you@email.com"
                                required
                            >
                        </label>

                        <button type="submit" class="btn btn-primary btn-lg auth-btn">
                            <i class="fas fa-paper-plane"></i> Gửi yêu cầu
                        </button>

                        <div class="auth-foot">
                            <a class="link strong" href="login.php">Quay lại đăng nhập</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include "includes/footer.php"; ?>'