<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (!isLoggedIn()) {
    setFlash("warning", "Vui lòng đăng nhập");
    redirect("login.php");
}

$pageTitle = "Thanh toán - " . SITE_NAME;
$userId = (int)$_SESSION["user_id"];
$currentUser = getCurrentUser();
$cartId = getOrCreateCart($userId);

$stmt = $conn->prepare("
    SELECT ci.*, p.name, p.price, p.thumbnail, p.quantity as stock
    FROM cart_items ci
    JOIN products p ON ci.product_id = p.id
    WHERE ci.cart_id = ?
");

$stmt->bind_param("i", $cartId);
$stmt->execute();

$cartItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($cartItems)) {
    setFlash("warning", "Giỏ hàng trống");
    redirect("cart.php");
}

$totalAmount = array_reduce($cartItems, function($sum, $item) {
    return $sum + ($item["price"] * $item["quantity"]);
}, 0);

$errors = [];
$discountAmount = 0;
$discountCodeId = null;
$discount_code = trim($_POST["discount_code"] ?? "");
$paymentMethod = $_POST["payment_method"] ?? "cod";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $isApply = isset($_POST["apply_discount"]);
    $isPlace = isset($_POST["place_order"]);

    if (!verifyToken($_POST["csrf_token"] ?? "")) {
        $errors[] = "Token không hợp lệ";
    }

    if ($isPlace) {
        if (empty(trim($_POST["receiver_name"] ?? ""))) {
            $errors[] = "Vui lòng nhập tên người nhận";
        }

        if (empty(trim($_POST["receiver_phone"] ?? ""))) {
            $errors[] = "Vui lòng nhập số điện thoại";
        }

        if (empty(trim($_POST["receiver_address"] ?? ""))) {
            $errors[] = "Vui lòng nhập địa chỉ";
        }
    }

    if ($discount_code && empty($errors)) {

        $stmtD = $conn->prepare("
            SELECT *
            FROM discount_codes
            WHERE code = ?
            AND status = 1
            LIMIT 1
        ");

        $stmtD->bind_param("s", $discount_code);
        $stmtD->execute();

        $discount = $stmtD->get_result()->fetch_assoc();

        if ($discount && $totalAmount >= (float)$discount["min_order_value"]) {

            $stmtC = $conn->prepare("
                SELECT id
                FROM discount_usages
                WHERE discount_code_id = ?
                AND user_id = ?
                LIMIT 1
            ");

            $stmtC->bind_param("ii", $discount["id"], $userId);
            $stmtC->execute();

            if ($stmtC->get_result()->fetch_assoc()) {

                $errors[] = "Bạn đã sử dụng mã này rồi";

            } else {

                $discountCodeId = (int)$discount["id"];

                $discountAmount = ($discount["discount_type"] === "percent")
                    ? min(
                        $totalAmount * $discount["discount_value"] / 100,
                        $discount["max_discount"] ?? PHP_INT_MAX
                    )
                    : (float)$discount["discount_value"];

                $discountAmount = min($discountAmount, $totalAmount);
            }

        } else {

            $errors[] = "Mã giảm giá không hợp lệ hoặc chưa đủ điều kiện";
        }
    }

    $finalAmount = $totalAmount - $discountAmount;

    if ($isPlace && empty($errors)) {

        mysqli_begin_transaction($conn);

        try {

            foreach ($cartItems as $item) {

                $st = $conn->prepare("
                    SELECT quantity
                    FROM products
                    WHERE id = ?
                    FOR UPDATE
                ");

                $st->bind_param("i", $item['product_id']);
                $st->execute();

                $result = $st->get_result();
                $row = $result->fetch_assoc();

                if ($row['quantity'] < $item['quantity']) {
                    throw new Exception("Sản phẩm " . $item['name'] . " không đủ hàng");
                }
            }

            $stmtO = $conn->prepare("
                INSERT INTO orders (
                    user_id,
                    receiver_name,
                    receiver_phone,
                    receiver_address,
                    total_amount,
                    discount_code_id,
                    discount_amount,
                    final_amount,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmtO->bind_param(
                "isssdddd",
                $userId,
                $_POST['receiver_name'],
                $_POST['receiver_phone'],
                $_POST['receiver_address'],
                $totalAmount,
                $discountCodeId,
                $discountAmount,
                $finalAmount
            );

            $stmtO->execute();

            $orderId = $conn->insert_id;

            foreach ($cartItems as $item) {

                $stmtI = $conn->prepare("
                    INSERT INTO order_items (
                        order_id,
                        product_id,
                        quantity,
                        price
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $stmtI->bind_param(
                    "iiid",
                    $orderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price']
                );

                $stmtI->execute();

                $stmtU = $conn->prepare("
                    UPDATE products
                    SET quantity = quantity - ?
                    WHERE id = ?
                ");

                $stmtU->bind_param(
                    "ii",
                    $item['quantity'],
                    $item['product_id']
                );

                $stmtU->execute();
            }

            $stmtP = $conn->prepare("
                INSERT INTO payments (
                    order_id,
                    method,
                    status
                )
                VALUES (?, ?, 'unpaid')
            ");

            $stmtP->bind_param(
                "is",
                $orderId,
                $paymentMethod
            );

            $stmtP->execute();

            if ($discountCodeId) {

                $stmtDU = $conn->prepare("
                    INSERT INTO discount_usages (
                        discount_code_id,
                        user_id,
                        order_id
                    )
                    VALUES (?, ?, ?)
                ");

                $stmtDU->bind_param(
                    "iii",
                    $discountCodeId,
                    $userId,
                    $orderId
                );

                $stmtDU->execute();

                $stmtDC = $conn->prepare("
                    UPDATE discount_codes
                    SET used_count = used_count + 1
                    WHERE id = ?
                ");

                $stmtDC->bind_param("i", $discountCodeId);
                $stmtDC->execute();
            }

            $stmtDel = $conn->prepare("
                DELETE FROM cart_items
                WHERE cart_id = ?
            ");

            $stmtDel->bind_param("i", $cartId);
            $stmtDel->execute();

            mysqli_commit($conn);

            setFlash("success", "Đặt hàng thành công! Mã đơn: #" . $orderId);

            redirect("order-detail.php?id=" . $orderId);

        } catch (Exception $e) {

            mysqli_rollback($conn);

            $errors[] = $e->getMessage();
        }
    }
}

include "includes/header.php";
?>

<div class="container">

    <div class="checkout-head">
        <h1>Thanh toán</h1>
        <a href="cart.php" class="btn btn-outline btn-sm">
            Quay lại giỏ hàng
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul>
                <?php array_walk($errors, function($err) {
                    echo "<li>" . e($err) . "</li>";
                }); ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" class="checkout-layout">

        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
        <input type="hidden" name="payment_method" value="cod">

        <div class="co-left">

            <div class="card co-card">

                <div class="co-card-head">
                    <h2>Thông tin giao hàng</h2>
                </div>

                <div class="co-card-body">

                    <label class="field">
                        <span>Tên người nhận *</span>

                        <input
                            type="text"
                            name="receiver_name"
                            class="input"
                            value="<?= e($_POST["receiver_name"] ?? $currentUser["full_name"]) ?>"
                            required
                        >
                    </label>

                    <label class="field">
                        <span>Số điện thoại *</span>

                        <input
                            type="text"
                            name="receiver_phone"
                            class="input"
                            value="<?= e($_POST["receiver_phone"] ?? $currentUser["phone"]) ?>"
                            required
                        >
                    </label>

                    <label class="field">
                        <span>Địa chỉ *</span>

                        <textarea
                            name="receiver_address"
                            class="textarea"
                            required
                        ><?= e($_POST["receiver_address"] ?? $currentUser["address"]) ?></textarea>
                    </label>

                </div>
            </div>

            <div class="card co-card">

                <div class="co-card-head">
                    <h2>Phương thức thanh toán</h2>
                </div>

                <div class="co-card-body">

                    <label class="radio">
                        <input type="radio" checked disabled>
                        <span class="radio-text">
                            <strong>COD</strong>
                            (Thanh toán khi nhận hàng)
                        </span>
                    </label>

                </div>
            </div>

        </div>

        <aside class="co-right">

            <div class="card co-card co-sticky">

                <div class="co-card-head">
                    <h2>Đơn hàng (<?= count($cartItems) ?>)</h2>
                </div>

                <div class="co-card-body">

                    <div class="co-items">

                        <?php array_walk($cartItems, function($item) { ?>

                            <div class="co-item">

                                <span>
                                    <?= e($item['name']) ?>
                                    <small>x<?= $item['quantity'] ?></small>
                                </span>

                                <span>
                                    <?= formatPrice($item['price'] * $item['quantity']) ?>
                                </span>

                            </div>

                        <?php }); ?>

                    </div>

                    <div class="co-discount">

                        <div class="co-discount-row">

                            <input
                                type="text"
                                name="discount_code"
                                class="input"
                                value="<?= e($discount_code) ?>"
                                placeholder="Mã giảm giá"
                            >

                            <button
                                type="submit"
                                name="apply_discount"
                                class="btn btn-outline btn-sm"
                            >
                                Dùng
                            </button>

                        </div>
                    </div>

                    <div class="co-summary">

                        <div class="sum-row">
                            <span>Tạm tính</span>
                            <strong><?= formatPrice($totalAmount) ?></strong>
                        </div>

                        <div class="sum-row">
                            <span>Giảm giá</span>

                            <strong class="text-danger">
                                -<?= formatPrice($discountAmount) ?>
                            </strong>
                        </div>

                        <div class="sum-divider"></div>

                        <?php $finalAmount = $totalAmount - $discountAmount; ?>

                        <div class="sum-row sum-total">

                            <span>Tổng</span>

                            <strong class="sum-price">
                                <?= formatPrice($finalAmount) ?>
                            </strong>

                        </div>

                        <button
                            type="submit"
                            name="place_order"
                            value="1"
                            class="btn btn-primary btn-lg w-full mt-20"
                        >
                            ĐẶT HÀNG NGAY
                        </button>

                    </div>

                </div>
            </div>

        </aside>

    </form>

</div>

<?php include "includes/footer.php"; ?>