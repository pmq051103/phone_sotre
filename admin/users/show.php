<?php
$pageTitle = 'Chi tiết tài khoản - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$errors = [];

function fmtMoney($n){ return number_format((float)$n, 0, '.', ',') . 'đ'; }

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

function userStatusBadge(int $st): string {
    return $st === 1
        ? '<span class="badge badge-success">Đang hoạt động</span>'
        : '<span class="badge badge-gray">Đã khóa</span>';
}

$roleMap = ['user' => 'Người dùng', 'admin' => 'Quản trị'];
$allowedRoles = array_keys($roleMap);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID user không hợp lệ.');
    redirect(BASE_URL . '/admin/users/list.php');
}

/** 1. Handle POST: update role / lock-unlock (MySQLi) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';

        // Kiểm tra user tồn tại
        $stCur = $conn->prepare("SELECT id, role, status FROM users WHERE id = ?");
        $stCur->bind_param("i", $id);
        $stCur->execute();
        $curUser = $stCur->get_result()->fetch_assoc();

        if (!$curUser) {
            $errors[] = 'User không tồn tại.';
        } else {
            $myId = (int)($_SESSION['user_id'] ?? 0);

            // Đổi quyền (Role)
            if ($action === 'set_role') {
                $newRole = $_POST['role'] ?? '';
                if (!in_array($newRole, $allowedRoles, true)) $errors[] = 'Quyền không hợp lệ.';

                if ($myId > 0 && $id === $myId && $newRole !== 'admin') {
                    $errors[] = 'Bạn không thể tự hạ quyền của chính mình.';
                }

                if (!$errors) {
                    try {
                        $up = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                        $up->bind_param("si", $newRole, $id);
                        $up->execute();
                        setFlash('success', 'Đã cập nhật quyền.');
                        redirect(BASE_URL . '/admin/users/show.php' . buildQuery());
                        exit;
                    } catch (Exception $e) {
                        $errors[] = 'Có lỗi khi cập nhật quyền.';
                    }
                }
            }

            // Khóa/Mở khóa (Status)
            elseif ($action === 'set_status') {
                $to = (int)($_POST['to'] ?? -1);
                if (!in_array($to, [0, 1], true)) $errors[] = 'Trạng thái không hợp lệ.';

                if ($myId > 0 && $id === $myId && $to === 0) {
                    $errors[] = 'Bạn không thể tự khóa tài khoản của chính mình.';
                }

                if (!$errors) {
                    try {
                        $up = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
                        $up->bind_param("ii", $to, $id);
                        $up->execute();
                        setFlash('success', $to === 1 ? 'Đã mở khóa tài khoản.' : 'Đã khóa tài khoản.');
                        redirect(BASE_URL . '/admin/users/show.php' . buildQuery());
                        exit;
                    } catch (Exception $e) {
                        $errors[] = 'Có lỗi khi cập nhật trạng thái.';
                    }
                }
            } else {
                $errors[] = 'Action không hợp lệ.';
            }
        }
    }
}

/** 2. Load User Info */
$stU = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stU->bind_param("i", $id);
$stU->execute();
$user = $stU->get_result()->fetch_assoc();

if (!$user) {
    setFlash('danger', 'User không tồn tại.');
    redirect(BASE_URL . '/admin/users/list.php');
}

/** 3. Load Orders with Pagination (MySQLi) */
$stt = trim($_GET['status'] ?? ''); 
$allowedOrderStatus = ['pending','confirmed','shipping','completed','cancelled'];

$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ["o.user_id = ?"];
$types = "i";
$bindParams = [$id];

if ($stt !== '' && in_array($stt, $allowedOrderStatus, true)) {
    $where[] = "o.status = ?";
    $types .= "s";
    $bindParams[] = $stt;
}

$whereSql = "WHERE " . implode(" AND ", $where);

// Total orders count
$stTotal = $conn->prepare("SELECT COUNT(*) FROM orders o {$whereSql}");
$stTotal->bind_param($types, ...$bindParams);
$stTotal->execute();
$totalRows = (int)$stTotal->get_result()->fetch_row()[0];

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

