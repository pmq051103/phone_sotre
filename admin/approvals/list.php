<?php
$pageTitle = 'Phê duyệt - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

/** ===== map ===== */
$entityMap = [
  'products' => 'Sản phẩm',
  'discount_codes' => 'Mã giảm giá',
];

$actionMap = [
  'create' => 'Thêm mới',
  'update' => 'Cập nhật',
  'delete' => 'Xóa',
];

$statusMap = [
  'pending' => ['text' => 'Chờ duyệt', 'badge' => 'badge-info'],
  'approved' => ['text' => 'Đã duyệt', 'badge' => 'badge-success'],
  'rejected' => ['text' => 'Từ chối', 'badge' => 'badge-danger'],
];

/** ===== filters ===== */
$q = trim($_GET['q'] ?? '');
$entity = trim($_GET['entity'] ?? '');
$status = trim($_GET['status'] ?? 'all');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
$types = "";

/** search */
if ($q !== '') {
  $where[] = "(u.full_name LIKE ? OR a.id = ?)";
  $params[] = "%$q%";
  $params[] = ctype_digit($q) ? (int)$q : -1;
  $types .= "si";
}

if ($entity !== '' && isset($entityMap[$entity])) {
  $where[] = "a.entity = ?";
  $params[] = $entity;
  $types .= "s";
}

if ($status !== '' && $status !== 'all' && isset($statusMap[$status])) {
  $where[] = "a.status = ?";
  $params[] = $status;
  $types .= "s";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/** ================= COUNT ================= */
$sql = "
  SELECT COUNT(*)
  FROM approvals a
  JOIN users u ON u.id = a.actor_id
  $whereSql
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_row();
$total = (int)$row[0];

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/** ================= LIST ================= */
$sql = "
  SELECT a.*, u.full_name AS actor_name
  FROM approvals a
  JOIN users u ON u.id = a.actor_id
  $whereSql
  ORDER BY a.created_at DESC
  LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

/** thêm limit + offset */
$params2 = $params;
$types2 = $types . "ii";
$params2[] = $perPage;
$params2[] = $offset;

$stmt->bind_param($types2, ...$params2);

$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

/** ===== helper ===== */
function buildQuery(array $extra = []): string {
  $base = $_GET;
  foreach ($extra as $k => $v) $base[$k] = $v;
  return '?' . http_build_query($base);
}

function eStatusBadge(array $statusMap, string $st): string {
  if (!isset($statusMap[$st])) return '<span class="badge badge-gray">Không rõ</span>';
  $b = $statusMap[$st]['badge'];
  $t = $statusMap[$st]['text'];
  return '<span class="badge '.$b.'">'.$t.'</span>';
}
?>

<main class="admin-main">
  <div class="admin-topbar">
    <div>
      <div class="admin-title">Phê duyệt</div>
      <div class="admin-sub">Duyệt yêu cầu thay đổi từ nhân viên</div>
    </div>
  </div>

  <section class="admin-panel" style="margin-bottom:14px;">
    <div class="admin-panel__head">
      <strong>Bộ lọc</strong>
      <span class="badge badge-info">Tổng: <?= (int)$total ?></span>
    </div>

    <div class="admin-panel__body">
      <form class="admin-filters" method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div class="field" style="min-width:260px; flex:1;">
          <label>Tìm (tên nhân viên hoặc #ID)</label>
          <input class="input" type="text" name="q" value="<?= e($q) ?>" placeholder="vd: Thúy hoặc 12">
        </div>

        <div class="field" style="min-width:190px;">
          <label>Loại yêu cầu</label>
          <select class="input" name="entity">
            <option value="" <?= $entity===''?'selected':'' ?>>Tất cả</option>
            <option value="products" <?= $entity==='products'?'selected':'' ?>>Sản phẩm</option>
            <option value="discount_codes" <?= $entity==='discount_codes'?'selected':'' ?>>Mã giảm giá</option>
          </select>
        </div>

        <div class="field" style="min-width:180px;">
          <label>Trạng thái</label>
          <select class="input" name="status">
            <option value="all" <?= $status==='all'?'selected':'' ?>>Tất cả</option>
            <option value="pending" <?= $status==='pending'?'selected':'' ?>>Chờ duyệt</option>
            <option value="approved" <?= $status==='approved'?'selected':'' ?>>Đã duyệt</option>
            <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>Từ chối</option>
          </select>
        </div>

        <button class="btn btn-primary btn-sm" type="submit">
          <i class="fa-solid fa-filter"></i> Lọc
        </button>
        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/approvals/list.php">
          <i class="fa-solid fa-rotate"></i> Reset
        </a>
      </form>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <strong>Danh sách yêu cầu</strong>
      <span class="badge badge-success">Trang <?= (int)$page ?>/<?= (int)$totalPages ?></span>
    </div>

    <div class="admin-panel__body">
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th style="width:90px;">Mã</th>
              <th style="width:200px;">Loại</th>
              <th style="width:160px;">Hành động</th>
              <th>Người tạo</th>
              <th style="width:140px;">Trạng thái</th>
              <th style="width:170px;">Thời gian</th>
              <th style="width:100px;">Chi tiết</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" style="color:var(--text-secondary); padding:16px;">Không có dữ liệu.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <?php
              $entKey = (string)$r['entity'];
              $actKey = (string)$r['action'];
              $entText = $entityMap[$entKey] ?? $entKey;
              $actText = $actionMap[$actKey] ?? $actKey;

              $entityIdText = $r['entity_id'] ? (' • #' . (int)$r['entity_id']) : '';
            ?>
            <tr>
              <td style="font-weight:900;">#<?= (int)$r['id'] ?></td>

              <td>
                <span class="badge badge-info"><?= e($entText) ?></span>
                <span style="opacity:.75; margin-left:6px;"><?= e($entityIdText) ?></span>
              </td>

              <td>
                <?php if ($actKey === 'create'): ?>
                  <span class="badge badge-success"><?= e($actText) ?></span>
                <?php elseif ($actKey === 'update'): ?>
                  <span class="badge badge-info"><?= e($actText) ?></span>
                <?php elseif ($actKey === 'delete'): ?>
                  <span class="badge badge-danger"><?= e($actText) ?></span>
                <?php else: ?>
                  <span class="badge badge-gray"><?= e($actText) ?></span>
                <?php endif; ?>
              </td>

              <td><?= e($r['actor_name']) ?></td>
              <td><?= eStatusBadge($statusMap, (string)$r['status']) ?></td>
              <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>

              <td>
                <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/approvals/detail.php?id=<?= (int)$r['id'] ?>">
                  <i class="fa-regular fa-eye"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px; flex-wrap:wrap;">
          <?php
            $isFirst = ($page <= 1);
            $isLast  = ($page >= $totalPages);
            $prev=max(1,$page-1); $next=min($totalPages,$page+1);
          ?>

          <a class="btn btn-outline btn-sm <?= $isFirst ? 'disabled' : '' ?>"
            <?= $isFirst ? 'aria-disabled="true"' : 'href="'.buildQuery(['page'=>$prev]).'"' ?>>
            ← Trước
          </a>

          <?php $start=max(1,$page-3); $end=min($totalPages,$page+3); for($i=$start;$i<=$end;$i++): ?>
            <a class="btn btn-sm <?= $i===$page ? 'btn-primary' : 'btn-outline' ?>" href="<?= buildQuery(['page'=>$i]) ?>">
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
