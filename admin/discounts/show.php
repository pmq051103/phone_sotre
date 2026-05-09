<?php
$pageTitle = 'Chi tiết mã giảm giá - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID không hợp lệ.');
    redirect(BASE_URL . '/admin/discounts/list.php');
}

// 1. Lấy thông tin chi tiết mã giảm giá (MySQLi)
$sqlDc = "SELECT * FROM discount_codes WHERE id = ?";
$st = $conn->prepare($sqlDc);
$st->bind_param("i", $id);
$st->execute();
$dc = $st->get_result()->fetch_assoc();

if (!$dc) {
    setFlash('danger', 'Mã giảm giá không tồn tại.');
    redirect(BASE_URL . '/admin/discounts/list.php');
}

function fmtMoney($n){ return number_format((float)$n, 0, '.', ',') . 'đ'; }
function fmtDiscount($type, $val){
    $val = (float)$val;
    return $type === 'percent' ? (rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.') . '%') : fmtMoney($val);
}

/** Helpers */
function buildQuery(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ('?' . $qs) : '';
}

/** Search log by email/name */
$logKeyword = trim($_GET['uq'] ?? '');

/** Log pagination */
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/** 2. Đếm tổng logs với filter (MySQLi) */
$whereLog = "du.discount_code_id = ?";
$paramsLog = [$id];
$typesLog = "i";

if ($logKeyword !== '') {
    $whereLog .= " AND (u.email LIKE ? OR u.full_name LIKE ?)";
    $searchWildcard = "%{$logKeyword}%";
    $paramsLog[] = $searchWildcard;
    $paramsLog[] = $searchWildcard;
    $typesLog .= "ss";
}

$sqlCount = "SELECT COUNT(*) FROM discount_usages du JOIN users u ON u.id = du.user_id WHERE $whereLog";
$stTotal = $conn->prepare($sqlCount);
$stTotal->bind_param($typesLog, ...$paramsLog);
$stTotal->execute();
$totalRows = $stTotal->get_result()->fetch_row()[0];

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

/** 3. Lấy danh sách Logs (MySQLi) */
$sqlLogs = "
    SELECT du.*, u.full_name, u.email, o.status AS order_status, o.final_amount
    FROM discount_usages du
    JOIN users u ON u.id = du.user_id
    LEFT JOIN orders o ON o.id = du.order_id
    WHERE $whereLog
    ORDER BY du.used_at DESC
    LIMIT ? OFFSET ?
";

$stLog = $conn->prepare($sqlLogs);
$typesWithLimit = $typesLog . "ii";
$paramsWithLimit = array_merge($paramsLog, [$perPage, $offset]);

$stLog->bind_param($typesWithLimit, ...$paramsWithLimit);
$stLog->execute();
$logs = $stLog->get_result()->fetch_all(MYSQLI_ASSOC);

// Sync used_count logic (optional)
$realUsed = $totalRows;
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Chi tiết mã: <?= htmlspecialchars($dc['code']) ?></div>
            <div class="admin-sub">
                ID #<?= (int)$dc['id'] ?> • used_count: <?= (int)$dc['used_count'] ?> • log thực tế: <?= (int)$realUsed ?>
            </div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/discounts/list.php">← Quay lại</a>
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/discounts/edit.php?id=<?= (int)$dc['id'] ?>">
                <i class="fa-regular fa-pen-to-square"></i> Sửa
            </a>
        </div>
    </div>

    <!-- Thông tin chung -->
    <section class="admin-panel">
        <div class="admin-panel__head"><strong>Thông tin mã giảm giá</strong></div>
        <div class="admin-panel__body">
            <div class="dc-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Loại giảm</div>
                    <div class="dc-value" style="font-weight: bold;"><?= $dc['discount_type'] === 'percent' ? 'Giảm %' : 'Giảm tiền' ?></div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Giá trị</div>
                    <div class="dc-value" style="font-weight: bold; color: var(--primary-color);"><?= htmlspecialchars(fmtDiscount($dc['discount_type'], $dc['discount_value'])) ?></div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Trạng thái</div>
                    <div style="margin-top:4px;">
                        <?= (int)$dc['status'] === 1 ? '<span class="badge badge-success">Đang bật</span>' : '<span class="badge badge-gray">Đang tắt</span>' ?>
                    </div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Đơn tối thiểu</div>
                    <div class="dc-value"><?= fmtMoney($dc['min_order_value']) ?></div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Giảm tối đa</div>
                    <div class="dc-value">
                        <?= $dc['discount_type'] === 'percent' && $dc['max_discount'] !== null ? fmtMoney($dc['max_discount']) : '—' ?>
                    </div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Lượt dùng</div>
                    <div class="dc-value"><?= (int)$dc['used_count'] ?> / <?= ((int)$dc['quantity'] > 0 ? (int)$dc['quantity'] : '∞') ?></div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Ngày bắt đầu</div>
                    <div class="dc-value"><?= $dc['start_date'] ? date('d/m/Y H:i', strtotime($dc['start_date'])) : '—' ?></div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Ngày kết thúc</div>
                    <div class="dc-value"><?= $dc['end_date'] ? date('d/m/Y H:i', strtotime($dc['end_date'])) : '—' ?></div>
                </div>

                <div class="dc-card" style="padding: 10px; border: 1px solid #eee; border-radius: 4px;">
                    <div class="dc-label" style="font-size: 0.85rem; color: #666;">Ngày tạo</div>
                    <div class="dc-value"><?= date('d/m/Y H:i', strtotime($dc['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Lịch sử sử dụng (Log) -->
    <section class="admin-panel" style="margin-top:12px;">
        <div class="admin-panel__head" style="display: flex; justify-content: space-between; align-items: center;">
            <strong>Lịch sử dùng mã</strong>
            <span class="badge badge-info">Tổng: <?= (int)$totalRows ?> lượt</span>
        </div>

        <div class="admin-panel__body">
            <!-- Bộ lọc Log -->
            <form method="GET" class="admin-filters" style="margin-bottom:15px;">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <div class="admin-filter-row">
                    <div class="filter-item">
                        <label>Tìm kiếm khách hàng</label>
                        <input class="input" name="uq" value="<?= htmlspecialchars($logKeyword) ?>" placeholder="Email hoặc tên khách hàng...">
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Tìm kiếm</button>
                        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/discounts/show.php?id=<?= (int)$id ?>">Reset</a>
                    </div>
                </div>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:80px;">ID</th>
                            <th>Khách hàng</th>
                            <th>Email</th>
                            <th style="width:100px;">Đơn hàng</th>
                            <th>Trạng thái đơn</th>
                            <th>Thành tiền</th>
                            <th>Thời gian dùng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px; color:#999;">Không có lịch sử sử dụng nào.</td></tr>
                        <?php else: foreach ($logs as $l): ?>
                            <tr>
                                <td>#<?= (int)$l['id'] ?></td>
                                <td style="font-weight:bold;"><?= htmlspecialchars($l['full_name']) ?></td>
                                <td><?= htmlspecialchars($l['email']) ?></td>
                                <td><?= $l['order_id'] ? '<a href="../orders/show.php?id='.$l['order_id'].'">#'.$l['order_id'].'</a>' : '—' ?></td>
                                <td><?= $l['order_status'] ? htmlspecialchars($l['order_status']) : '—' ?></td>
                                <td><?= $l['final_amount'] !== null ? fmtMoney($l['final_amount']) : '—' ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($l['used_at'])) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Phân trang Log -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="display:flex; justify-content:center; margin-top:20px; gap:5px;">
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>
                    
                    <?php if($page > 1): ?>
                        <a class="btn btn-outline btn-sm" href="<?= buildQuery(['page' => $page - 1]) ?>">Trước</a>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>" href="<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if($page < $totalPages): ?>
                        <a class="btn btn-outline btn-sm" href="<?= buildQuery(['page' => $page + 1]) ?>">Sau</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>