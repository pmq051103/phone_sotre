<?php
$pageTitle = 'Quản lý danh mục - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

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

/** Handle POST (add / edit / delete) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $status = (int)($_POST['status'] ?? 1);
        if (!in_array($status, [0, 1], true)) $status = 1;

        try {
            if ($action === 'add') {
                if ($name === '') $errors[] = 'Vui lòng nhập tên danh mục.';
                if (!$errors) {
                    $st = $conn->prepare("INSERT INTO categories (name, status) VALUES (?, ?)");
                    $st->bind_param("si", $name, $status);
                    $st->execute();
                    setFlash('success', 'Đã thêm danh mục.');
                    redirect(BASE_URL . '/admin/categories/list.php');
                }
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $errors[] = 'ID danh mục không hợp lệ.';
                if ($name === '') $errors[] = 'Vui lòng nhập tên danh mục.';
                if (!$errors) {
                    $chk = $conn->prepare("SELECT id FROM categories WHERE id=?");
                    $chk->bind_param("i", $id);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows === 0) {
                        $errors[] = 'Danh mục không tồn tại.';
                    } else {
                        $st = $conn->prepare("UPDATE categories SET name=?, status=? WHERE id=?");
                        $st->bind_param("sii", $name, $status, $id);
                        $st->execute();
                        setFlash('success', 'Đã cập nhật danh mục.');
                        redirect(BASE_URL . '/admin/categories/list.php');
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) $errors[] = 'ID danh mục không hợp lệ.';
                if (!$errors) {
                    // Kiểm tra sản phẩm thuộc danh mục
                    $c = $conn->prepare("SELECT id FROM products WHERE category_id=?");
                    $c->bind_param("i", $id);
                    $c->execute();
                    $c->store_result();
                    $cnt = $c->num_rows;
                    
                    if ($cnt > 0) {
                        $errors[] = "Không thể xóa vì đang có {$cnt} sản phẩm thuộc danh mục này.";
                    } else {
                        $del = $conn->prepare("DELETE FROM categories WHERE id=?");
                        $del->bind_param("i", $id);
                        $del->execute();
                        setFlash('success', 'Đã xóa danh mục.');
                        redirect(BASE_URL . '/admin/categories/list.php');
                    }
                }
            }
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $conn->errno == 1062) {
                $errors[] = 'Tên danh mục đã tồn tại.';
            } else {
                $errors[] = 'Có lỗi khi lưu dữ liệu.';
            }
        }
    }
}

/** Filters & Pagination */
$keyword = trim($_GET['q'] ?? '');
$status = intOrNull($_GET['status'] ?? null);
$perPage = 7;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = [];
$types = "";
$values = [];

if ($keyword !== '') {
    $where[] = "name LIKE ?";
    $values[] = "%{$keyword}%";
    $types .= "s";
}
if ($status !== null) {
    $where[] = "status = ?";
    $values[] = $status;
    $types .= "i";
}

$baseWhere = $where ? (" WHERE " . implode(" AND ", $where)) : "";

