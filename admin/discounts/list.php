<?php
$pageTitle = 'Quản lý mã giảm giá - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
requireRole(['admin','staff']);
$isAdmin = isAdmin();
$isStaff = isStaff();

$errors = [];

/** helpers */
function intOrNull($v) {
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v)) return null;
    return (int)$v;
}

function buildQuery(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ('?' . $qs) : '';
}

function fmtMoney($n) {
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 0, '.', ',') . 'đ';
}

function fmtDiscount($type, $val) {
    $val = (float)$val;
    if ($type === 'percent') return rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.') . '%';
    return fmtMoney($val);
}

/** Handle POST (delete / toggle) - Chuyển sang MySQLi */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) $errors[] = 'ID không hợp lệ.';
        if (!in_array($action, ['delete','toggle'], true)) $errors[] = 'Action không hợp lệ.';

        if (!$errors) {
            try {
                // Lấy discount hiện tại
                $stInfo = $conn->prepare("SELECT id, code, status, used_count, discount_type, discount_value FROM discount_codes WHERE id=?");
                $stInfo->bind_param("i", $id);
                $stInfo->execute();
                $dc = $stInfo->get_result()->fetch_assoc();

                if (!$dc) {
                    $errors[] = 'Mã không tồn tại.';
                } else {
                    // ========================= STAFF: tạo approval =========================
                    if ($isStaff) {
                        $payload = ['id' => $id, 'code' => $dc['code']];
                        $actType = ($action === 'toggle') ? 'update' : 'delete';
                        
                        if ($action === 'toggle') {
                            $payload['status_old'] = (int)$dc['status'];
                            $payload['status_new'] = ((int)$dc['status'] === 1) ? 0 : 1;
                        } else {
                            $payload['used_count'] = (int)$dc['used_count'];
                        }

                        $approvalId = createApproval($conn, [
                            'actor_id' => (int)(getCurrentUser()['id'] ?? 0),
                            'action' => $actType,
                            'entity' => 'discount_codes',
                            'entity_id' => $id,
                            'payload' => $payload,
                        ]);

                        setFlash('success', "Đã gửi yêu cầu thao tác (#$approvalId). Chờ phê duyệt.");
                        redirect(BASE_URL . '/admin/discounts/list.php' . buildQuery());
                        exit;
                    }

                    // ========================= ADMIN: xử lý trực tiếp =========================
                    if ($action === 'delete') {
                        $used = (int)$dc['used_count']; 
                        if ($used > 0) {
                            $errors[] = "Không thể xóa vì mã đã được sử dụng ({$used} lần).";
                        } else {
                            // Check log sử dụng
                            $lc = $conn->prepare("SELECT id FROM discount_usages WHERE discount_code_id = ? LIMIT 1");
                            $lc->bind_param("i", $id);
                            $lc->execute();
                            $lc->store_result();
                            
                            if ($lc->num_rows > 0) {
                                $errors[] = "Không thể xóa vì đã có log dùng mã. Hãy ẩn thay vì xóa.";
                            } else {
                                $del = $conn->prepare("DELETE FROM discount_codes WHERE id=?");
                                $del->bind_param("i", $id);
                                $del->execute();
                                setFlash('success', 'Đã xóa mã giảm giá.');
                                redirect(BASE_URL . '/admin/discounts/list.php' . buildQuery());
                                exit;
                            }
                        }
                    } elseif ($action === 'toggle') {
                        $st = $conn->prepare("UPDATE discount_codes SET status = IF(status=1,0,1) WHERE id=?");
                        $st->bind_param("i", $id);
                        $st->execute();
                        setFlash('success', 'Đã cập nhật trạng thái.');
                        redirect(BASE_URL . '/admin/discounts/list.php' . buildQuery());
                        exit;
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Có lỗi khi thao tác: ' . $e->getMessage();
            }
        }
    }
}

/** Filters Logic */
$keyword = trim($_GET['q'] ?? '');
$status  = intOrNull($_GET['status'] ?? null);
$type    = trim($_GET['type'] ?? ''); 
$activeNow = (int)($_GET['active_now'] ?? 0);

$where = [];
$values = [];
$types = "";

if ($keyword !== '') {
    $where[] = "dc.code LIKE ?";
    $values[] = "%{$keyword}%";
    $types .= "s";
}
if ($status !== null && in_array($status, [0, 1], true)) {
    $where[] = "dc.status = ?";
    $values[] = $status;
    $types .= "i";
}
if (in_array($type, ['percent', 'fixed'], true)) {
    $where[] = "dc.discount_type = ?";
    $values[] = $type;
    $types .= "s";
}
if ($activeNow === 1) {
    $where[] = "(dc.start_date IS NULL OR dc.start_date <= NOW()) AND (dc.end_date IS NULL OR dc.end_date >= NOW())";
}

