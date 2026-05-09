<?php
// Cấu hình database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'phone_store');

// Cấu hình đường dẫn
define('BASE_URL', 'http://localhost:3000');
define('SITE_NAME', 'PhoneStore - Cửa hàng điện thoại');

// Cấu hình upload
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');

// Cấu hình PayOS
define('PAYOS_CLIENT_ID', '39ccce3b-e51c-49f3-9391-baec1b3c0848');
define('PAYOS_API_KEY', 'e1c52b92-9872-455e-86f5-186de798a237');
define('PAYOS_CHECKSUM_KEY', '60d5d7d9ceba1ea85206813f69bcc26cdb13ca28c4fdb0463c78ea7c9c08ab29');

// Timezone
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Bật session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kết nối database
try {
    // Bước 1, Bước 2
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    // Check lỗi kết nối
    if ($conn->connect_error) {
        throw new Exception("Kết nối thất bại: " . $conn->connect_error);
    }
    // Bước 3: 
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Lỗi kết nối database: " . $e->getMessage());
}
?>