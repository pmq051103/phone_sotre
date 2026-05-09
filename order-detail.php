<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (!isLoggedIn()) redirect("../login.php");

$orderId = (int)($_GET["id"] ?? 0);
$userId  = (int)($_SESSION["user_id"] ?? 0);

// ===== ORDER =====
$stmt = $conn->prepare("
    SELECT o.*, p.method, p.status as payment_status
    FROM orders o
    LEFT JOIN payments p ON o.id = p.order_id
    WHERE o.id = ? AND o.user_id = ?
");
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();

$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    setFlash("danger", "Đơn hàng không tồn tại");
    redirect("orders.php");
}

// ===== ITEMS =====
$stmt = $conn->prepare("
    SELECT
        oi.*,
        p.name,
        p.thumbnail,
        (SELECT r.id
         FROM reviews r
         WHERE r.order_id = ? AND r.product_id = oi.product_id
         LIMIT 1) AS reviewed_id
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");

// bind 2 lần orderId (vì dùng 2 chỗ)
$stmt->bind_param("ii", $orderId, $orderId);
$stmt->execute();

$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

// ===== COUNT REVIEW =====
$needReviewCount = 0;

if (($order["status"] ?? "") === "completed") {
    foreach ($items as $it) {
        if (empty($it["reviewed_id"])) {
            $needReviewCount++;
        }
    }
}

$pageTitle = "Chi tiết đơn hàng #" . $orderId . " - " . SITE_NAME;

include "includes/header.php";
?>

<div class="container">
    <div class="od-head">
        <h1 class="od-title">
            <i class="fas fa-file-invoice"></i> Chi tiết đơn hàng #<?= (int)$orderId ?>
        </h1>

        <a href="orders.php" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Quay lại
        </a>
    </div>

    <div class="od-layout">
        <!-- LEFT: Items -->
        <section class="card od-card">
            <div class="od-card-head">
                <h2>Sản phẩm</h2>
                <span class="muted"><?= count($items) ?> món</span>
            </div>

            <div class="od-card-body">

                <!-- anchor để orders.php link sang -->
                <a id="review"></a>

                <?php if (($order["status"] ?? "") === "completed" && $needReviewCount > 0): ?>
                <div class="alert alert-info" style="margin: 0 0 12px 0;">
                    <i class="fas fa-star"></i>
                    Bạn còn <strong><?= (int)$needReviewCount ?></strong> sản phẩm chưa đánh giá trong đơn này.
                </div>
                <?php endif; ?>

                <div class="od-items">
                    <?php foreach ($items as $item): ?>
                    <?php
                            $pid = (int)$item["product_id"];
                            $isReviewed = !empty($item["reviewed_id"]);
                            $isCompleted = (($order["status"] ?? "") === "completed");
                        ?>
                    <div class="od-item">
                        <a class="od-thumb" href="../product-detail.php?id=<?= $pid ?>">
                            <img src="<?= $item["thumbnail"] ? (UPLOAD_URL . e($item["thumbnail"])) : (BASE_URL . '/assets/images/no-image.png') ?>"
                                alt="<?= e($item["name"]) ?>">
                        </a>

                        <div class="od-info">
                            <a class="od-name" href="../product-detail.php?id=<?= $pid ?>">
                                <?= e($item["name"]) ?>
                            </a>

                            <div class="od-meta">
                                <span class="muted">Đơn giá:</span>
                                <strong><?= formatPrice($item["price"]) ?></strong>
                                <span class="dot">•</span>
                                <span class="muted">SL:</span>
                                <strong><?= (int)$item["quantity"] ?></strong>
                            </div>

                            <!-- Actions: đánh giá -->
                            <div class="od-actions"
                                style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                <?php if (!$isCompleted): ?>
                                <span class="badge badge-gray">Chỉ đánh giá khi hoàn tất</span>
                                <?php else: ?>
                                <?php if (!$isReviewed): ?>
                                <a class="btn btn-outline btn-sm"
                                    href="../product-detail.php?id=<?= $pid ?>&order_id=<?= (int)$orderId ?>#tab-reviews">
                                    <i class="fas fa-star"></i> Đánh giá
                                </a>
                                <?php else: ?>
                                <span class="badge badge-success">
                                    <i class="fas fa-check-circle"></i> Đã đánh giá
                                </span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="od-line-total">
                            <?= formatPrice($item["price"] * $item["quantity"]) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($items)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Đơn hàng này chưa có sản phẩm.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- RIGHT: Summary -->
        <aside class="od-right">
            <!-- Order info -->
            <div class="card od-card od-sticky">
                <div class="od-card-head">
                    <h2>Thông tin đơn hàng</h2>
                </div>

                <div class="od-card-body">
                    <div class="od-kv">
                        <span class="muted">Trạng thái</span>
                        <span class="status-pill status-<?= e($order["status"]) ?>">
                            <?= e(function_exists('getOrderStatusText') ? getOrderStatusText($order["status"]) : $order["status"]) ?>
                        </span>
                    </div>

                    <div class="od-kv">
                        <span class="muted">Ngày đặt</span>
                        <strong><?= formatDate($order["created_at"]) ?></strong>
                    </div>

                    <div class="od-kv">
                        <span class="muted">Thanh toán</span>
                        <div class="od-pay">
                            <strong><?= e(function_exists('getPaymentMethodText') ? getPaymentMethodText($order["method"]) : ($order["method"] ?? '—')) ?></strong>
                            <span class="pay-pill pay-<?= e($order["payment_status"] ?? "unpaid") ?>">
                                <?= ($order["payment_status"] ?? "unpaid") === "paid" ? "Đã thanh toán" : "Chưa thanh toán" ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Receiver -->
            <div class="card od-card">
                <div class="od-card-head">
                    <h2>Thông tin nhận hàng</h2>
                </div>

                <div class="od-card-body">
                    <div class="od-ship">
                        <div class="od-ship-row">
                            <i class="fas fa-user"></i>
                            <div>
                                <strong><?= e($order["receiver_name"]) ?></strong>
                                <div class="muted"><?= e($order["receiver_phone"]) ?></div>
                            </div>
                        </div>

                        <div class="od-ship-row">
                            <i class="fas fa-location-dot"></i>
                            <div><?= e($order["receiver_address"]) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total -->
            <div class="card od-card">
                <div class="od-card-head">
                    <h2>Tổng cộng</h2>
                </div>

                <div class="od-card-body">
                    <div class="sum-row">
                        <span class="muted">Tạm tính</span>
                        <strong><?= formatPrice($order["total_amount"]) ?></strong>
                    </div>

                    <div class="sum-row">
                        <span class="muted">Giảm giá</span>
                        <strong class="sum-discount">-<?= formatPrice($order["discount_amount"]) ?></strong>
                    </div>

                    <div class="sum-divider"></div>

                    <div class="sum-row sum-total">
                        <span>Tổng</span>
                        <strong class="sum-price"><?= formatPrice($order["final_amount"]) ?></strong>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <div class="od-foot">
        <a href="orders.php" class="btn btn-outline btn-lg">
            <i class="fas fa-arrow-left"></i> Quay lại danh sách đơn
        </a>
    </div>
</div>

<?php include "includes/footer.php"; ?>