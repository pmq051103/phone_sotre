<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    setFlash('warning', 'Vui lòng đăng nhập để sử dụng giỏ hàng');
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$action = $_POST['action'] ?? '';
$csrf_token = $_POST['csrf_token'] ?? '';

if (!verifyToken($csrf_token)) {
    setFlash('danger', 'Token không hợp lệ');
    redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
}

$cartId = getOrCreateCart($_SESSION['user_id']);

try {
    switch ($action) {

        case 'add':
            $productId = (int)$_POST['product_id'];
            $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

            // Kiểm tra sản phẩm
            $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND status = 1");
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();

            if (!$product) {
                setFlash('danger', 'Sản phẩm không tồn tại');
                break;
            }

            if ($product['quantity'] < $quantity) {
                setFlash('warning', 'Số lượng sản phẩm không đủ');
                break;
            }

            // Kiểm tra đã có trong giỏ chưa
            $stmt = $conn->prepare("SELECT * FROM cart_items WHERE cart_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $cartId, $productId);
            $stmt->execute();
            $cartItem = $stmt->get_result()->fetch_assoc();

            if ($cartItem) {
                $newQuantity = $cartItem['quantity'] + $quantity;

                if ($newQuantity > $product['quantity']) {
                    setFlash('warning', 'Vượt quá số lượng tồn kho');
                    break;
                }

                $stmt = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
                $stmt->bind_param("ii", $newQuantity, $cartItem['id']);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare("INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $cartId, $productId, $quantity);
                $stmt->execute();
            }

            setFlash('success', 'Đã thêm sản phẩm vào giỏ hàng');
            break;


        case 'update':
            $itemId = (int)$_POST['item_id'];
            $quantity = (int)$_POST['quantity'];

            if ($quantity <= 0) {
                setFlash('danger', 'Số lượng không hợp lệ');
                break;
            }

            // Kiểm tra tồn kho
            $stmt = $conn->prepare("
                SELECT p.quantity, ci.product_id
                FROM cart_items ci
                JOIN products p ON ci.product_id = p.id
                WHERE ci.id = ? AND ci.cart_id = ?
            ");
            $stmt->bind_param("ii", $itemId, $cartId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();

            if (!$item) {
                setFlash('danger', 'Sản phẩm không tồn tại trong giỏ hàng');
                break;
            }

            if ($quantity > $item['quantity']) {
                setFlash('warning', 'Số lượng vượt quá tồn kho');
                break;
            }

            $stmt = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
            $stmt->bind_param("ii", $quantity, $itemId);
            $stmt->execute();

            setFlash('success', 'Đã cập nhật giỏ hàng');
            break;


        case 'remove':
            $itemId = (int)$_POST['item_id'];

            $stmt = $conn->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?");
            $stmt->bind_param("ii", $itemId, $cartId);
            $stmt->execute();

            setFlash('success', 'Đã xóa sản phẩm khỏi giỏ hàng');
            break;


        default:
            setFlash('danger', 'Hành động không hợp lệ');
    }

} catch (Exception $e) {
    setFlash('danger', 'Có lỗi xảy ra, vui lòng thử lại');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'cart.php');
?>