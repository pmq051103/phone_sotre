<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$pageTitle = 'Đăng ký - ' . SITE_NAME;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate
    if (empty($full_name)) {
        $errors[] = 'Vui lòng nhập họ tên';
    }
    
    if (empty($email)) {
        $errors[] = 'Vui lòng nhập email';
    } elseif (!isValidEmail($email)) {
        $errors[] = 'Email không hợp lệ';
    }
    
    if (empty($password)) {
        $errors[] = 'Vui lòng nhập mật khẩu';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Mật khẩu xác nhận không khớp';
    }
    
    if (!verifyToken($csrf_token)) {
        $errors[] = 'Token không hợp lệ';
    }
    
    // ========================
    // Check email tồn tại
    // ========================
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = 'Email đã được sử dụng';
        }
    }
    
    // ========================
    // Insert user
    // ========================
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("
            INSERT INTO users (full_name, email, password, phone, address, role, status) 
            VALUES (?, ?, ?, ?, ?, 'user', 1)
        ");

        $stmt->bind_param(
            "sssss",
            $full_name,
            $email,
            $hashedPassword,
            $phone,
            $address
        );

        if ($stmt->execute()) {
            setFlash('success', 'Đăng ký thành công! Vui lòng đăng nhập.');
            redirect('login.php');
        } else {
            $errors[] = 'Có lỗi xảy ra, vui lòng thử lại';
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
                    <i class="fas fa-user-plus"></i>
                </div>
                <h1 class="auth-title">Đăng ký tài khoản</h1>
                <p class="auth-subtitle">Tạo tài khoản để mua sắm nhanh hơn</p>
            </div>

            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <div style="display:flex; gap:10px; align-items:flex-start;">
                            <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
                            <div>
                                <strong>Đăng ký thất bại</strong>
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
                        <span class="label">Họ và tên <span class="req">*</span></span>
                        <input
                            type="text"
                            name="full_name"
                            class="input"
                            value="<?= e($_POST['full_name'] ?? '') ?>"
                            placeholder="vd: Nguyễn Văn A"
                            required
                        >
                    </label>

                    <label class="field">
                        <span class="label">Email <span class="req">*</span></span>
                        <input
                            type="email"
                            name="email"
                            class="input"
                            value="<?= e($_POST['email'] ?? '') ?>"
                            placeholder="vd: you@email.com"
                            required
                        >
                    </label>

                    <div class="two-col">
                        <label class="field">
                            <span class="label">Số điện thoại</span>
                            <input
                                type="text"
                                name="phone"
                                class="input"
                                value="<?= e($_POST['phone'] ?? '') ?>"
                                placeholder="vd: 09xxxxxxxx"
                            >
                        </label>

                        <label class="field">
                            <span class="label">Địa chỉ</span>
                            <input
                                type="text"
                                name="address"
                                class="input"
                                value="<?= e($_POST['address'] ?? '') ?>"
                                placeholder="vd: Quận 1, TP.HCM"
                            >
                        </label>
                    </div>

                    <label class="field">
                        <span class="label">Mật khẩu <span class="req">*</span></span>
                        <div class="password-wrap">
                            <input type="password" name="password" class="input" id="pw" placeholder="Tối thiểu 6 ký tự" required>
                            <button type="button" class="pw-toggle" id="togglePw" aria-label="Hiện/ẩn mật khẩu">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="hint">Tối thiểu 6 ký tự</span>
                    </label>

                    <label class="field">
                        <span class="label">Xác nhận mật khẩu <span class="req">*</span></span>
                        <div class="password-wrap">
                            <input type="password" name="confirm_password" class="input" id="pw2" required>
                            <button type="button" class="pw-toggle" id="togglePw2" aria-label="Hiện/ẩn mật khẩu">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </label>

                    <button type="submit" class="btn btn-primary btn-lg auth-btn">
                        <i class="fas fa-user-plus"></i> Đăng ký
                    </button>

                    <div class="auth-foot">
                        <span>Đã có tài khoản?</span>
                        <a class="link strong" href="login.php">Đăng nhập ngay</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
  function setupToggle(inputId, btnId){
    const pw = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if (!pw || !btn) return;

    btn.addEventListener('click', () => {
      const isHidden = pw.type === 'password';
      pw.type = isHidden ? 'text' : 'password';
      btn.innerHTML = isHidden ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
    });
  }
  setupToggle('pw', 'togglePw');
  setupToggle('pw2', 'togglePw2');
})();

<?php include 'includes/footer.php'; ?>