// List orders fetch
$sqlOrders = "
    SELECT o.*, p.method AS pay_method, p.status AS pay_status
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.id
    {$whereSql}
    ORDER BY o.id DESC
    LIMIT ? OFFSET ?
";
$stO = $conn->prepare($sqlOrders);
$finalTypes = $types . "ii";
$finalParams = array_merge($bindParams, [$perPage, $offset]);
$stO->bind_param($finalTypes, ...$finalParams);
$stO->execute();
$orders = $stO->get_result()->fetch_all(MYSQLI_ASSOC);

/** 4. Mapping Helpers */
function orderBadge($st){
    $map = [
        'pending'   => ['badge badge-gray', 'Chờ xác nhận'],
        'confirmed' => ['badge badge-info', 'Đã xác nhận'],
        'shipping'  => ['badge badge-warning', 'Đang giao'],
        'completed' => ['badge badge-success', 'Hoàn thành'],
        'cancelled' => ['badge badge-gray', 'Đã hủy'],
    ];
    $cls = $map[$st][0] ?? 'badge badge-gray';
    $txt = $map[$st][1] ?? $st;
    return '<span class="'.$cls.'">'.$txt.'</span>';
}
$payMap = [
    'unpaid' => 'Chưa thanh toán',
    'paid'   => 'Đã thanh toán',
    'failed' => 'Thất bại',
];
$methodMap = ['cod' => 'COD', 'banking' => 'Chuyển khoản'];
?>