// Lấy tổng số dòng để phân trang
$stTotal = $conn->prepare("SELECT COUNT(*) FROM categories" . $baseWhere);
if ($where) {
    $stTotal->bind_param($types, ...$values);
}
$stTotal->execute();
$resTotal = $stTotal->get_result();
$totalRows = $resTotal->fetch_row()[0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

// Lấy danh sách danh mục
$sql = "SELECT * FROM categories $baseWhere ORDER BY id DESC LIMIT ? OFFSET ?";
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
            <div class="admin-title">Quản lý danh mục</div>
            <div class="admin-sub">Thêm / sửa nhanh bằng modal • Tổng: <?= (int)$totalRows ?></div>
        </div>
        <div class="admin-actions">
            <button type="button" class="btn btn-primary btn-sm" id="btnOpenAdd">
                <i class="fa-solid fa-plus"></i> Thêm danh mục
            </button>
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
            <span class="badge badge-info">Tổng: <?= $totalRows ?></span>
        </div>

        <div class="admin-panel__body">
            <form method="GET" class="admin-filters">
                <div class="admin-filter-row">
                    <div class="filter-item">
                        <label>Tìm theo tên</label>
                        <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="vd: Flaship, Giá rẻ,...">
                    </div>

                    <div class="filter-item">
                        <label>Trạng thái</label>
                        <select class="input" name="status">
                            <option value="" <?= $status===null?'selected':'' ?>>Tất cả</option>
                            <option value="1" <?= $status===1?'selected':'' ?>>Hiển thị</option>
                            <option value="0" <?= $status===0?'selected':'' ?>>Ẩn</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button class="btn btn-outline btn-sm" type="submit">
                            <i class="fa-solid fa-filter"></i> Lọc
                        </button>
                        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/categories/list.php">
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
            <strong>Danh sách danh mục</strong>
            <span class="badge badge-success">Trang <?= (int)$page ?>/<?= (int)$totalPages ?></span>
        </div>

        <div class="admin-panel__body">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">ID</th>
                            <th>Tên danh mục</th>
                            <th style="width:140px;">Trạng thái</th>
                            <th style="width:220px;">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:18px; color:var(--text-secondary);">
                                Không có dữ liệu
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td style="font-weight:800;"><?= e($r['name']) ?></td>
                            <td>
                                <?php if ((int)$r['status'] === 1): ?>
                                <span class="badge badge-success">Hiển thị</span>
                                <?php else: ?>
                                <span class="badge badge-gray">Ẩn</span>
                                <?php endif; ?>
                            </td>
                            <td style="display:flex; gap:8px; align-items:center;">
                                <button type="button" class="btn btn-outline btn-sm js-edit"
                                    data-id="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>"
                                    data-status="<?= (int)$r['status'] ?>">
                                    <i class="fa-regular fa-pen-to-square"></i> Sửa
                                </button>

                                <button type="button" class="btn btn-outline btn-sm js-del"
                                    data-id="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>"
                                    style="border-color: rgba(220,38,38,.35); color: var(--danger-color);">
                                    <i class="fa-regular fa-trash-can"></i> Xóa
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination (chỉ hiện nếu > 1 trang) -->
            <?php if ($totalPages > 1): ?>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px; flex-wrap:wrap;">
                <?php
                  $isFirst = ($page <= 1);
                  $isLast  = ($page >= $totalPages);

                  $prev = max(1, $page - 1);
                  $next = min($totalPages, $page + 1);
                ?>

                <!-- Prev -->
                <a class="btn btn-outline btn-sm <?= $isFirst ? 'disabled' : '' ?>"
                    <?= $isFirst ? 'aria-disabled="true"' : 'href="'.buildQuery(['page' => $prev]).'"' ?>>
                    ← Trước
                </a>

                <?php
                  $start = max(1, $page - 3);
                  $end   = min($totalPages, $page + 3);
                  for ($i = $start; $i <= $end; $i++):
                ?>
                <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>"
                    href="<?= buildQuery(['page' => $i]) ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>

                <!-- Next -->
                <a class="btn btn-outline btn-sm <?= $isLast ? 'disabled' : '' ?>"
                    <?= $isLast ? 'aria-disabled="true"' : 'href="'.buildQuery(['page' => $next]).'"' ?>>
                    Sau →
                </a>
            </div>
            <?php endif; ?>

        </div>
    </section>

    <!-- Modal Add/Edit -->
    <div class="ps-modal" id="catModal" aria-hidden="true">
        <div class="ps-modal__overlay" data-close="1"></div>
        <div class="ps-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="catModalTitle">
            <button type="button" class="ps-modal__close" data-close="1" aria-label="Đóng">×</button>
            <h3 id="catModalTitle" class="ps-modal__title">Thêm danh mục</h3>
            <div class="ps-modal__body">
                <form method="POST" id="catForm">
                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                    <input type="hidden" name="action" id="cmAction" value="add">
                    <input type="hidden" name="id" id="cmId" value="">
                    <div class="admin-grid-2">
                        <div>
                            <label style="font-weight:800;">Tên danh mục *</label>
                            <input class="input" style="width:100%;margin-top:6px;" name="name" id="cmName" required>
                        </div>
                        <div>
                            <label style="font-weight:800;">Trạng thái</label>
                            <select class="input" style="width:100%;margin-top:6px;" name="status" id="cmStatus">
                                <option value="1">Hiển thị</option>
                                <option value="0">Ẩn</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                        <button type="button" class="btn btn-outline btn-sm" data-close="1">Hủy</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="cmSubmit">
                            <i class="fa-solid fa-save"></i> Lưu
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Delete confirm -->
    <div class="ps-modal" id="deleteModal" aria-hidden="true">
        <div class="ps-modal__overlay" data-close="1"></div>
        <div class="ps-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
            <button type="button" class="ps-modal__close" data-close="1" aria-label="Đóng">×</button>
            <h3 id="deleteModalTitle" class="ps-modal__title">Xác nhận xóa</h3>
            <div class="ps-modal__body">
                <p style="margin:0 0 10px 0;">
                    Bạn chắc chắn muốn xóa danh mục:
                    <strong id="dmCatName">—</strong> ?
                </p>
                <p style="margin:0; opacity:.75; font-size:13px;">
                    Lưu ý: Nếu danh mục đang có sản phẩm thì sẽ không cho xóa.
                </p>

                <form method="POST" id="deleteForm" style="margin-top:14px;">
                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="dmCatId" value="">

                    <div style="display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn btn-outline btn-sm" data-close="1">Hủy</button>
                        <button type="submit" class="btn btn-primary btn-sm"
                            style="background: var(--danger-color); border-color: var(--danger-color);">
                            <i class="fa-regular fa-trash-can"></i> Xóa
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</main>

<script>
(function() {
    // Category modal (add/edit)
    const catModal = document.getElementById('catModal');
    const cmTitle = document.getElementById('catModalTitle');
    const btnAdd = document.getElementById('btnOpenAdd');

    const cmAction = document.getElementById('cmAction');
    const cmId = document.getElementById('cmId');
    const cmName = document.getElementById('cmName');
    const cmStatus = document.getElementById('cmStatus');
    const cmSubmit = document.getElementById('cmSubmit');

    function open(m) {
        m.classList.add('is-open');
        m.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function close(m) {
        m.classList.remove('is-open');
        m.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function resetToAdd() {
        cmTitle.textContent = 'Thêm danh mục';
        cmAction.value = 'add';
        cmId.value = '';
        cmName.value = '';
        cmStatus.value = '1';
        cmSubmit.innerHTML = '<i class="fa-solid fa-save"></i> Lưu';
    }

    function setToEdit(data) {
        cmTitle.textContent = 'Sửa danh mục';
        cmAction.value = 'edit';
        cmId.value = data.id;
        cmName.value = data.name || '';
        cmStatus.value = String(data.status ?? 1);
        cmSubmit.innerHTML = '<i class="fa-solid fa-save"></i> Cập nhật';
    }

    btnAdd?.addEventListener('click', () => {
        resetToAdd();
        open(catModal);
        setTimeout(() => cmName?.focus(), 50);
    });

    document.querySelectorAll('.js-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            setToEdit({
                id: btn.dataset.id,
                name: btn.dataset.name,
                status: btn.dataset.status
            });
            open(catModal);
            setTimeout(() => cmName?.focus(), 50);
        });
    });

    catModal?.addEventListener('click', (e) => {
        if (e.target.closest('[data-close="1"]')) close(catModal);
    });

    // Delete modal
    const delModal = document.getElementById('deleteModal');
    const dmCatName = document.getElementById('dmCatName');
    const dmCatId = document.getElementById('dmCatId');

    document.querySelectorAll('.js-del').forEach(btn => {
        btn.addEventListener('click', () => {
            dmCatName.textContent = btn.dataset.name || '—';
            dmCatId.value = btn.dataset.id || '';
            open(delModal);
        });
    });

    delModal?.addEventListener('click', (e) => {
        if (e.target.closest('[data-close="1"]')) close(delModal);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (catModal?.classList.contains('is-open')) close(catModal);
        if (delModal?.classList.contains('is-open')) close(delModal);
    });
})();
</script>
