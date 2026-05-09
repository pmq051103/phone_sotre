<?php
$pageTitle = 'Chi tiết đơn hàng - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$errors = [];

function fmtMoney($n){ return number_format((float)$n, 0, '.', ',') . 'đ'; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID đơn không hợp lệ.');
    redirect(BASE_URL . '/admin/orders/list.php');
}

// maps từ includes/functions.php
$orderStatusMap = orderStatusMap();
$allowedStatus  = array_keys($orderStatusMap);

/** handle POST: update status / set paid */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1) Đổi trạng thái đơn (MySQLi)
        if ($action === 'set_status') {
            $newStatus = $_POST['status'] ?? '';
            if (!in_array($newStatus, $allowedStatus, true)) {
                $errors[] = 'Trạng thái không hợp lệ.';
            }

            if (!$errors) {
                try {
                    $up = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
                    $up->bind_param("si", $newStatus, $id);
                    $up->execute();
                    setFlash('success', 'Đã cập nhật trạng thái đơn.');
                    redirect(BASE_URL . '/admin/orders/show.php?id=' . $id);
                    exit;
                } catch (Exception $e) {
                    $errors[] = 'Có lỗi khi cập nhật trạng thái.';
                }
            }
        }

        // 2) Đánh dấu đã thanh toán (MySQLi Transaction)
        elseif ($action === 'set_paid') {
            try {
                $pm = trim($_POST['method'] ?? 'cod'); 
                if (!in_array($pm, ['cod','banking'], true)) $pm = 'cod';

                $tx = trim($_POST['transaction_code'] ?? '');
                $paidAt = date('Y-m-d H:i:s');

                // Bắt đầu Transaction trong MySQLi
                $conn->begin_transaction();

                // Check order tồn tại
                $chk = $conn->prepare("SELECT id FROM orders WHERE id=?");
                $chk->bind_param("i", $id);
                $chk->execute();
                if (!$chk->get_result()->fetch_assoc()) {
                    throw new Exception('Đơn hàng không tồn tại.');
                }

                // Kiểm tra xem đã có bản ghi thanh toán chưa
                $exists = $conn->prepare("SELECT id FROM payments WHERE order_id=? LIMIT 1");
                $exists->bind_param("i", $id);
                $exists->execute();
                $paymentRes = $exists->get_result()->fetch_assoc();

                if ($paymentRes) {
                    // Update
                    $txValue = ($tx !== '' ? $tx : null);
                    $up = $conn->prepare("
                        UPDATE payments 
                        SET method=?, status='paid', transaction_code=?, paid_at=? 
                        WHERE order_id=?
                    ");
                    $up->bind_param("sssi", $pm, $txValue, $paidAt, $id);
                    $up->execute();
                } else {
                    // Insert
                    $txValue = ($tx !== '' ? $tx : null);
                    $ins = $conn->prepare("
                        INSERT INTO payments (order_id, method, status, transaction_code, paid_at) 
                        VALUES (?, ?, 'paid', ?, ?)
                    ");
                    $ins->bind_param("isss", $id, $pm, $txValue, $paidAt);
                    $ins->execute();
                }

                $conn->commit();
                setFlash('success', 'Đã cập nhật: Đã thanh toán.');
                redirect(BASE_URL . '/admin/orders/show.php?id=' . $id);
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Không thể cập nhật thanh toán: ' . $e->getMessage();
            }
        }
        else {
            $errors[] = 'Action không hợp lệ.';
        }
    }
}

/** Lấy thông tin: order + user + payment + discount (MySQLi) */
$sqlOrder = "
    SELECT 
        o.*, 
        u.full_name, u.email, u.phone AS user_phone,
        dc.code AS discount_code,
        p.method AS pay_method, p.status AS pay_status, p.transaction_code, p.paid_at
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN discount_codes dc ON dc.id = o.discount_code_id
    LEFT JOIN payments p ON p.order_id = o.id
    WHERE o.id = ?
";
$st = $conn->prepare($sqlOrder);
$st->bind_param("i", $id);
$st->execute();
$order = $st->get_result()->fetch_assoc();

if (!$order) {
    setFlash('danger', 'Đơn hàng không tồn tại.');
    redirect(BASE_URL . '/admin/orders/list.php');
}

/** Lấy danh sách sản phẩm trong đơn (MySQLi) */
$sqlItems = "
    SELECT oi.*, pr.name, pr.thumbnail
    FROM order_items oi
    JOIN products pr ON pr.id = oi.product_id
    WHERE oi.order_id = ?