<main class="admin-main">
  <div class="admin-topbar">
    <div>
      <div class="admin-title">Chi tiết user: <?= e($user['full_name']) ?></div>
      <div class="admin-sub">ID #<?= (int)$user['id'] ?> • <?= e($user['email']) ?></div>
    </div>
    <div class="admin-actions">
      <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/users/list.php">← Quay lại</a>
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
    .u-grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:12px; }
    .u-card{ border:1px solid rgba(0,0,0,.06); border-radius:12px; padding:12px; background:rgba(255,255,255,.6); }
    .u-label{ font-weight:800; opacity:.75; font-size:13px; }
    .u-value{ margin-top:6px; font-weight:900; }
    @media(max-width:1024px){ .u-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media(max-width:640px){ .u-grid{ grid-template-columns: 1fr; } }
  </style>

  <!-- User info -->
  <section class="admin-panel">
    <div class="admin-panel__head">
      <strong>Thông tin tài khoản</strong>
      <span><?= userStatusBadge((int)$user['status']) ?></span>
    </div>
    <div class="admin-panel__body">
      <div class="u-grid">
        <div class="u-card">
          <div class="u-label">Họ tên</div>
          <div class="u-value"><?= e($user['full_name']) ?></div>
        </div>
        <div class="u-card">
          <div class="u-label">Email</div>
          <div class="u-value"><?= e($user['email']) ?></div>
        </div>
        <div class="u-card">
          <div class="u-label">SĐT</div>
          <div class="u-value"><?= e($user['phone'] ?? '—') ?></div>
        </div>

        <div class="u-card">
          <div class="u-label">Địa chỉ</div>
          <div class="u-value" style="font-weight:800;"><?= $user['address'] ? nl2br(e($user['address'])) : '—' ?></div>
        </div>

        <div class="u-card">
          <div class="u-label">Quyền hiện tại</div>
          <div class="u-value"><?= e($roleMap[$user['role']] ?? $user['role']) ?></div>

          <!-- Update role -->
          <form method="POST" style="margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
            <input type="hidden" name="action" value="set_role">
            <select class="input" name="role" style="height:38px; min-width:180px;">
              <?php foreach ($roleMap as $k => $lb): ?>
                <option value="<?= e($k) ?>" <?= $user['role']===$k?'selected':'' ?>><?= e($lb) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit">
              <i class="fa-solid fa-save"></i> Cập nhật quyền
            </button>
          </form>
        </div>

        <div class="u-card">
          <div class="u-label">Trạng thái</div>
          <div class="u-value" style="font-weight:800;">
            <?= (int)$user['status']===1 ? 'Đang hoạt động' : 'Đã khóa' ?>
          </div>

          <?php if(isAdmin()): ?>
          <!-- lock/unlock -->
          <?php if ((int)$user['status'] === 1): ?>
            <form method="POST" style="margin-top:10px;">
              <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="to" value="0">
              <button class="btn btn-outline btn-sm"
                style="border-color: rgba(220,38,38,.35); color: var(--danger-color);"
                type="submit"
                onclick="return confirm('Khóa tài khoản user này?')">
                <i class="fa-solid fa-lock"></i> Khóa tài khoản
              </button>
            </form>
          <?php else: ?>
            <form method="POST" style="margin-top:10px;">
              <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="to" value="1">
              <button class="btn btn-outline btn-sm" type="submit">
                <i class="fa-solid fa-unlock"></i> Mở khóa
              </button>
            </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="u-card">
          <div class="u-label">Ngày tạo</div>
          <div class="u-value"><?= e(date('d/m/Y H:i', strtotime($user['created_at']))) ?></div>
        </div>
      </div>
    </div>
  </section>

  <!-- Orders -->
  <section class="admin-panel" style="margin-top:12px;">
    <div class="admin-panel__head">
      <strong>Đơn hàng của user</strong>
      <span class="badge badge-info">Tổng: <?= (int)$totalRows ?></span>
    </div>

    <div class="admin-panel__body">
      <!-- Filter orders -->
      <form method="GET" class="admin-filters" style="margin-bottom:12px;">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="admin-filter-row">
          <div class="filter-item">
            <label>Trạng thái đơn</label>
            <select class="input" name="status">
              <option value="" <?= $stt===''?'selected':'' ?>>Tất cả</option>
              <option value="pending"   <?= $stt==='pending'?'selected':'' ?>>Chờ xác nhận</option>
              <option value="confirmed" <?= $stt==='confirmed'?'selected':'' ?>>Đã xác nhận</option>
              <option value="shipping"  <?= $stt==='shipping'?'selected':'' ?>>Đang giao</option>
              <option value="completed" <?= $stt==='completed'?'selected':'' ?>>Hoàn thành</option>
              <option value="cancelled" <?= $stt==='cancelled'?'selected':'' ?>>Đã hủy</option>
            </select>
          </div>

          <div class="filter-actions">
            <button class="btn btn-outline btn-sm" type="submit">
              <i class="fa-solid fa-filter"></i> Lọc
            </button>
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/users/show.php?id=<?= (int)$id ?>">
              <i class="fa-solid fa-rotate-left"></i> Reset
            </a>
          </div>
        </div>
      </form>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th style="width:90px;">Mã đơn</th>
              <th style="width:180px;">Ngày đặt</th>
              <th style="width:160px;">Thanh toán</th>
              <th style="width:160px;">Trạng thái</th>
              <th style="width:180px;">Tổng thanh toán</th>
              <th>Thông tin nhận</th>
              <th style="width:140px;">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr>
                <td colspan="7" style="text-align:center; padding:18px; color:var(--text-secondary);">
                  Chưa có đơn hàng
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($orders as $o): ?>
                <tr>
                  <td>#<?= (int)$o['id'] ?></td>
                  <td><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>

                  <td>
                    <div style="font-weight:800;"><?= e($methodMap[$o['pay_method']] ?? '—') ?></div>
                    <div style="opacity:.75; font-size:12px;"><?= e($payMap[$o['pay_status']] ?? '—') ?></div>
                  </td>

                  <td><?= orderBadge($o['status']) ?></td>

                  <td style="font-weight:900;"><?= fmtMoney($o['final_amount']) ?></td>

                  <td>
                    <div style="font-weight:800;"><?= e($o['receiver_name']) ?> • <?= e($o['receiver_phone']) ?></div>
                    <div style="opacity:.75; font-size:12px;"><?= e($o['receiver_address']) ?></div>
                  </td>

                  <td>
                    <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/orders/show.php?id=<?= (int)$o['id'] ?>">
                      <i class="fa-regular fa-eye"></i> Xem
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination orders -->
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
