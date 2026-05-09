<?php
$pageTitle = 'Quản lý tài khoản - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
requireRole(['admin', 'staff']);
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

function statusBadgeUser(int $st): string {
    return $st === 1
        ? '<span class="badge badge-success">Đang hoạt động</span>'
        : '<span class="badge badge-gray">Đã khóa</span>';
}

$roleMap = [
    'user'  => 'Người dùng',
    'staff' => 'Nhân viên',
    'admin' => 'Quản trị',
];

/** Handle POST: lock/unlock + update role (MySQLi) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';

        // Chỉ admin mới được thao tác (staff chỉ xem)
        if (!$isAdmin) {
            $errors[] = 'Bạn không có quyền thao tác.';
        }

        if (!$errors) {
            // ===== 1) Toggle status (Khóa/Mở khóa) =====
            if ($action === 'toggle_status') {
                $uid = (int)($_POST['id'] ?? 0);
                $to  = (int)($_POST['to'] ?? -1); // 0/1

                if ($uid <= 0) $errors[] = 'ID user không hợp lệ.';
                if (!in_array($to, [0, 1], true)) $errors[] = 'Trạng thái không hợp lệ.';

                // Chặn tự khóa mình
                $myId = (int)($_SESSION['user_id'] ?? 0);
                if ($myId > 0 && $uid === $myId && $to === 0) {
                    $errors[] = 'Bạn không thể tự khóa tài khoản của chính mình.';
                }

                if (!$errors) {
                    try {
                        $chk = $conn->prepare("SELECT id FROM users WHERE id = ?");
                        $chk->bind_param("i", $uid);
                        $chk->execute();
                        if (!$chk->get_result()->fetch_assoc()) {
                            $errors[] = 'User không tồn tại.';
                        } else {
                            $up = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
                            $up->bind_param("ii", $to, $uid);
                            $up->execute();

                            setFlash('success', $to === 1 ? 'Đã mở khóa tài khoản.' : 'Đã khóa tài khoản.');
                            redirect(BASE_URL . '/admin/users/list.php' . buildQuery());
                            exit;
                        }
                    } catch (Exception $e) {
                        $errors[] = 'Có lỗi khi cập nhật. Vui lòng thử lại.';
                    }
                }
            }

            // ===== 2) Update role (Đổi quyền) =====
            elseif ($action === 'update_role') {
                $uid = (int)($_POST['id'] ?? 0);
                $newRole = trim($_POST['role_new'] ?? '');

                if ($uid <= 0) $errors[] = 'ID user không hợp lệ.';
                if (!in_array($newRole, ['user', 'staff', 'admin'], true)) $errors[] = 'Role không hợp lệ.';

                // Chặn tự đổi role mình
                $myId = (int)($_SESSION['user_id'] ?? 0);
                if ($myId > 0 && $uid === $myId) {
                    $errors[] = 'Bạn không thể tự đổi quyền của chính mình.';
                }

                if (!$errors) {
                    try {
                        $chk = $conn->prepare("SELECT id FROM users WHERE id = ?");
                        $chk->bind_param("i", $uid);
                        $chk->execute();
                        if (!$chk->get_result()->fetch_assoc()) {
                            $errors[] = 'User không tồn tại.';
                        } else {
                            $up = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                            $up->bind_param("si", $newRole, $uid);
                            $up->execute();

                            setFlash('success', 'Đã cập nhật quyền.');
                            redirect(BASE_URL . '/admin/users/list.php' . buildQuery());
                            exit;
                        }
                    } catch (Exception $e) {
                        $errors[] = 'Có lỗi khi cập nhật quyền.';
                    }
                }
            } else {
                $errors[] = 'Action không hợp lệ.';
            }
        }
    }
}

/** Filters */
$keyword = trim($_GET['q'] ?? '');
$role    = trim($_GET['role'] ?? '');
$status  = intOrNull($_GET['status'] ?? null);

/** Pagination */
$perPage = 7;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = [];
$types = "";
$bindParams = [];

if ($keyword !== '') {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $types .= "sss";
    $searchQ = "%{$keyword}%";
    array_push($bindParams, $searchQ, $searchQ, $searchQ);
}

if ($role !== '' && in_array($role, ['user', 'staff', 'admin'], true)) {
    $where[] = "role = ?";
    $types .= "s";
    $bindParams[] = $role;
}

if ($status !== null && in_array($status, [0, 1], true)) {
    $where[] = "status = ?";
    $types .= "i";
    $bindParams[] = $status;
}

$baseFrom = "FROM users";
$baseWhere = $where ? (" WHERE " . implode(" AND ", $where)) : "";

/** 1. Total rows count */
$stTotal = $conn->prepare("SELECT COUNT(*) {$baseFrom} {$baseWhere}");
if ($where) {
    $stTotal->bind_param($types, ...$bindParams);
}
$stTotal->execute();
$totalRows = (int)$stTotal->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

/** 2. Fetch list */
$sql = "SELECT * {$baseFrom} {$baseWhere} ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

$finalTypes = $types . "ii";
$finalParams = array_merge($bindParams, [$perPage, $offset]);

