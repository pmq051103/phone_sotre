<?php
$pageTitle = 'Quản lý đơn hàng - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$errors = [];

/** helpers */
function buildQuery(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ('?' . $qs) : '';
}

function fmtMoney($n){
    return number_format((float)$n, 0, '.', ',') . 'đ';
}

// Maps từ includes/functions.php
$orderStatusMap = orderStatusMap();
$allowedStatus  = array_keys($orderStatusMap);

$paymentStatusMap = paymentStatusMap();
$allowedPayStatus  = array_keys($paymentStatusMap);

$lockedStatuses = ['completed', 'cancelled'];

/** 1. Xử lý POST: Cập nhật trạng thái đơn hàng (MySQLi) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'set_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';

            if ($id <= 0) $errors[] = 'ID đơn không hợp lệ.';
            if (!in_array($newStatus, $allowedStatus, true)) $errors[] = 'Trạng thái không hợp lệ.';

            if (!$errors) {
                try {
                    // Kiểm tra tồn tại
                    $chk = $conn->prepare("SELECT status FROM orders WHERE id=?");
                    $chk->bind_param("i", $id);
                    $chk->execute();
                    $res = $chk->get_result()->fetch_assoc();

                    if (!$res) {
                        $errors[] = 'Đơn hàng không tồn tại.';
                    } else {
                        $up = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
                        $up->bind_param("si", $newStatus, $id);
                        $up->execute();
                        setFlash('success', "Đã cập nhật trạng thái đơn #{$id}.");
                        redirect(BASE_URL . '/admin/orders/list.php' . buildQuery());
                        exit;
                    }
                } catch (Exception $e) {
                    $errors[] = 'Có lỗi khi cập nhật: ' . $e->getMessage();
                }
            }
        }
    }
}

/** 2. Xử lý Bộ lọc (Filters) */
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$payStatus = trim($_GET['pay_status'] ?? '');
$dateFrom = trim($_GET['from'] ?? '');
$dateTo   = trim($_GET['to'] ?? '');

$where = [];
$params = [];
$types = "";

if ($q !== '') {
    if (ctype_digit($q)) {
        $where[] = "o.id = ?";
        $params[] = (int)$q;
        $types .= "i";
    } else {
        $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR o.receiver_name LIKE ? OR o.receiver_phone LIKE ?)";
        $search = "%{$q}%";
        array_push($params, $search, $search, $search, $search);
        $types .= "ssss";
    }
}

if ($status !== '' && in_array($status, $allowedStatus, true)) {
    $where[] = "o.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($payStatus !== '' && in_array($payStatus, $allowedPayStatus, true)) {
    $where[] = "p.status = ?";
    $params[] = $payStatus;
    $types .= "s";
}

