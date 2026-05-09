<?php
require_once __DIR__ . '/../includes/auth.php';

requireRole(['admin', 'staff']);
$isAdmin = isAdmin();
$isStaff = isStaff();

// Chỉ chấp nhận phương thức POST để bảo mật
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/products/list.php');
}

$id = (int)($_POST['id'] ?? 0);
$token = $_POST['csrf_token'] ?? '';

// Kiểm tra CSRF Token
if (!verifyToken($token)) {
    setFlash('danger', 'CSRF token không hợp lệ.');
    redirect(BASE_URL . '/admin/products/list.php');
}

if ($id <= 0) {
    setFlash('danger', 'ID sản phẩm không hợp lệ.');
    redirect(BASE_URL . '/admin/products/list.php');
}

/** 1. Kiểm tra sản phẩm tồn tại (MySQLi) */
$stmt = $conn->prepare("SELECT id, thumbnail FROM products WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    setFlash('warning', 'Sản phẩm không tồn tại hoặc đã bị xóa.');
    redirect(BASE_URL . '/admin/products/list.php');
}

// ================= STAFF: gửi yêu cầu xóa =================
if ($isStaff) {
    try {
        $approvalId = createApproval($conn, [
            'actor_id' => (int)(getCurrentUser()['id'] ?? 0),
            'action' => 'delete',
            'entity' => 'products',
            'entity_id' => $id,
            'payload' => [
                'id' => $id,
                'thumbnail' => $product['thumbnail'] ?? null,
            ],
        ]);

        setFlash('success', "Đã gửi yêu cầu xóa sản phẩm (#$approvalId). Chờ admin phê duyệt.");
        redirect(BASE_URL . '/admin/products/list.php');
        exit;
    } catch (Exception $e) {
        setFlash('danger', 'Có lỗi khi gửi yêu cầu phê duyệt.');
        redirect(BASE_URL . '/admin/products/list.php');
        exit;
    }
}

// ================= ADMIN: Thực hiện xóa thật (MySQLi) =================
try {
    // Bắt đầu giao dịch
    $conn->begin_transaction();

    // A. Lấy danh sách ảnh gallery để xóa file vật lý sau này
    $stGallery = $conn->prepare("SELECT image_url FROM product_images WHERE product_id = ?");
    $stGallery->bind_param("i", $id);
    $stGallery->execute();
    $galleryImgs = $stGallery->get_result()->fetch_all(MYSQLI_ASSOC);

    // B. Xóa sản phẩm khỏi database
    $stmtDel = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmtDel->bind_param("i", $id);
    $stmtDel->execute();

    // Xác nhận giao dịch
    $conn->commit();

    /** 2. Xóa file vật lý sau khi DB commit thành công */
    // Xóa ảnh đại diện (thumbnail)
    if (!empty($product['thumbnail'])) {
        deleteImage($product['thumbnail']);
    }

    // Xóa các ảnh trong gallery
    foreach ($galleryImgs as $img) {
        if (!empty($img['image_url'])) {
            deleteImage($img['image_url']);
        }
    }

    setFlash('success', 'Đã xóa sản phẩm thành công.');
    redirect(BASE_URL . '/admin/products/list.php');
    exit;

} catch (Exception $e) {
    // Rollback nếu có lỗi xảy ra
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    setFlash('danger', 'Có lỗi xảy ra khi xóa sản phẩm: ' . $e->getMessage());
    redirect(BASE_URL . '/admin/products/list.php');
    exit;
}