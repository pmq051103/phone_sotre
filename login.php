<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Nếu đã đăng nhập thì redirect
if (isLoggedIn()) {
    redirect('index.php');
}

$pageTitle = 'Đăng nhập - ' . SITE_NAME;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // ===== Validate =====
    if (empty($email)) {
        $errors[] = 'Vui lòng nhập email';
    }
    
    if (empty($password)) {
        $errors[] = 'Vui lòng nhập mật khẩu';
    }
    
    if (!verifyToken($csrf_token)) {
        $errors[] = 'Token không hợp lệ';
    }
    
    if (empty($errors)) {
        // ===== Kiểm tra user =====
        // Bước 4
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        // Bước 5
        $result = $stmt->get_result();
        // Bước 6
        $user = $result->fetch_assoc();

        if (!$user) {
            $errors[] = 'Email hoặc mật khẩu không đúng';
        }
        elseif ((int)$user['status'] === 0) {
            $errors[] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
        }
        elseif (!password_verify($password, $user['password'])) {
            $errors[] = 'Email hoặc mật khẩu không đúng';
        }
        else {
            // ===== Đăng nhập thành công =====
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_name']  = $user['full_name'];

            setFlash('success', 'Đăng nhập thành công!');

            if ($user['role'] === 'admin') {
                redirect(BASE_URL . '/admin/index.php');
            } 
            elseif ($user['role'] === 'staff') {
                redirect(BASE_URL . '/admin/products/list.php');
            } 
            else {
                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);
                redirect($redirect);
            }
        }
    }
}

include 'includes/header.php';
?>

<div class="container">
    <div class="auth-wrap">
        <div class="card auth-card">
            <div class="auth-head">
                <div class="auth-icon">
                    <i class="fas fa-sign-in-alt"></i>
                </div>
                <h1 class="auth-title">Đăng nhập</h1>
                <p class="auth-subtitle">Chào mừng bạn quay lại PhoneStore</p>
            </div>

            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <div style="display:flex; gap:10px; align-items:flex-start;">
                        <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
                        <div>
                            <strong>Đăng nhập thất bại</strong>
                            <ul class="auth-errors">
                                <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">

                    <label class="field">
                        <span class="label">Email</span>
                        <input type="email" name="email" class="input" value="<?= e($_POST['email'] ?? '') ?>"
                            placeholder="vd: you@email.com" required>
                    </label>

                    <label class="field">
                        <span class="label">Mật khẩu</span>
                        <div class="password-wrap">
                            <input type="password" name="password" class="input" id="password"
                                placeholder="Nhập mật khẩu" required>
                            <button type="button" class="pw-toggle" id="togglePw" aria-label="Hiện/ẩn mật khẩu">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </label>

                    <div class="auth-row">
                        <label class="check">
                            <input type="checkbox" id="remember">
                            <span>Ghi nhớ đăng nhập</span>
                        </label>

                        <a class="link" href="forgot-password.php">Quên mật khẩu?</a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg auth-btn">
                        <i class="fas fa-sign-in-alt"></i> Đăng nhập
                    </button>

                    <div class="auth-divider">
                        <span>hoặc</span>
                    </div>

                    <div class="auth-foot">
                        <span>Chưa có tài khoản?</span>
                        <a class="link strong" href="register.php">Đăng ký ngay</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const pw = document.getElementById('password');
    const btn = document.getElementById('togglePw');
    if (!pw || !btn) return;

    btn.addEventListener('click', () => {
        const isHidden = pw.type === 'password';
        pw.type = isHidden ? 'text' : 'password';
        btn.innerHTML = isHidden ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });
})();
</script>


<?php include 'includes/footer.php'; ?>