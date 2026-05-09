<?php
$pageTitle = 'Quản lý sản phẩm - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// 1. Khởi tạo Filters
$q = trim($_GET['q'] ?? '');
$brandId = (int)($_GET['brand_id'] ?? 0);
$catId   = (int)($_GET['category_id'] ?? 0);
$status  = $_GET['status'] ?? ''; // '', '1', '0'

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 7;
$offset = ($page - 1) * $perPage;

// 2. Lấy dữ liệu Dropdown (MySQLi)
$brands = $conn->query("SELECT id, name FROM brands ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$cats   = $conn->query("SELECT id, name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// 3. Xây dựng câu lệnh WHERE động
$where = [];
$types = "";      // Chuỗi định dạng kiểu dữ liệu cho bind_param (s, i, d...)
$bindParams = []; // Mảng chứa các giá trị cần bind

if ($q !== '') {
    $where[] = "p.name LIKE ?";
    $types .= "s";
    $bindParams[] = "%{$q}%";
}
if ($brandId > 0) {
    $where[] = "p.brand_id = ?";
    $types .= "i";
    $bindParams[] = $brandId;
}
if ($catId > 0) {
    $where[] = "p.category_id = ?";
    $types .= "i";
    $bindParams[] = $catId;
}
if ($status === '1' || $status === '0') {
    $where[] = "p.status = ?";
    $types .= "i";
    $bindParams[] = (int)$status;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// 4. Đếm tổng số bản ghi (Count total)
$sqlCount = "SELECT COUNT(*) FROM products p $whereSql";
$stmtCount = $conn->prepare($sqlCount);

if ($where) {
    $stmtCount->bind_param($types, ...$bindParams);
}
$stmtCount->execute();
$total = (int)$stmtCount->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($total / $perPage));

// 5. Lấy danh sách sản phẩm (List)
$sqlList = "
    SELECT p.id, p.name, p.price, p.quantity, p.status, p.thumbnail, p.created_at,
           b.name AS brand_name, c.name AS category_name
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN categories c ON c.id = p.category_id
    $whereSql
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$stmtList = $conn->prepare($sqlList);

// Thêm tham số cho LIMIT và OFFSET vào cuối danh sách bind
$finalTypes = $types . "ii"; 
$finalParams = array_merge($bindParams, [$perPage, $offset]);

$stmtList->bind_param($finalTypes, ...$finalParams);
$stmtList->execute();
$rows = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);