$baseWhere = $where ? (" WHERE " . implode(" AND ", $where)) : "";

/** Pagination Logic */
$perPage = 7;
$page = max(1, (int)($_GET['page'] ?? 1));

// Đếm tổng
$stTotal = $conn->prepare("SELECT COUNT(*) FROM discount_codes dc" . $baseWhere);
if ($where) $stTotal->bind_param($types, ...$values);
$stTotal->execute();
$totalRows = $stTotal->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/** Get List Data */
$sql = "SELECT dc.* FROM discount_codes dc $baseWhere ORDER BY dc.id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$listTypes = $types . "ii";
$listValues = array_merge($values, [$perPage, $offset]);
$stmt->bind_param($listTypes, ...$listValues);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <h1 class="admin-title">Quản lý mã giảm giá</h1>
            <p class="admin-sub">Danh sách các mã khuyến mãi</p>
        </div>
        <div class="admin-actions">
            <a href="add.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Thêm mã mới</a>
        </div>
    </div>

    <!-- Filter Form -->
    <section class="admin-panel">
        <div class="admin-panel__body">
            <form method="GET" class="admin-filters">
                <div class="admin-filter-row">
                    <div class="filter-item">
                        <label>Từ khóa</label>
                        <input class="input" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Nhập mã code...">
                    </div>
                    <div class="filter-item">
                        <label>Trạng thái</label>
                        <select class="input" name="status">
                            <option value="">Tất cả</option>
                            <option value="1" <?= $status === 1 ? 'selected' : '' ?>>Đang bật</option>
                            <option value="0" <?= $status === 0 ? 'selected' : '' ?>>Đang tắt</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Loại</label>
                        <select class="input" name="type">
                            <option value="">Tất cả</option>
                            <option value="percent" <?= $type === 'percent' ? 'selected' : '' ?>>Phần trăm (%)</option>
                            <option value="fixed" <?= $type === 'fixed' ? 'selected' : '' ?>>Cố định (đ)</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>&nbsp;</label>
                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                            <input type="checkbox" name="active_now" value="1" <?= $activeNow === 1 ? 'checked' : '' ?>> Đang hiệu lực
                        </label>
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Lọc</button>
                        <a href="list.php" class="btn btn-outline btn-sm">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- Display Errors -->
    <?php if ($errors): ?>
        <div class="alert alert-danger" style="margin-top:10px;">
            <ul style="margin:0; padding-left:20px;">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Table -->
    <section class="admin-panel" style="margin-top:12px;">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>Loại giảm</th>
                        <th>Giá trị</th>
                        <th>Lượt dùng</th>
                        <th>Thời gian</th>
                        <th>Trạng thái</th>
                        <th style="text-align:right;">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" style="text-align:center;">Không tìm thấy mã nào.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['code']) ?></strong></td>
                            <td><?= $r['discount_type'] === 'percent' ? 'Phần trăm' : 'Cố định' ?></td>
                            <td>
                                <div style="color:var(--primary-color); font-weight:700;">
                                    <?= fmtDiscount($r['discount_type'], $r['discount_value']) ?>
                                </div>
                                <?php if($r['max_discount']): ?><small>Tối đa: <?= fmtMoney($r['max_discount']) ?></small><?php endif; ?>
                            </td>
                            <td><?= (int)$r['used_count'] ?> / <?= $r['quantity'] ?: '∞' ?></td>
                            <td>
                                <small>Từ: <?= $r['start_date'] ?: 'N/A' ?></small><br>
                                <small>Đến: <?= $r['end_date'] ?: 'N/A' ?></small>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="badge <?= $r['status'] ? 'badge-success' : 'badge-gray' ?>" style="border:none; cursor:pointer;">
                                        <?= $r['status'] ? 'Bật' : 'Tắt' ?>
                                    </button>
                                </form>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; gap:5px; justify-content:flex-end;">
                                    <a href="edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline">Sửa</a>
                                    <button type="button" class="btn btn-sm btn-outline js-del-btn" 
                                            data-id="<?= $r['id'] ?>" data-code="<?= $r['code'] ?>" style="color:red;">Xóa</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination" style="margin-top:15px; display:flex; justify-content:center; gap:5px;">
                <?php for ($i=1; $i<=$totalPages; $i++): ?>
                    <a href="<?= buildQuery(['page' => $i]) ?>" class="btn btn-sm <?= $i == $page ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const delButtons = document.querySelectorAll('.js-del-btn');
    delButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const code = this.dataset.code;
            if (confirm(`Xác nhận xóa mã giảm giá [${code}]?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>