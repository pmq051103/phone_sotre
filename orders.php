<?php
require_once "includes/config.php";
require_once "includes/functions.php";

if (!isLoggedIn()) redirect("login.php");

$pageTitle = "Đơn hàng của tôi - " . SITE_NAME;

$userId = (int)($_SESSION["user_id"] ?? 0);

// ===== filter =====
$allowedStatuses = ['all','pending','confirmed','shipping','completed','cancelled'];
$filterStatus = $_GET['status'] ?? 'all';

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'all';
}

// ===== SQL =====
$sql = "
    SELECT
        o.id, o.created_at, o.total_amount, o.discount_amount, o.final_amount, o.status,
        dc.code AS discount_code,
        p.method AS payment_method,
        p.status AS payment_status,

        CASE
          WHEN o.status = 'completed' AND EXISTS (
            SELECT 1
            FROM order_items oi
            LEFT JOIN reviews r
              ON r.product_id = oi.product_id AND r.user_id = o.user_id
            WHERE oi.order_id = o.id
              AND r.id IS NULL
          )
          THEN 1 ELSE 0
        END AS need_review

    FROM orders o
    LEFT JOIN discount_codes dc ON o.discount_code_id = dc.id
    LEFT JOIN payments p ON p.order_id = o.id
    WHERE o.user_id = ?
";

$params = [$userId];
$types = "i";

// filter status
if ($filterStatus !== 'all') {
    $sql .= " AND o.status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

$sql .= " ORDER BY o.created_at DESC";

// ===== EXECUTE =====
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC) ?: [];

include "includes/header.php";
?>

<div class="container">
    <div class="section-title" style="margin-top: var(--space-xl);">
        <h2><i class="fas fa-box"></i> Đơn hàng của tôi</h2>
        <p>Theo dõi trạng thái đơn hàng và thanh toán</p>
    </div>

    <!-- Filter -->
    <div class="orders-toolbar">
        <div class="orders-filters">
            <?php
            // helper tạo link filter
            function ordersFilterLink($status, $label, $activeStatus) {
                $isActive = ($status === $activeStatus);
                $url = "orders.php?status=" . urlencode($status);
                $cls = "filter-chip" . ($isActive ? " is-active" : "");
                return '<a class="'. $cls .'" href="'. $url .'">'. $label .'</a>';
            }

            echo ordersFilterLink('all', 'Tất cả', $filterStatus);
            echo ordersFilterLink('pending', 'Chờ xử lý', $filterStatus);
            echo ordersFilterLink('confirmed', 'Đã xác nhận', $filterStatus);
            echo ordersFilterLink('shipping', 'Đang giao', $filterStatus);
            echo ordersFilterLink('completed', 'Hoàn tất', $filterStatus);
            echo ordersFilterLink('cancelled', 'Đã huỷ', $filterStatus);
            ?>
        </div>

        <div class="orders-count">
            <i class="fas fa-receipt"></i>
            <span><?= count($orders) ?> đơn</span>
        </div>
    </div>

    <?php if (empty($orders)): ?>
    <div class="alert alert-info">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-info-circle"></i>
            <span>Bạn chưa có đơn hàng nào.</span>
        </div>
        <a class="btn btn-primary btn-sm" href="products.php">
            <i class="fas fa-shopping-bag"></i> Mua sắm ngay
        </a>
    </div>
    <?php else: ?>

    <div class="card orders-card">
        <div class="orders-card-header">
            <div class="orders-card-title">
                <i class="fas fa-list"></i>
                <span>Danh sách đơn hàng</span>
            </div>
        </div>

        <div class="table-responsive orders-table-wrap">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Mã đơn</th>
                        <th>Ngày đặt</th>
                        <th>Tổng</th>
                        <th>Giảm</th>
                        <th>Thanh toán</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <?php
                        $orderId = (int)$order['id'];

                        // Badge cho order status
                        $orderStatus = $order['status'] ?? 'pending';
                        $orderBadgeClass = function_exists('getOrderStatusClass')
                            ? getOrderStatusClass($orderStatus)
                            : (
                                $orderStatus === 'completed' ? 'success' :
                                ($orderStatus === 'shipping' ? 'warning' :
                                ($orderStatus === 'confirmed' ? 'info' :
                                ($orderStatus === 'cancelled' ? 'danger' : 'info')))
                            );

                        $orderStatusText = function_exists('getOrderStatusText')
                            ? getOrderStatusText($orderStatus)
                            : (
                                $orderStatus === 'completed' ? 'Hoàn tất' :
                                ($orderStatus === 'shipping' ? 'Đang giao' :
                                ($orderStatus === 'confirmed' ? 'Đã xác nhận' :
                                ($orderStatus === 'cancelled' ? 'Đã huỷ' : 'Chờ xử lý')))
                            );

                        // Payment text
                        $pm = $order['payment_method'] ?? null; // cod/banking/null
                        $ps = $order['payment_status'] ?? null; // unpaid/paid/failed/null

                        $paymentMethodText = $pm === 'cod' ? 'COD' : ($pm === 'banking' ? 'Chuyển khoản' : '—');
                        $paymentStatusText = $ps === 'paid' ? 'Đã thanh toán' : ($ps === 'failed' ? 'Thất bại' : ($ps === 'unpaid' ? 'Chưa thanh toán' : '—'));

                        // class cho payment status
                        $paymentBadgeClass =
                            $ps === 'paid' ? 'success' :
                            ($ps === 'failed' ? 'danger' :
                            ($ps === 'unpaid' ? 'warning' : 'info'));

                        $discountCode = $order['discount_code'] ?? '';
                        ?>
                    <tr>
                        <td>
                            <div class="order-id">
                                <strong>#<?= $orderId ?></strong>
                                <?php if ($discountCode): ?>
                                <div class="order-sub">
                                    <i class="fas fa-ticket"></i>
                                    <span><?= e($discountCode) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td><?= formatDate($order["created_at"]) ?></td>

                        <td>
                            <div class="money">
                                <strong><?= formatPrice($order["final_amount"]) ?></strong>
                                <div class="order-sub">
                                    <span class="muted">Trước giảm: <?= formatPrice($order["total_amount"]) ?></span>
                                </div>
                            </div>
                        </td>

                        <td>
                            <?php if ((float)$order["discount_amount"] > 0): ?>
                            <span class="money-discount">-<?= formatPrice($order["discount_amount"]) ?></span>
                            <?php else: ?>
                            <span class="muted">0đ</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="payment-cell">
                                <div class="muted"><?= e($paymentMethodText) ?></div>
                                <span>
                                    <?= e($paymentStatusText) ?>
                                </span>
                            </div>
                        </td>

                        <td>
                            <span class="badge badge-<?= e($orderBadgeClass) ?>">
                                <?= e($orderStatusText) ?>
                            </span>
                        </td>

                        <td class="text-right" style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
                            <a href="order-detail.php?id=<?= $orderId ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> Chi tiết
                            </a>

                            <?php if ((int)($order['need_review'] ?? 0) === 1): ?>
                            <a href="order-detail.php?id=<?= $orderId ?>#review" class="btn btn-outline btn-sm">
                                <i class="fas fa-star"></i> Đánh giá
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include "includes/footer.php"; ?>