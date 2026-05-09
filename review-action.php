<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyToken($csrf)) {
    setFlash('danger', 'Token không hợp lệ. Vui lòng thử lại.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
}

if (!isLoggedIn()) {
    setFlash('warning', 'Vui lòng đăng nhập để đánh giá.');
    redirect('login.php');
}

$userId    = (int)($_SESSION['user_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$orderId   = (int)($_POST['order_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$comment   = trim($_POST['comment'] ?? '');

if ($productId <= 0) {
    setFlash('danger', 'Sản phẩm không hợp lệ.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
}

if ($orderId <= 0) {
    setFlash('warning', 'Bạn cần vào đúng đơn hàng đã mua để đánh giá.');
    redirect("product-detail.php?id={$productId}#tab-reviews");
}

if ($rating < 1 || $rating > 5) {
    setFlash('danger', 'Vui lòng chọn số sao từ 1 đến 5.');
    redirect("product-detail.php?id={$productId}&order_id={$orderId}#tab-reviews");
}

/**
 * Check: order hợp lệ
 */
$st = $conn->prepare("
    SELECT 1
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.id = ?
      AND o.user_id = ?
      AND o.status = 'completed'
      AND oi.product_id = ?
    LIMIT 1
");

$st->bind_param("iii", $orderId, $userId, $productId);
$st->execute();

$result = $st->get_result();
$valid = $result->num_rows > 0;

if (!$valid) {
    setFlash('warning', 'Chỉ được đánh giá sản phẩm trong đơn đã hoàn tất của bạn.');
    redirect("product-detail.php?id={$productId}&order_id={$orderId}#tab-reviews");
}

/**
 * Check: đã review chưa
 */
$st = $conn->prepare("SELECT id FROM reviews WHERE order_id=? AND product_id=? LIMIT 1");
$st->bind_param("ii", $orderId, $productId);
$st->execute();

$result = $st->get_result();

if ($result->num_rows > 0) {
    setFlash('info', 'Bạn đã đánh giá sản phẩm này cho đơn hàng này rồi.');
    redirect("product-detail.php?id={$productId}&order_id={$orderId}#tab-reviews");
}

$status = 1;

/**
 * Insert review
 */
$ins = $conn->prepare("
    INSERT INTO reviews (user_id, order_id, product_id, rating, comment, status)
    VALUES (?, ?, ?, ?, ?, ?)
");

$commentVal = ($comment !== '' ? $comment : null);

$ins->bind_param(
    "iiiisi",
    $userId,
    $orderId,
    $productId,
    $rating,
    $commentVal,
    $status
);

$ins->execute();

setFlash(
    'success',
    $status === 1
        ? 'Cảm ơn bạn! Đánh giá đã được đăng.'
        : 'Cảm ơn bạn! Đánh giá đang chờ duyệt.'
);

redirect("product-detail.php?id={$productId}&order_id={$orderId}#tab-reviews");
?>