";
$stI = $conn->prepare($sqlItems);
$stI->bind_param("i", $id);
$stI->execute();
$items = $stI->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Chi tiết đơn #<?= (int)$order['id'] ?></div>
            <div class="admin-sub">
                <?= e($order['full_name']) ?> • <?= e($order['email']) ?> •
                <?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?>
            </div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/orders/list.php">← Quay lại</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="margin:12px 0;">
        <div>
            <strong>Lỗi</strong>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <button type="button" class="btn-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <style>
    .o-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .o-card {
        border: 1px solid rgba(0, 0, 0, .06);
        border-radius: 12px;
        padding: 12px;
        background: rgba(255, 255, 255, .6);
    }

    .o-label {
        font-weight: 800;
        opacity: .75;
        font-size: 13px;
    }

    .o-value {
        margin-top: 6px;
        font-weight: 900;
    }

    @media(max-width:1024px) {
        .o-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media(max-width:640px) {
        .o-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <section class="admin-panel">
        <div class="admin-panel__head">
            <strong>Thông tin chung</strong>
            <span><?= orderStatusBadge($order['status']) ?></span>
        </div>

        <div class="admin-panel__body">
            <div class="o-grid">
                <!-- Trạng thái đơn -->
                <div class="o-card">
                    <div class="o-label">Trạng thái đơn</div>
                    <div style="margin-top:8px;">
                        <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                            <input type="hidden" name="action" value="set_status">
                            <select class="input" name="status" style="height:38px; min-width:190px;">
                                <?php foreach ($orderStatusMap as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $order['status']===$key?'selected':'' ?>>
                                    <?= e($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">
                                <i class="fa-solid fa-save"></i> Lưu
                            </button>
                        </form>
                    </div>
                </div>

                <div class="o-card">
                    <div class="o-label">Tổng trước giảm</div>
                    <div class="o-value"><?= fmtMoney($order['total_amount']) ?></div>
                </div>

                <div class="o-card">
                    <div class="o-label">Tổng sau giảm</div>
                    <div class="o-value"><?= fmtMoney($order['final_amount']) ?></div>
                </div>

                <div class="o-card">
                    <div class="o-label">Mã giảm giá</div>
                    <div class="o-value">
                        <?= $order['discount_code'] ? e($order['discount_code']) : '—' ?>
                        <?php if ((float)$order['discount_amount'] > 0): ?>
                        <div style="opacity:.8; font-weight:800; margin-top:6px;">
                            Giảm: <?= fmtMoney($order['discount_amount']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Thanh toán (VI) + nút set paid -->
                <div class="o-card">
                    <div class="o-label">Thanh toán</div>

                    <div class="o-value" style="font-weight:800;">
                        <?= e(paymentMethodLabel($order['pay_method'] ?? null)) ?> •
                        <?= e(paymentStatusLabel($order['pay_status'] ?? null)) ?>
                    </div>

                    <?php if (!empty($order['transaction_code'])): ?>
                    <div style="opacity:.75; font-size:12px; margin-top:6px;">TX: <?= e($order['transaction_code']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($order['paid_at'])): ?>
                    <div style="opacity:.75; font-size:12px;">Thanh toán lúc:
                        <?= e(date('d/m/Y H:i', strtotime($order['paid_at']))) ?></div>
                    <?php endif; ?>

                    <?php if (($order['pay_status'] ?? '') !== 'paid'): ?>
                    <form method="POST"
                        style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                        <input type="hidden" name="action" value="set_paid">

                        <select class="input" name="method" style="height:38px; min-width:160px;">
                            <option value="cod">COD</option>
                            <option value="banking">Chuyển khoản</option>
                        </select>

                        <input class="input" name="transaction_code" placeholder="Mã giao dịch (tuỳ chọn)"
                            style="min-width:220px;">

                        <button class="btn btn-primary btn-sm" type="submit">
                            <i class="fa-solid fa-check"></i> Đánh dấu đã thanh toán
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="o-card">
                    <div class="o-label">Khách đặt</div>
                    <div class="o-value">
                        <?= e($order['full_name']) ?>
                        <div style="opacity:.75; font-size:12px; margin-top:6px;"><?= e($order['email']) ?></div>
                        <div style="opacity:.75; font-size:12px;"><?= e($order['user_phone'] ?? '') ?></div>
                    </div>
                </div>
            </div>

            <div class="o-grid" style="margin-top:12px;">
                <div class="o-card" style="grid-column: span 3;">
                    <div class="o-label">Thông tin nhận hàng</div>
                    <div class="o-value" style="font-weight:800;">
                        <?= e($order['receiver_name']) ?> • <?= e($order['receiver_phone']) ?>
                    </div>
                    <div style="margin-top:6px; opacity:.85;">
                        <?= nl2br(e($order['receiver_address'])) ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-panel" style="margin-top:12px;">
        <div class="admin-panel__head"><strong>Sản phẩm trong đơn</strong></div>
        <div class="admin-panel__body">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th style="width:120px;">Giá</th>
                            <th style="width:120px;">Số lượng</th>
                            <th style="width:160px;">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$items): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:18px; color:var(--text-secondary);">
                                Không có sản phẩm
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <!-- Cột sản phẩm -->
                            <td>
                                <div style="display:flex; gap:12px; align-items:center;">

                                    <!-- Thumbnail -->
                                    <div style="width:56px; height:56px; flex-shrink:0;">
                                        <?php if (!empty($it['thumbnail'])): ?>
                                        <img src="<?= BASE_URL . '/uploads/' . e($it['thumbnail']) ?>"
                                            alt="<?= e($it['name']) ?>"
                                            style="width:56px; height:56px; object-fit:cover; border-radius:8px; border:1px solid rgba(0,0,0,.08);">
                                        <?php endif; ?>
                                    </div>

                                    <!-- Name + link -->
                                    <div>
                                        <a href="<?= BASE_URL ?>/admin/products/show.php?id=<?= (int)$it['product_id'] ?>"
                                            style="font-weight:900; color:inherit; text-decoration:none;">
                                            <?= e($it['name']) ?>
                                        </a>

                                        <div style="opacity:.6; font-size:12px; margin-top:4px;">
                                            ID: #<?= (int)$it['product_id'] ?>
                                        </div>
                                    </div>

                                </div>
                            </td>

                            <td><?= fmtMoney($it['price']) ?></td>
                            <td><?= (int)$it['quantity'] ?></td>
                            <td style="font-weight:900;">
                                <?= fmtMoney((float)$it['price'] * (int)$it['quantity']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

            </div>
        </div>
    </section>
</main>