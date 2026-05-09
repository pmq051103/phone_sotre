<?php
$pageTitle = 'Quản lý đánh giá - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$errors = [];

/** helpers */
function intOrNull($v){
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

function badgeReviewStatus(int $st): string {
    return $st === 1
        ? '<span class="badge badge-success">Hiển thị</span>'
        : '<span class="badge badge-gray">Đã ẩn</span>';
}

function stars(int $rating): string {
    $rating = max(1, min(5, $rating));
    $s = '';
    for ($i=1; $i<=5; $i++){
        $s .= $i <= $rating
            ? '<i class="fa-solid fa-star" style="color:#f59e0b;"></i>'
            : '<i class="fa-regular fa-star" style="color:#cbd5e1;"></i>';
    }
    return $s;
}

/** Handle POST: toggle_status / delete (MySQLi) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) {
        $errors[] = 'CSRF token không hợp lệ.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_status') {
            $rid = (int)($_POST['id'] ?? 0);
            $to  = (int)($_POST['to'] ?? -1);

            if ($rid <= 0) $errors[] = 'ID đánh giá không hợp lệ.';
            if (!in_array($to, [0,1], true)) $errors[] = 'Trạng thái không hợp lệ.';

            if (!$errors) {
                try {
                    $chk = $conn->prepare("SELECT id FROM reviews WHERE id=?");
                    $chk->bind_param("i", $rid);
                    $chk->execute();
                    if (!$chk->get_result()->fetch_assoc()) {
                        $errors[] = 'Đánh giá không tồn tại.';
                    } else {
                        $up = $conn->prepare("UPDATE reviews SET status=? WHERE id=?");
                        $up->bind_param("ii", $to, $rid);
                        $up->execute();

                        setFlash('success', $to === 1 ? 'Đã hiển thị đánh giá.' : 'Đã ẩn đánh giá.');
                        redirect(BASE_URL . '/admin/reviews/list.php' . buildQuery());
                        exit;
                    }
                } catch (Exception $e) {
                    $errors[] = 'Có lỗi khi cập nhật.';
                }
            }
        }
        elseif ($action === 'delete') {
            $rid = (int)($_POST['id'] ?? 0);
            if ($rid <= 0) $errors[] = 'ID đánh giá không hợp lệ.';

            if (!$errors) {
                try {
                    $del = $conn->prepare("DELETE FROM reviews WHERE id=?");
                    $del->bind_param("i", $rid);
                    $del->execute();

                    setFlash('success', 'Đã xóa đánh giá.');
                    redirect(BASE_URL . '/admin/reviews/list.php' . buildQuery());
                    exit;
                } catch (Exception $e) {
                    $errors[] = 'Có lỗi khi xóa.';
                }
            }
        }
    }
}

/** Filters & Pagination */
$q       = trim($_GET['q'] ?? '');
$status  = intOrNull($_GET['status'] ?? null);
$rating  = intOrNull($_GET['rating'] ?? null);
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/** Build Dynamic SQL for MySQLi */
$where = [];
$types = "";
$bindParams = [];

$baseFrom = "
FROM reviews r
JOIN users u ON r.user_id = u.id
JOIN products p ON r.product_id = p.id
";

if ($q !== '') {
    $where[] = "(p.name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $types .= "sss";
    $searchQ = "%{$q}%";
    array_push($bindParams, $searchQ, $searchQ, $searchQ);
}

if ($status !== null && in_array($status, [0,1], true)) {
    $where[] = "r.status = ?";
    $types .= "i";
    $bindParams[] = $status;
}

if ($rating !== null && $rating >= 1 && $rating <= 5) {
    $where[] = "r.rating = ?";
    $types .= "i";
    $bindParams[] = $rating;
}

if ($from !== '') {
    $where[] = "r.created_at >= ?";
    $types .= "s";
    $bindParams[] = $from . " 00:00:00";
}

if ($to !== '') {
    $where[] = "r.created_at <= ?";
    $types .= "s";
    $bindParams[] = $to . " 23:59:59";
}

$baseWhere = $where ? (" WHERE " . implode(" AND ", $where)) : "";

/** 1. Total rows count */
$stTotal = $conn->prepare("SELECT COUNT(*) {$baseFrom}{$baseWhere}");
if ($where) {
    $stTotal->bind_param($types, ...$bindParams);
}
$stTotal->execute();
$totalRows = (int)$stTotal->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

/** 2. Fetch list */
$sql = "
SELECT 
    r.*, 
    u.full_name AS user_name, 
    u.email     AS user_email,
    p.name       AS product_name, 
    p.thumbnail AS product_thumb
{$baseFrom}{$baseWhere}
ORDER BY r.id DESC
LIMIT ? OFFSET ?
";

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
            <div class="admin-title">Quản lý đánh giá</div>
            <div class="admin-sub">Duyệt/ẩn/hiện đánh giá • Tổng: <?= (int)$totalRows ?></div>
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
                <div class="admin-filter-row" style="gap:10px; align-items:end;">
                    <div class="filter-item" style="min-width:260px;">
                        <label>Tìm kiếm</label>
                        <input class="input" name="q" value="<?= e($q) ?>" placeholder="Tên SP / tên user / email...">
                    </div>

                    <div class="filter-item" style="min-width:160px;">
                        <label>Trạng thái</label>
                        <select class="input" name="status">
                            <option value="" <?= $status===null?'selected':'' ?>>Tất cả</option>
                            <option value="1" <?= $status===1?'selected':'' ?>>Hiển thị</option>
                            <option value="0" <?= $status===0?'selected':'' ?>>Đã ẩn</option>
                        </select>
                    </div>

                    <div class="filter-item" style="min-width:140px;">
                        <label>Số sao</label>
                        <select class="input" name="rating">
                            <option value="" <?= $rating===null?'selected':'' ?>>Tất cả</option>
                            <?php for ($i=5; $i>=1; $i--): ?>
                            <option value="<?= $i ?>" <?= $rating===$i?'selected':'' ?>><?= $i ?> sao</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <label>Từ ngày</label>
                        <input class="input" type="date" name="from" value="<?= e($from) ?>">
                    </div>

                    <div class="filter-item">
                        <label>Đến ngày</label>
                        <input class="input" type="date" name="to" value="<?= e($to) ?>">
                    </div>

                    <div class="filter-actions">
                        <button class="btn btn-outline btn-sm" type="submit">
                            <i class="fa-solid fa-filter"></i> Lọc
                        </button>
                        <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/reviews/list.php">
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
            <strong>Danh sách đánh giá</strong>
            <span class="badge badge-success">Trang <?= (int)$page ?>/<?= (int)$totalPages ?></span>
        </div>

        <div class="admin-panel__body">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">ID</th>
                            <th>Sản phẩm</th>
                            <th style="width:220px;">Người đánh giá</th>
                            <th style="width:160px;">Số sao</th>
                            <th>Nội dung</th>
                            <th style="width:140px;">Trạng thái</th>
                            <th style="width:170px;">Ngày</th>
                            <th style="width:260px;">Hành động</th>
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
                        <?php
                            $rid = (int)$r['id'];
                            $st  = (int)$r['status'];
                            $rt  = (int)$r['rating'];
                            $comment = trim((string)($r['comment'] ?? ''));
                            $short = $comment === '' ? '—' : (mb_strlen($comment) > 90 ? mb_substr($comment, 0, 90) . '…' : $comment);
                            $thumb = $r['product_thumb'] ?? '';
                            ?>
                        <tr>
                            <td>#<?= $rid ?></td>

                            <td>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <div
                                        style="width:44px; height:44px; border-radius:10px; overflow:hidden; border:1px solid rgba(0,0,0,.08); background:#fff;">
                                        <?php if (!empty($thumb)): ?>
                                        <img src="<?= BASE_URL ?>/uploads/<?= e($thumb) ?>"
                                            alt="<?= e($r['product_name']) ?>"
                                            style="width:44px;height:44px;object-fit:cover;">
                                        <?php else: ?>
                                        <div
                                            style="width:44px;height:44px;display:flex;align-items:center;justify-content:center;color:#94a3b8;">
                                            <i class="fa-regular fa-image"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-weight:900;">
                                        <?= e($r['product_name']) ?>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div style="font-weight:900;"><?= e($r['user_name']) ?></div>
                                <div style="font-size:12px; color:var(--text-secondary);"><?= e($r['user_email']) ?>
                                </div>
                            </td>

                            <td>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <span style="font-weight:900; min-width:26px;"><?= $rt ?>/5</span>
                                    <span><?= stars($rt) ?></span>
                                </div>
                            </td>

                            <td>
                                <div class="rv-comment">
                                    <?= e($comment ?: '—') ?>
                                </div>

                                <?php if ($comment !== ''): ?>
                                <button type="button" class="btn btn-outline btn-sm rv-view"
                                    data-id="<?= (int)$r['id'] ?>" data-user="<?= e($r['user_name']) ?>"
                                    data-product="<?= e($r['product_name']) ?>" data-rating="<?= (int)$rt ?>"
                                    data-time="<?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?>"
                                    data-comment="<?= e($comment) ?>">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <?php endif; ?>
                            </td>

                            <td><?= badgeReviewStatus($st) ?></td>

                            <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>

                            <td style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <?php if ($st === 1): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <input type="hidden" name="to" value="0">
                                    <button class="btn btn-outline btn-sm"
                                        style="border-color: rgba(245,158,11,.35); color:#b45309;" type="submit"
                                        onclick="return confirm('Ẩn đánh giá #<?= $rid ?>?')">
                                        <i class="fa-solid fa-eye-slash"></i> Ẩn
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <input type="hidden" name="to" value="1">
                                    <button class="btn btn-outline btn-sm" type="submit"
                                        onclick="return confirm('Hiển thị lại đánh giá #<?= $rid ?>?')">
                                        <i class="fa-solid fa-eye"></i> Hiện
                                    </button>
                                </form>
                                <?php endif; ?>

                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <button class="btn btn-outline btn-sm"
                                        style="border-color: rgba(220,38,38,.35); color: var(--danger-color);"
                                        type="submit" onclick="return confirm('Xóa vĩnh viễn đánh giá #<?= $rid ?>?')">
                                        <i class="fa-solid fa-trash"></i> Xóa
                                    </button>
                                </form>
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

    <div id="rvModal" class="modal" style="display:none;">
  <div class="modal-backdrop" onclick="rvClose()"></div>

  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="rvTitle">
    <div class="modal-head">
      <div>
        <div id="rvTitle" style="font-weight:900; font-size:18px;">Chi tiết đánh giá</div>
        <div id="rvMeta" style="color:var(--text-secondary); font-size:13px; margin-top:4px;"></div>
      </div>
      <button class="btn btn-outline btn-sm" type="button" onclick="rvClose()">×</button>
    </div>

    <div class="modal-body">
      <div id="rvInfo" style="margin-bottom:10px;"></div>
      <div id="rvComment" style="white-space:pre-wrap; line-height:1.6;"></div>
    </div>

    <div class="modal-foot" style="display:flex; justify-content:flex-end; gap:8px;">
      <button class="btn btn-outline" type="button" onclick="rvClose()">Đóng</button>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('rvModal');
  const title = document.getElementById('rvTitle');
  const meta  = document.getElementById('rvMeta');
  const info  = document.getElementById('rvInfo');
  const cmt   = document.getElementById('rvComment');

  function esc(s){
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  window.rvClose = function(){
    modal.style.display = 'none';
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.rv-view');
    if (!btn) return;

    const user = btn.dataset.user || '';
    const product = btn.dataset.product || '';
    const rating = btn.dataset.rating || '';
    const time = btn.dataset.time || '';
    const comment = btn.dataset.comment || '';

    title.textContent = 'Chi tiết đánh giá';
    meta.textContent = `#${btn.dataset.id || ''} • ${time}`;
    info.innerHTML = `
      <div style="font-weight:900;">${esc(user)}</div>
      <div style="color:var(--text-secondary); margin-top:2px;">Sản phẩm: <strong>${esc(product)}</strong> • ${esc(rating)}/5</div>
    `;
    cmt.textContent = comment; // giữ xuống dòng đẹp

    modal.style.display = 'block';
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.rvClose();
  });
})();
</script>
</main>