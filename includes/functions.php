<?php
// Format tiền VND
function formatPrice($price) {
    return number_format($price, 0, ',', '.') . ' ₫';
}

// Kiểm tra đăng nhập
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Kiểm tra admin
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function isStaff(): bool {
  return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'staff';
}

function hasRole($roles): bool {
  if (!isLoggedIn()) return false;
  $u = getCurrentUser();
  $role = $u['role'] ?? 'user';
  $roles = is_array($roles) ? $roles : [$roles];
  return in_array($role, $roles, true);
}

function canAccessAdmin(): bool {
  if (!isLoggedIn()) return false;
  $u = getCurrentUser();
  return in_array(($u['role'] ?? 'user'), ['admin','staff'], true);
}

function requireRole($roles, string $message = 'Bạn không có quyền'): void {
  if (!hasRole($roles)) {
    setFlash('danger', $message);
    redirect(BASE_URL . '/index.php');
    exit;
  }
}

// =======================
// DATABASE (MySQLi)
// =======================

// Lấy thông tin user hiện tại
function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;

    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Tạo approval
function createApproval($conn, array $data): int {
    $stmt = $conn->prepare("
        INSERT INTO approvals(actor_id, action, entity, entity_id, payload, status, admin_note, created_at)
        VALUES(?, ?, ?, ?, ?, 'pending', NULL, NOW())
    ");

    $payload = json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE);
    $entityId = $data['entity_id'] ?? null;

    $stmt->bind_param(
        "issis",
        $data['actor_id'],
        $data['action'],
        $data['entity'],
        $entityId,
        $payload
    );

    $stmt->execute();
    return $conn->insert_id;
}

// =======================
// Redirect + Flash
// =======================

function redirect($url) {
    if (strpos($url, 'http') !== 0) {
        $url = BASE_URL . '/' . ltrim($url, '/');
    }
    header("Location: $url");
    exit;
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (empty($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// =======================
// CSRF
// =======================

function generateToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyToken($token): bool {
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Escape
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// =======================
// Upload
// =======================

function uploadImage($file, $folder = 'products') {
    $targetDir = UPLOAD_DIR . $folder . '/';

    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($fileExtension, $allowedExtensions)) {
        return ['success' => false, 'message' => 'Chỉ chấp nhận file ảnh'];
    }

    if ($file['size'] > 5242880) {
        return ['success' => false, 'message' => 'File > 5MB'];
    }

    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
    $targetFile = $targetDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'filename' => $folder . '/' . $fileName];
    }

    return ['success' => false, 'message' => 'Upload lỗi'];
}

function deleteImage($filename) {
    $filePath = UPLOAD_DIR . $filename;
    if (file_exists($filePath)) unlink($filePath);
}

// =======================
// Cart
// =======================

function getCartCount() {
    global $conn;
    if (!isLoggedIn()) return 0;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(ci.quantity), 0) as total
        FROM carts c
        LEFT JOIN cart_items ci ON c.id = ci.cart_id
        WHERE c.user_id = ?
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getOrCreateCart($userId) {
    global $conn;

    $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $cart = $stmt->get_result()->fetch_assoc();

    if ($cart) return $cart['id'];

    $stmt = $conn->prepare("INSERT INTO carts (user_id) VALUES (?)");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $conn->insert_id;
}

// =======================
// Order / Payment
// =======================

function orderStatusMap(): array {
  return [
    'pending'   => 'Chờ xác nhận',
    'confirmed' => 'Đã xác nhận',
    'shipping'  => 'Đang giao',
    'completed' => 'Hoàn thành',
    'cancelled' => 'Đã hủy',
  ];
}

function paymentStatusMap(): array {
  return [
    'unpaid' => 'Chưa thanh toán',
    'paid'   => 'Đã thanh toán',
    'failed' => 'Thất bại',
  ];
}

function paymentMethodMap(): array {
  return [
    'cod'     => 'COD',
    'banking' => 'Chuyển khoản',
  ];
}

function orderStatusLabel(string $key): string {
  return orderStatusMap()[$key] ?? $key;
}

function paymentStatusLabel(?string $key): string {
  if (!$key) return '—';
  return paymentStatusMap()[$key] ?? $key;
}

function paymentMethodLabel(?string $key): string {
  if (!$key) return '—';
  return paymentMethodMap()[$key] ?? $key;
}

function orderStatusBadge(string $key): string {
  $label = orderStatusLabel($key);
  $cls = 'badge badge-gray';

  if ($key === 'confirmed') $cls = 'badge badge-info';
  elseif ($key === 'shipping') $cls = 'badge badge-warning';
  elseif ($key === 'completed') $cls = 'badge badge-success';

  return '<span class="'.$cls.'">'.htmlspecialchars($label).'</span>';
}

function formatDate($date, $format = 'd/m/Y') {
    if (!$date) return '';
    return date($format, strtotime($date));
}