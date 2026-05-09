<?php
$pageTitle = 'Sửa mã giảm giá - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
requireRole(['admin','staff']);
$isAdmin = isAdmin();
$isStaff = isStaff();

$errors = [];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID không hợp lệ.');
    redirect(BASE_URL . '/admin/discounts/list.php');
}

// Lấy dữ liệu cũ bằng MySQLi
$st = $conn->prepare("SELECT * FROM discount_codes WHERE id=?");
$st->bind_param("i", $id);
$st->execute();
$result = $st->get_result();
$data = $result->fetch_assoc();

if (!$data) {
    setFlash('danger', 'Mã giảm giá không tồn tại.');
    redirect(BASE_URL . '/admin/discounts/list.php');
}

$oldCode = $data['code'] ?? null;

function toNull($v){
    $v = trim((string)$v);
    return $v==='' ? null : $v;
}
function toFloat($v){
    $v = trim((string)$v);
    return $v==='' ? null : (is_numeric($v) ? (float)$v : null);
}
function toInt($v){
    $v = trim((string)$v);
    return $v==='' ? null : (is_numeric($v) ? (int)$v : null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $data['code'] = strtoupper(trim($_POST['code'] ?? ''));
        $data['discount_type'] = trim($_POST['discount_type'] ?? 'percent');
        $data['discount_value'] = toFloat($_POST['discount_value'] ?? '');
        $data['min_order_value'] = toFloat($_POST['min_order_value'] ?? 0) ?? 0;
        $data['max_discount'] = toFloat($_POST['max_discount'] ?? '');
        $data['quantity'] = toInt($_POST['quantity'] ?? 0) ?? 0;
        $data['start_date'] = toNull($_POST['start_date'] ?? '');
        $data['end_date']   = toNull($_POST['end_date'] ?? '');
        $data['status'] = (int)($_POST['status'] ?? 1);

        // validate
        if ($data['code'] === '') $errors[] = 'Vui lòng nhập CODE.';
        if (!in_array($data['discount_type'], ['percent','fixed'], true)) $errors[] = 'Loại giảm không hợp lệ.';
        if ($data['discount_value'] === null || $data['discount_value'] <= 0) $errors[] = 'Giá trị giảm phải > 0.';
        if ($data['min_order_value'] < 0) $errors[] = 'Min order không hợp lệ.';
        if ($data['quantity'] < 0) $errors[] = 'Số lượt dùng không hợp lệ.';

        if ($data['discount_type'] === 'percent') {
            if ($data['discount_value'] > 100) $errors[] = 'Giảm % không được > 100.';
        } else {
            $data['max_discount'] = null;
        }

        if ($data['start_date'] && $data['end_date']) {
            if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
                $errors[] = 'Start date không được lớn hơn end date.';
            }
        }

        $usedCount = (int)($data['used_count'] ?? 0);
        if ($data['quantity'] > 0 && $usedCount > $data['quantity']) {
            $errors[] = "Quantity không thể nhỏ hơn used_count hiện tại ({$usedCount}).";
        }

        if (!$errors) {
            try {
                // ================= STAFF: tạo yêu cầu phê duyệt UPDATE =================
                if ($isStaff) {
                    $approvalId = createApproval($conn, [
                        'actor_id' => (int)(getCurrentUser()['id'] ?? 0),
                        'action' => 'update',
                        'entity' => 'discount_codes',
                        'entity_id' => $id,
                        'payload' => array_merge($data, ['id' => $id, 'code_old' => $oldCode]),
                    ]);

                    setFlash('success', "Đã gửi yêu cầu cập nhật mã giảm giá (#$approvalId). Chờ admin phê duyệt.");
                    redirect(BASE_URL . '/admin/discounts/list.php');
                    exit;
                }

                // ================= ADMIN: update thật (MySQLi) =================
                $sql = "UPDATE discount_codes SET 
                            code = ?, 
                            discount_type = ?, 
                            discount_value = ?, 
                            min_order_value = ?, 
                            max_discount = ?, 
                            quantity = ?, 
                            start_date = ?, 
                            end_date = ?, 
                            status = ? 
                        WHERE id = ?";
                
                $up = $conn->prepare($sql);
                
                // s: string, d: double, i: integer
                // Định dạng: ssdddisssi
                $up->bind_param(
                    "ssdddisssi",
                    $data['code'],
                    $data['discount_type'],
                    $data['discount_value'],
                    $data['min_order_value'],
                    $data['max_discount'],
                    $data['quantity'],
                    $data['start_date'],
                    $data['end_date'],
                    $data['status'],
                    $id
                );

                $up->execute();

                setFlash('success', 'Đã cập nhật mã giảm giá.');
                redirect(BASE_URL . '/admin/discounts/list.php');
                exit;

            } catch (Exception $e) {
                if ($conn->errno == 1062 || str_contains($e->getMessage(), 'Duplicate')) {
                    $errors[] = 'CODE đã tồn tại.';
                } else {
                    $errors[] = 'Có lỗi khi lưu dữ liệu. Vui lòng thử lại.';
                }
            }
        }
    }
}
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Sửa mã giảm giá</div>
            <div class="admin-sub">#<?= (int)$id ?> • <?= e($data['code']) ?> • used_count:
                <?= (int)$data['used_count'] ?></div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/discounts/list.php">← Quay lại</a>
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/discounts/show.php?id=<?= (int)$id ?>">
                <i class="fa-regular fa-eye"></i> Xem log
            </a>
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

    <section class="admin-panel">
        <div class="admin-panel__head"><strong>Thông tin mã</strong></div>
        <div class="admin-panel__body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                <?php include __DIR__.'/_form.php'; ?>

                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                    <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/discounts/list.php">Hủy</a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-save"></i> <?= $isStaff ? 'Gửi phê duyệt' : 'Cập nhật' ?>
                    </button>
                </div>
            </form>
        </div>
    </section>
</main>