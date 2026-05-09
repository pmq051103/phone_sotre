<?php
$pageTitle = 'Chi tiết phê duyệt - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID không hợp lệ');
    redirect(BASE_URL . '/admin/approvals/list.php');
}

// Lấy thông tin phê duyệt
$stmt = $conn->prepare("
    SELECT a.*, u.full_name AS actor_name
    FROM approvals a
    JOIN users u ON u.id = a.actor_id
    WHERE a.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$a = $stmt->get_result()->fetch_assoc();

if (!$a) {
    setFlash('danger', 'Không tìm thấy yêu cầu');
    redirect(BASE_URL . '/admin/approvals/list.php');
}

$payload = json_decode($a['payload'] ?? '{}', true) ?: [];

// Ưu tiên đọc old_data từ cột snapshot (chính xác tại thời điểm tạo request)
$current = null;
if (!empty($a['old_data'])) {
    $current = json_decode($a['old_data'], true) ?: null;
}

// Fallback: nếu old_data chưa có (record cũ trước khi thêm cột) thì query DB
if ($current === null && !empty($a['entity_id'])) {
    if ($a['entity'] === 'products') {
        $st = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $entityId = (int)$a['entity_id'];
        $st->bind_param("i", $entityId);
        $st->execute();
        $current = $st->get_result()->fetch_assoc();
    }
    if ($a['entity'] === 'discount_codes') {
        $st = $conn->prepare("SELECT * FROM discount_codes WHERE id = ?");
        $entityId = (int)$a['entity_id'];
        $st->bind_param("i", $entityId);
        $st->execute();
        $current = $st->get_result()->fetch_assoc();
    }
}

$brandMap = array_column($conn->query("SELECT id, name FROM brands")->fetch_all(MYSQLI_ASSOC), 'name', 'id');
$catMap   = array_column($conn->query("SELECT id, name FROM categories")->fetch_all(MYSQLI_ASSOC), 'name', 'id');

/** ================= Helpers ================= */
function fmtRef(string $k, $id, array $brandMap, array $catMap): string {
    if (!$id) return '—';
    $id = (int)$id;
    if ($k === 'brand_id')    return ($brandMap[$id] ?? "#$id");
    if ($k === 'category_id') return ($catMap[$id]   ?? "#$id");
    return (string)$id;
}

function fmtMoneyVND($n): string {
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 0, ',', '.') . 'đ';
}

function fmtStatusCommon($v): string {
    return ((int)$v === 1) ? 'Hiển thị' : 'Ẩn';
}

function badgeDiffText($oldText, $newText): string {
    if ((string)$oldText === (string)$newText)
        return '<span class="badge badge-gray">Không đổi</span>';
    return '<span class="badge badge-info">Thay đổi</span>';
}

function safeDate($dt): string {
    if (!$dt) return '—';
    return date('d/m/Y', strtotime($dt));
}

function safeDateTime($dt): string {
    if (!$dt) return '—';
    return date('d/m/Y H:i', strtotime($dt));
}

/** ---- Products ---- */
function labelProductField(string $k): string {
    $map = [
        'name'        => 'Tên sản phẩm',
        'brand_id'    => 'Hãng',
        'category_id' => 'Loại',
        'price'       => 'Giá',
        'quantity'    => 'Tồn kho',
        'status'      => 'Trạng thái',
        'ram'         => 'RAM',
        'rom'         => 'ROM',
        'cpu'         => 'CPU',
        'camera'      => 'Camera',
        'battery'     => 'Pin',
        'thumbnail'   => 'Thumbnail',
    ];
    return $map[$k] ?? $k;
}

function fmtProductValue(string $k, $v): string {
    if ($v === null || $v === '') return '—';
    if ($k === 'price')    return fmtMoneyVND($v);
    if ($k === 'status')   return fmtStatusCommon($v);
    if ($k === 'quantity') return (string)(int)$v;
    return (string)$v;
}

/** ---- Discount codes ---- */
function labelDiscountField(string $k): string {
    $map = [
        'code'            => 'CODE',
        'discount_type'   => 'Loại giảm',
        'discount_value'  => 'Giá trị giảm',
        'min_order_value' => 'Min order',
        'max_discount'    => 'Max discount',
        'quantity'        => 'Số lượt dùng',
        'start_date'      => 'Start date',
        'end_date'        => 'End date',
        'status'          => 'Trạng thái',
    ];
    return $map[$k] ?? $k;
}

function fmtDiscountType($t): string {
    return $t === 'fixed' ? 'Tiền' : '%';
}