if ($dateFrom !== '') {
    $where[] = "DATE(o.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}
if ($dateTo !== '') {
    $where[] = "DATE(o.created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$baseWhere = $where ? (" WHERE " . implode(" AND ", $where)) : "";
$baseFrom = " FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN payments p ON p.order_id = o.id ";

/** 3. Phân trang (Pagination) */
$perPage = 7;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Đếm tổng
$sqlCount = "SELECT COUNT(DISTINCT o.id) $baseFrom $baseWhere";
$stTotal = $conn->prepare($sqlCount);
if ($where) $stTotal->bind_param($types, ...$params);
$stTotal->execute();
$totalRows = $stTotal->get_result()->fetch_row()[0];

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

/** 4. Lấy danh sách (MySQLi) */
$sql = "
    SELECT o.*, u.full_name, u.email, p.method AS pay_method, p.status AS pay_status
    $baseFrom $baseWhere
    GROUP BY o.id
    ORDER BY o.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$listTypes = $types . "ii";
$listParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Quản lý đơn hàng</div>
            <div class="admin-sub">Theo dõi đơn + thanh toán + trạng thái • Tổng: <?= (int)$totalRows ?></div>
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

    <!-- Filters -->
    <section class="admin-panel">
        <div class="admin-panel__head">
            <strong>Bộ lọc</strong>
            <span class="badge badge-info">Tổng: <?= (int)$totalRows ?></span>
        </div>

        <div class="admin-panel__body">
            <form method="GET" class="admin-filters">
                <div class="admin-filter-row">
                    <div class="filter-item">
                        <label>Tìm kiếm</label>
                        <input class="input" name="q" value="<?= e($q) ?>" placeholder="Mã đơn / tên / SĐT / email..."
                            style="width:200px;">
                    </div>

                    <div class="filter-item">
                        <label>Trạng thái đơn</label>
                        <select class="input" name="status" style="width:170px;">
                            <option value="" <?= $status===''?'selected':'' ?>>Tất cả</option>
                            <?php foreach ($orderStatusMap as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $status===$key?'selected':'' ?>>
                                <?= e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Trạng thái thanh toán</label>
                        <select class="input" name="pay_status" style="width:170px;">
                            <option value="" <?= $payStatus===''?'selected':'' ?>>Tất cả</option>
                            <?php foreach ($paymentStatusMap as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $payStatus===$key?'selected':'' ?>>
                                <?= e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Từ ngày</label>
                        <input class="input" type="date" name="from" value="<?= e($dateFrom) ?>" style="width:170px;">
                    </div>

                    <div class="filter-item">
                        <label>Đến ngày</label>
                        <input class="input" type="date" name="to" value="<?= e($dateTo) ?>" style="width:170px;">
                    </div>

                    <div class="filter-actions">
                        <button class="btn btn-outline btn-sm" type="submit">
                            <i class="fa-solid fa-filter"></i> Lọc
                        </button>
                        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/orders/list.php">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- Table -->
    <section class="admin-panel" style="margin-top:12px;">
        <div class="admin-panel__head">
            <strong>Danh sách đơn</strong>
            <span class="badge badge-success">Trang <?= (int)$page ?>/<?= (int)$totalPages ?></span>
        </div>

        <div class="admin-panel__body">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">Mã đơn</th>
                            <th>Khách</th>
                            <th style="width:160px;">SĐT nhận</th>
                            <th style="width:160px;">Tổng thanh toán</th>
                            <th style="width:160px;">Thanh toán</th>
                            <th style="width:160px;">Trạng thái</th>
                            <th style="width:180px;">Ngày tạo</th>
                            <th style="width:300px;">Thao tác</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$rows): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:18px; color:var(--text-secondary);">
                                Không có dữ liệu
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>

                            <td>
                                <div style="font-weight:900;"><?= e($r['full_name']) ?></div>
                                <div style="opacity:.75; font-size:12px;"><?= e($r['email']) ?></div>
                            </td>

                            <td>
                                <div style="font-weight:800;"><?= e($r['receiver_phone']) ?></div>
                                <div style="opacity:.75; font-size:12px;"><?= e($r['receiver_name']) ?></div>
                            </td>

                            <td style="font-weight:900;"><?= fmtMoney($r['final_amount']) ?></td>

                            <td>
                                <div style="font-weight:800;"><?= e(paymentMethodLabel($r['pay_method'] ?? null)) ?>
                                </div>
                                <div style="opacity:.75; font-size:12px;">
                                    <?= e(paymentStatusLabel($r['pay_status'] ?? null)) ?></div>
                            </td>

                            <td><?= orderStatusBadge($r['status']) ?></td>

                            <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>

                            <td style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <a class="btn btn-outline btn-sm"
                                    href="<?= BASE_URL ?>/admin/orders/show.php?id=<?= (int)$r['id'] ?>">
                                    <i class="fa-regular fa-eye"></i>
                                </a>
                                <?php $isLocked = in_array($r['status'], $lockedStatuses, true); ?>
                                <!-- đổi trạng thái nhanh -->
                                <?php if ($isLocked): ?>
                                <!-- Đơn đã chốt => chỉ hiện label/badge -->
                                <span class="badge badge-secondary"
                                    title="Đơn đã hoàn thành/hủy nên không thể đổi trạng thái">
                                    <?= e($orderStatusMap[$r['status']] ?? $r['status']) ?>
                                </span>
                                <?php else: ?>
                                <!-- Đơn chưa chốt => cho đổi trạng thái -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

                                    <select class="input" name="status" style="height:34px; padding:6px 10px;"
                                        onchange="this.form.submit()">
                                        <?php foreach ($orderStatusMap as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $r['status']===$key?'selected':'' ?>>
                                            <?= e($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px; flex-wrap:wrap;">
                <?php
          $isFirst = ($page <= 1);
          $isLast  = ($page >= $totalPages);
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
          $start = max(1, $page - 3);
          $end   = min($totalPages, $page + 3);
        ?>

                <a class="btn btn-outline btn-sm <?= $isFirst ? 'disabled' : '' ?>"
                    <?= $isFirst ? 'aria-disabled="true"' : 'href="'.buildQuery(['page'=>$prev]).'"' ?>>
                    ← Trước
                </a>

                <?php for ($i=$start; $i<=$end; $i++): ?>
                <a class="btn btn-sm <?= $i===$page ? 'btn-primary' : 'btn-outline' ?>"
                    href="<?= buildQuery(['page'=>$i]) ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>

                <a class="btn btn-outline btn-sm <?= $isLast ? 'disabled' : '' ?>"
                    <?= $isLast ? 'aria-disabled="true"' : 'href="'.buildQuery(['page'=>$next]).'"' ?>>
                    Sau →
                </a>
            </div>
            <?php endif; ?>

        </div>
    </section>
</main>