$stmt->bind_param($finalTypes, ...$finalParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Quản lý tài khoản</div>
            <div class="admin-sub">Khóa / mở khóa user • Tổng: <?= (int)$totalRows ?></div>
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
                        <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="Tên / email / SĐT...">
                    </div>

                    <div class="filter-item">
                        <label>Quyền</label>
                        <select class="input" name="role">
                            <option value="" <?= $role===''?'selected':'' ?>>Tất cả</option>
                            <option value="user" <?= $role==='user'?'selected':'' ?>>Người dùng</option>
                            <option value="staff" <?= $role==='staff'?'selected':'' ?>>Nhân viên</option>
                            <option value="admin" <?= $role==='admin'?'selected':'' ?>>Quản trị</option>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Trạng thái</label>
                        <select class="input" name="status">
                            <option value="" <?= $status===null?'selected':'' ?>>Tất cả</option>
                            <option value="1" <?= $status===1?'selected':'' ?>>Đang hoạt động</option>
                            <option value="0" <?= $status===0?'selected':'' ?>>Đã khóa</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button class="btn btn-outline btn-sm" type="submit">
                            <i class="fa-solid fa-filter"></i> Lọc
                        </button>
                        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/users/list.php">
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
            <strong>Danh sách user</strong>
            <span class="badge badge-success">Trang <?= (int)$page ?>/<?= (int)$totalPages ?></span>
        </div>

        <div class="admin-panel__body">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">ID</th>
                            <th>Họ tên</th>
                            <th style="width:240px;">Email</th>
                            <th style="width:140px;">SĐT</th>
                            <th style="width:140px;">Quyền</th>
                            <th style="width:160px;">Trạng thái</th>
                            <th style="width:190px;">Ngày tạo</th>
                            <th style="width:220px;">Hành động</th>
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
                        <?php foreach ($rows as $u): ?>
                        <tr>
                            <td>#<?= (int)$u['id'] ?></td>
                            <td style="font-weight:900;"><?= e($u['full_name']) ?></td>
                            <td><?= e($u['email']) ?></td>
                            <td><?= e($u['phone'] ?? '—') ?></td>
                            <td><?= e($roleMap[$u['role']] ?? $u['role']) ?></td>
                            <td><?= statusBadgeUser((int)$u['status']) ?></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($u['created_at']))) ?></td>
                            <td style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <a class="btn btn-outline btn-sm"
                                    href="<?= BASE_URL ?>/admin/users/show.php?id=<?= (int)$u['id'] ?>">
                                    <i class="fa-regular fa-eye"></i>
                                </a>
                                <?php if ($isAdmin): ?>
                                <button type="button" class="btn btn-outline btn-sm js-role"
                                    data-id="<?= (int)$u['id'] ?>" data-name="<?= e($u['full_name']) ?>"
                                    data-role="<?= e($u['role']) ?>">
                                    <i class="fa-solid fa-user-gear"></i>
                                </button>
                                <?php endif; ?>

                                <?php if ($isAdmin): ?>
                                <?php if ((int)$u['status'] === 1): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="to" value="0">
                                    <button class="btn btn-outline btn-sm"
                                        style="border-color: rgba(220,38,38,.35); color: var(--danger-color);"
                                        type="submit"
                                        onclick="return confirm('Khóa tài khoản <?= e($u['full_name']) ?>?')">
                                        <i class="fa-solid fa-lock"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="to" value="1">
                                    <button class="btn btn-outline btn-sm" type="submit">
                                        <i class="fa-solid fa-unlock"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
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

    <?php if ($isAdmin): ?>
<div class="ps-modal" id="roleModal" aria-hidden="true">
  <div class="ps-modal__overlay" data-close="1"></div>

  <div class="ps-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="roleModalTitle">
    <button type="button" class="ps-modal__close" data-close="1" aria-label="Đóng">×</button>

    <h3 id="roleModalTitle" class="ps-modal__title">Cập nhật quyền</h3>

    <div class="ps-modal__body">
      <div style="margin-bottom:10px;">
        User: <strong id="rmName">—</strong>
      </div>

      <form method="POST" id="roleForm" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
        <input type="hidden" name="action" value="update_role">
        <input type="hidden" name="id" id="rmId" value="">

        <div class="field" style="margin-bottom:14px;">
          <label style="font-weight:800;">Quyền</label>
          <select class="input" name="role_new" id="rmRole" style="width:100%; margin-top:6px;">
            <option value="user">Người dùng</option>
            <option value="staff">Nhân viên</option>
            <option value="admin">Quản trị</option>
          </select>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-outline btn-sm" data-close="1">Hủy</button>
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-save"></i> Lưu
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('roleModal');
  const rmName = document.getElementById('rmName');
  const rmId = document.getElementById('rmId');
  const rmRole = document.getElementById('rmRole');

  function open(){ modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function close(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }

  document.querySelectorAll('.js-role').forEach(btn => {
    btn.addEventListener('click', () => {
      rmName.textContent = btn.dataset.name || '—';
      rmId.value = btn.dataset.id || '';
      rmRole.value = btn.dataset.role || 'user';
      open();
    });
  });

  modal?.addEventListener('click', (e) => {
    if (e.target.closest('[data-close="1"]')) close();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
  });
})();
</script>
<?php endif; ?>

</main>