// Helper build URL giữ lại các filters
function buildQuery(array $extra = []): string {
    $base = $_GET;
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    return '?' . http_build_query($base);
}
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Quản lý sản phẩm</div>
            <div class="admin-sub">Thêm, xóa, sửa và xem thông tin chi tiết sản phẩm</div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/products/create.php">
                <i class="fa-solid fa-plus"></i> <?= $isAdmin ? 'Thêm sản phẩm' : 'Gửi yêu cầu thêm' ?>
            </a>
        </div>
    </div>

    <section class="admin-panel" style="margin-bottom:14px;">
        <div class="admin-panel__head">
            <strong>Bộ lọc</strong>
            <span class="badge badge-info">Tổng: <?= $total ?></span>
        </div>

        <div class="admin-panel__body">
            <form class="admin-filters" method="GET" action="">
                <div class="field" style="min-width:260px; flex:1;">
                    <label>Tìm theo tên</label>
                    <input type="text" name="q" value="<?= e($q) ?>" placeholder="vd: iPhone 15...">
                </div>

                <div class="field">
                    <label>Hãng</label>
                    <select name="brand_id">
                        <option value="0">Tất cả</option>
                        <?php foreach ($brands as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $brandId===(int)$b['id']?'selected':'' ?>>
                            <?= e($b['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Loại</label>
                    <select name="category_id">
                        <option value="0">Tất cả</option>
                        <?php foreach ($cats as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $catId===(int)$c['id']?'selected':'' ?>>
                            <?= e($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Trạng thái</label>
                    <select name="status">
                        <option value="" <?= $status===''?'selected':'' ?>>Tất cả</option>
                        <option value="1" <?= $status==='1'?'selected':'' ?>>Đang hiển thị</option>
                        <option value="0" <?= $status==='0'?'selected':'' ?>>Đang ẩn</option>
                    </select>
                </div>

                <button class="btn btn-primary btn-sm" type="submit">
                    <i class="fa-solid fa-filter"></i> Lọc
                </button>
                <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/list.php">
                    <i class="fa-solid fa-rotate"></i> Reset
                </a>
            </form>
        </div>
    </section>

    <section class="admin-panel">
        <div class="admin-panel__head">
            <strong>Danh sách</strong>
            <span class="badge badge-success">Trang <?= $page ?>/<?= $totalPages ?></span>
        </div>

        <div class="admin-panel__body">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:70px;">Ảnh</th>
                        <th>Sản phẩm</th>
                        <th>Hãng / Loại</th>
                        <th style="width:140px;">Giá</th>
                        <th style="width:90px;">Tồn</th>
                        <th style="width:120px;">Trạng thái</th>
                        <th style="width:210px;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" style="color:var(--text-secondary);">Không có dữ liệu.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?php
                    $img = $r['thumbnail']
                      ? (UPLOAD_URL . e($r['thumbnail']))
                      : (BASE_URL . '/assets/images/no-image.png');
                  ?>
                            <img src="<?= $img ?>" alt="<?= e($r['name']) ?>"
                                style="width:56px;height:56px;object-fit:contain;border-radius:12px;border:1px solid rgba(0,0,0,.06);background:#fff;">
                        </td>
                        <td>
                            <div style="font-weight:800;"><?= e($r['name']) ?></div>
                            <div style="font-size:12px;opacity:.7;">#<?= (int)$r['id'] ?> •
                                <?= e(date('d/m/Y', strtotime($r['created_at']))) ?></div>
                        </td>
                        <td>
                            <div><?= e($r['brand_name'] ?? '—') ?></div>
                            <div style="font-size:12px;opacity:.7;"><?= e($r['category_name'] ?? '—') ?></div>
                        </td>
                        <td style="font-weight:800;color:var(--danger-color);">
                            <?= number_format((float)$r['price'], 0, ',', '.') ?>đ
                        </td>
                        <td><?= (int)$r['quantity'] ?></td>
                        <td>
                            <?php if ((int)$r['status'] === 1): ?>
                            <span class="badge badge-success">Hiển thị</span>
                            <?php else: ?>
                            <span class="badge badge-danger">Ẩn</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a class="btn btn-outline btn-sm"
                                    href="<?= BASE_URL ?>/admin/products/show.php?id=<?= (int)$r['id'] ?>">
                                    <i class="fa-regular fa-eye"></i>
                                </a>
                                <a class="btn btn-primary btn-sm"
                                    href="<?= BASE_URL ?>/admin/products/edit.php?id=<?= (int)$r['id'] ?>">
                                    <i class="fa-regular fa-pen-to-square"></i>
                                </a>

                                <button type="button" class="btn btn-danger btn-sm js-open-delete"
                                    data-id="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px; flex-wrap:wrap;">
                <?php
            $prev = max(1, $page - 1);
            $next = min($totalPages, $page + 1);
          ?>
                <a class="btn btn-outline btn-sm" href="<?= buildQuery(['page' => $prev]) ?>">← Trước</a>

                <?php
            // show max 7 pages around current
            $start = max(1, $page - 3);
            $end = min($totalPages, $page + 3);
            for ($i=$start; $i<=$end; $i++):
          ?>
                <a class="btn btn-sm <?= $i===$page ? 'btn-primary' : 'btn-outline' ?>"
                    href="<?= buildQuery(['page' => $i]) ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>

                <a class="btn btn-outline btn-sm" href="<?= buildQuery(['page' => $next]) ?>">Sau →</a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Delete Confirm Modal -->
    <div class="ps-modal" id="deleteModal" aria-hidden="true">
        <div class="ps-modal__overlay" data-close="1"></div>

        <div class="ps-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
            <h3 id="deleteModalTitle" class="ps-modal__title">
                <?= $isAdmin ? 'Xác nhận xóa sản phẩm' : 'Gửi yêu cầu xóa sản phẩm' ?></h3>
            <div class="ps-modal__desc">
                <?= $isAdmin
                  ? 'Bạn có chắc muốn xóa '
                  : 'Bạn có chắc muốn gửi yêu cầu xóa '
                  ?> <strong id="dmProductName">—</strong> không?
            </div>

            <div class="ps-modal__actions">
                <button type="button" class="btn btn-outline btn-sm" data-close="1">Hủy</button>
                <form id="deleteForm" method="POST" action="<?= BASE_URL ?>/admin/products/delete.php"
                    style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                    <input type="hidden" name="id" id="dmProductId" value="">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fa-regular fa-trash-can"></i></i> <?= $isStaff ? 'Gửi phê duyệt' : 'Xóa' ?>
                    </button>
                </form>
            </div>
        </div> 
    </div>

    <script>
    (function() {
        const modal = document.getElementById('deleteModal');
        const nameEl = document.getElementById('dmProductName');
        const idEl = document.getElementById('dmProductId');

        function openModal() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-open-delete');
            if (!btn) return;

            const id = btn.dataset.id || '';
            const name = btn.dataset.name || 'sản phẩm';

            idEl.value = id;
            nameEl.textContent = name;

            openModal();
        });

        modal?.addEventListener('click', (e) => {
            if (e.target.closest('[data-close="1"]')) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
        });
    })();
    </script>
</main>