function fmtDiscountValue($type, $val, $maxDiscount = null): string {
    if ($val === null || $val === '') return '—';
    $type = (string)$type;
    if ($type === 'percent') {
        $p   = (float)$val;
        $txt = rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
        if ($maxDiscount !== null && $maxDiscount !== '')
            $txt .= ' (max ' . fmtMoneyVND($maxDiscount) . ')';
        return $txt;
    }
    return fmtMoneyVND($val);
}

function fmtDiscountValueOnly(string $k, $v): string {
    if ($v === null || $v === '') return '—';
    if (in_array($k, ['min_order_value', 'max_discount'], true)) return fmtMoneyVND($v);
    if ($k === 'quantity') return (string)(int)$v;
    if ($k === 'status')   return fmtStatusCommon($v);
    if ($k === 'start_date' || $k === 'end_date') return safeDate($v);
    return (string)$v;
}
/** ================================================= */

$isProduct  = ($a['entity'] === 'products');
$isDiscount = ($a['entity'] === 'discount_codes');
$action     = $a['action'] ?? '';
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Yêu cầu #<?= (int)$a['id'] ?></div>
            <div class="admin-sub">
                <?= e($a['actor_name']) ?> • <?= e($a['entity']) ?> • <?= e($a['action']) ?> • <?= e(safeDateTime($a['created_at'])) ?>
            </div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/approvals/list.php">
                <i class="fa-solid fa-arrow-left"></i> Quay lại
            </a>
        </div>
    </div>

    <section class="admin-panel">
        <div class="admin-panel__head">
            <strong>Trạng thái</strong>
            <?php if ($a['status'] === 'pending'): ?>
                <span class="badge badge-info">Chờ duyệt</span>
            <?php elseif ($a['status'] === 'approved'): ?>
                <span class="badge badge-success">Đã duyệt</span>
            <?php else: ?>
                <span class="badge badge-danger">Từ chối</span>
            <?php endif; ?>
        </div>

        <div class="admin-panel__body">

            <!-- ==================== PRODUCTS ==================== -->
            <?php if ($isProduct):
                $fields = ['name', 'brand_id', 'category_id', 'price', 'quantity', 'status', 'ram', 'rom', 'cpu', 'camera', 'battery'];

                // --- Thumbnail ---
                // action=create  : payload['thumbnail']
                // action=update  : payload['thumbnail_new'] (null = không đổi) + payload['thumbnail_old']
                $thumbNew = $payload['thumbnail_new'] ?? ($payload['thumbnail'] ?? null);
                $thumbOld = $payload['thumbnail_old'] ?? ($current['thumbnail'] ?? null);

                // Ảnh đề xuất hiển thị: nếu có thumb mới thì show thumb mới, không thì show thumb cũ
                $thumbShowNew = $thumbNew
                    ? (UPLOAD_URL . e($thumbNew))
                    : ($thumbOld ? (UPLOAD_URL . e($thumbOld)) : (BASE_URL . '/assets/images/no-image.png'));
                $thumbShowOld = $thumbOld
                    ? (UPLOAD_URL . e($thumbOld))
                    : (BASE_URL . '/assets/images/no-image.png');
                $thumbChanged = $thumbNew && ($thumbNew !== $thumbOld);

                // --- Gallery ---
                // Ảnh hiện tại (từ old_data hoặc DB): lấy từ product_images qua entity_id
                $currentGallery = [];
                if (!empty($a['entity_id'])) {
                    $stG = $conn->prepare("SELECT id, image_url FROM product_images WHERE product_id=? ORDER BY id DESC");
                    $gId = (int)$a['entity_id'];
                    $stG->bind_param("i", $gId);
                    $stG->execute();
                    $currentGallery = $stG->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                // Ảnh thêm mới trong request
                $addImages    = $payload['add_images']    ?? ($payload['images'] ?? []);
                // Ảnh bị xóa trong request — mỗi item có id + image_url
                $deleteImages = $payload['delete_images'] ?? [];
                $deleteIds    = array_column($deleteImages, 'id');
            ?>

                <div class="admin-grid-2" style="gap:14px;">

                    <!-- Thông tin đề xuất -->
                    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:14px;">
                        <div style="font-weight:900;margin-bottom:10px;">Thông tin đề xuất</div>
                        <div class="admin-grid-2" style="gap:10px;">
                            <?php foreach ($fields as $k):
                                $newVal  = $payload[$k] ?? null;
                                $newText = in_array($k, ['brand_id', 'category_id'])
                                             ? fmtRef($k, $newVal, $brandMap, $catMap)
                                             : fmtProductValue($k, $newVal);
                            ?>
                                <div style="border:1px solid rgba(0,0,0,.06);border-radius:12px;padding:10px;">
                                    <div style="font-size:12px;opacity:.7;margin-bottom:4px;"><?= e(labelProductField($k)) ?></div>
                                    <div style="font-weight:800;"><?= e($newText) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:12px;">
                            <div style="font-size:12px;opacity:.7;margin-bottom:6px;">Mô tả</div>
                            <div style="border:1px solid rgba(0,0,0,.06);border-radius:12px;padding:10px;max-height:240px;overflow:auto;">
                                <?= $payload['description'] ?? '—' ?>
                            </div>
                        </div>

                        <div style="margin-top:12px;">
                            <div style="font-size:12px;opacity:.7;margin-bottom:6px;">Thumbnail</div>
                            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                                <!-- Thumb cũ -->
                                <div style="text-align:center;">
                                    <div style="font-size:11px;opacity:.6;margin-bottom:4px;">Hiện tại</div>
                                    <img src="<?= $thumbShowOld ?>"
                                         style="width:100px;height:100px;object-fit:contain;border-radius:12px;border:1px solid rgba(0,0,0,.06);background:#f8f9fa;">
                                </div>
                                <?php if ($thumbChanged): ?>
                                <div style="display:flex;align-items:center;padding-top:24px;color:#999;font-size:18px;">→</div>
                                <!-- Thumb mới -->
                                <div style="text-align:center;">
                                    <div style="font-size:11px;margin-bottom:4px;color:#22c55e;font-weight:700;">Mới đề xuất</div>
                                    <img src="<?= $thumbShowNew ?>"
                                         style="width:100px;height:100px;object-fit:contain;border-radius:12px;border:2px solid #22c55e;background:#f8f9fa;">
                                </div>
                                <?php else: ?>
                                <div style="display:flex;align-items:center;padding-top:24px;">
                                    <span class="badge badge-gray">Không đổi</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Gallery -->
                        <div style="margin-top:12px;">
                            <div style="font-size:12px;opacity:.7;margin-bottom:6px;">Ảnh chi tiết</div>

                            <?php if ($currentGallery): ?>
                            <div style="margin-bottom:6px;font-size:12px;font-weight:700;opacity:.7;">Hiện tại</div>
                            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:10px;">
                                <?php foreach ($currentGallery as $img):
                                    $isDeleted = in_array($img['id'], $deleteIds, true);
                                ?>
                                    <div style="position:relative;border-radius:10px;overflow:hidden;border:<?= $isDeleted ? '2px solid #ef4444' : '1px solid rgba(0,0,0,.06)' ?>;">
                                        <img src="<?= UPLOAD_URL . e($img['image_url']) ?>"
                                             style="width:100%;height:64px;object-fit:contain;background:#f8f9fa;<?= $isDeleted ? 'opacity:.4;' : '' ?>">
                                        <?php if ($isDeleted): ?>
                                            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
                                                <span style="background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:6px;">Xóa</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($addImages): ?>
                            <div style="margin-bottom:6px;font-size:12px;font-weight:700;color:#22c55e;">Thêm mới</div>
                            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
                                <?php foreach ($addImages as $fn): ?>
                                    <div style="border:2px solid #22c55e;border-radius:10px;overflow:hidden;">
                                        <img src="<?= UPLOAD_URL . e($fn) ?>"
                                             style="width:100%;height:64px;object-fit:contain;background:#f8f9fa;">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif (!$currentGallery): ?>
                                <div style="color:var(--text-secondary);font-size:13px;">Không có ảnh chi tiết.</div>
                            <?php endif; ?>
                        </div>
                    </div><!-- /đề xuất -->

                    <!-- So sánh -->
                    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:14px;">
                        <div style="font-weight:900;margin-bottom:10px;">So sánh</div>

                        <?php if ($action === 'create'): ?>
                            <div style="color:var(--text-secondary);">Dữ liệu mới (Create) — không có dữ liệu cũ để so sánh.</div>

                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>Hiện tại</th>
                                        <th>Đề xuất</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fields as $k):
                                        $oldVal  = $current[$k] ?? null;
                                        $newVal  = array_key_exists($k, $payload) ? $payload[$k] : $oldVal;

                                        $oldText = in_array($k, ['brand_id', 'category_id'])
                                                     ? fmtRef($k, $oldVal, $brandMap, $catMap)
                                                     : fmtProductValue($k, $oldVal);
                                        $newText = in_array($k, ['brand_id', 'category_id'])
                                                     ? fmtRef($k, $newVal, $brandMap, $catMap)
                                                     : fmtProductValue($k, $newVal);
                                    ?>
                                        <tr>
                                            <td><?= e(labelProductField($k)) ?></td>
                                            <td><?= e($oldText) ?></td>
                                            <td><?= e($newText) ?></td>
                                            <td><?= badgeDiffText($oldText, $newText) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div><!-- /so sánh -->

                </div>

            <!-- ==================== DISCOUNT CODES ==================== -->
            <?php elseif ($isDiscount):
                $fields  = ['code', 'discount_type', 'discount_value', 'min_order_value', 'max_discount', 'quantity', 'start_date', 'end_date', 'status'];
                $newType = $payload['discount_type']  ?? ($current['discount_type']  ?? 'percent');
                $newVal  = $payload['discount_value'] ?? ($current['discount_value'] ?? null);
                $newMax  = $payload['max_discount']   ?? ($current['max_discount']   ?? null);
                $oldType = $current['discount_type']  ?? 'percent';
                $oldMax  = $current['max_discount']   ?? null;
            ?>

                <div class="admin-grid-2" style="gap:14px;">

                    <!-- Đề xuất -->
                    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:14px;">
                        <div style="font-weight:900;margin-bottom:10px;">Đề xuất</div>
                        <div class="admin-grid-2" style="gap:10px;">
                            <?php foreach ($fields as $k):
                                $v = $payload[$k] ?? null;
                                if ($k === 'discount_type')
                                    $txt = fmtDiscountType($v);
                                elseif ($k === 'discount_value')
                                    $txt = fmtDiscountValue($newType, $newVal, $newMax);
                                elseif ($k === 'max_discount')
                                    $txt = ($newType === 'percent') ? fmtMoneyVND($v) : '—';
                                else
                                    $txt = fmtDiscountValueOnly($k, $v);
                            ?>
                                <div style="border:1px solid rgba(0,0,0,.06);border-radius:12px;padding:10px;">
                                    <div style="font-size:12px;opacity:.7;"><?= e(labelDiscountField($k)) ?></div>
                                    <div style="font-weight:800;"><?= e($txt) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div><!-- /đề xuất -->

                    <!-- So sánh -->
                    <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:14px;">
                        <div style="font-weight:900;margin-bottom:10px;">So sánh</div>

                        <?php if ($action === 'create'): ?>
                            <div style="color:var(--text-secondary);">Mã mới — không có dữ liệu cũ để so sánh.</div>

                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>Hiện tại</th>
                                        <th>Đề xuất</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fields as $k):
                                        $oldVal = $current[$k] ?? null;
                                        $newVal = array_key_exists($k, $payload) ? $payload[$k] : $oldVal;

                                        if ($k === 'discount_type') {
                                            $oldText = fmtDiscountType($oldVal);
                                            $newText = fmtDiscountType($newVal);
                                        } elseif ($k === 'discount_value') {
                                            $oldText = fmtDiscountValue($oldType, $oldVal, $oldMax);
                                            $newText = fmtDiscountValue($newType, $newVal, $newMax);
                                        } elseif ($k === 'max_discount') {
                                            $oldText = ($oldType === 'percent') ? fmtMoneyVND($oldVal) : '—';
                                            $newText = ($newType === 'percent') ? fmtMoneyVND($newVal) : '—';
                                        } else {
                                            $oldText = fmtDiscountValueOnly($k, $oldVal);
                                            $newText = fmtDiscountValueOnly($k, $newVal);
                                        }
                                    ?>
                                        <tr>
                                            <td><?= e(labelDiscountField($k)) ?></td>
                                            <td><?= e($oldText) ?></td>
                                            <td><?= e($newText) ?></td>
                                            <td><?= badgeDiffText($oldText, $newText) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div><!-- /so sánh -->

                </div>

            <!-- ==================== FALLBACK ==================== -->
            <?php else: ?>
                <pre style="background:#eee;padding:10px;"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>

            <!-- ==================== APPROVE / REJECT FORM ==================== -->
            <?php if ($a['status'] === 'pending'): ?>
                <hr style="margin:20px 0;">
                <form method="POST" action="<?= BASE_URL ?>/admin/approvals/handle.php" style="display:flex;gap:10px;">
                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                    <input type="hidden" name="id"         value="<?= (int)$a['id'] ?>">
                    <input class="input" style="flex:1" name="note" placeholder="Ghi chú...">
                    <button class="btn btn-danger"  name="decision" value="reject">Từ chối</button>
                    <button class="btn btn-primary" name="decision" value="approve">Duyệt</button>
                </form>
            <?php endif; ?>

        </div>
    </section>
</main>