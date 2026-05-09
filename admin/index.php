<?php
$pageTitle = 'Dashboard - Admin';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

/* ====== Helpers ====== */
function clampDate($s) {
    if (!$s) return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

/* ====== Filters (GET) ====== */
$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-29 day'));
$defaultTo   = $today;

$from = clampDate($_GET['from'] ?? '') ?? $defaultFrom;
$to   = clampDate($_GET['to'] ?? '') ?? $defaultTo;

$groupBy = ($_GET['group_by'] ?? 'day');
$groupBy = in_array($groupBy, ['day','month'], true) ? $groupBy : 'day';

if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

$topN = (int)($_GET['top'] ?? 10);
if ($topN < 5) $topN = 5;
if ($topN > 30) $topN = 30;

/* ====== 1. KPI (Overall) - MySQLi ====== */
// Do query trực tiếp không tham số, có thể dùng query()
$kpiRevenue = (float)$conn->query("SELECT COALESCE(SUM(final_amount),0) FROM orders WHERE status='completed'")->fetch_row()[0];
$kpiOrders  = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];
$kpiUsers   = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetch_row()[0];

/* ====== 2. Revenue chart (Filtered) - MySQLi ====== */
if ($groupBy === 'month') {
    $sqlRevenue = "
        SELECT DATE_FORMAT(created_at, '%Y-%m') k, COALESCE(SUM(final_amount),0) total
        FROM orders
        WHERE status='completed'
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY k
        ORDER BY k ASC
    ";
} else {
    $sqlRevenue = "
        SELECT DATE(created_at) k, COALESCE(SUM(final_amount),0) total
        FROM orders
        WHERE status='completed'
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY k
        ORDER BY k ASC
    ";
}

$stmt = $conn->prepare($sqlRevenue);
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$revRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$revLabels = [];
$revTotals = [];
foreach ($revRows as $r) {
    $revLabels[] = ($groupBy === 'month')
        ? date('m/Y', strtotime($r['k'].'-01'))
        : date('d/m', strtotime($r['k']));
    $revTotals[] = (float)$r['total'];
}

/* ====== 3. Best sellers - MySQLi ====== */
$sqlBest = "
    SELECT p.name, SUM(oi.quantity) sold
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.status='completed'
      AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY sold DESC
    LIMIT ?
";
$stmt = $conn->prepare($sqlBest);
$stmt->bind_param("ssi", $from, $to, $topN);
$stmt->execute();
$bestRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$bestLabels = array_map(fn($x) => $x['name'], $bestRows);
$bestValues = array_map(fn($x) => (int)$x['sold'], $bestRows);

/* ====== 4. Orders & Users trend - MySQLi ====== */
if ($groupBy === 'month') {
    $sqlOrdersCnt = "SELECT DATE_FORMAT(created_at,'%Y-%m') k, COUNT(*) total FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY k ORDER BY k ASC";
    $sqlUsersCnt  = "SELECT DATE_FORMAT(created_at,'%Y-%m') k, COUNT(*) total FROM users WHERE role='user' AND DATE(created_at) BETWEEN ? AND ? GROUP BY k ORDER BY k ASC";
} else {
    $sqlOrdersCnt = "SELECT DATE(created_at) k, COUNT(*) total FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY k ORDER BY k ASC";
    $sqlUsersCnt  = "SELECT DATE(created_at) k, COUNT(*) total FROM users WHERE role='user' AND DATE(created_at) BETWEEN ? AND ? GROUP BY k ORDER BY k ASC";
}

// Lấy dữ liệu Orders
$stmt = $conn->prepare($sqlOrdersCnt);
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$oc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Lấy dữ liệu Users
$stmt = $conn->prepare($sqlUsersCnt);
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$uc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$mapO = []; foreach ($oc as $r) $mapO[$r['k']] = (int)$r['total'];
$mapU = []; foreach ($uc as $r) $mapU[$r['k']] = (int)$r['total'];

$mixLabels = [];
$mixOrders = [];
$mixUsers  = [];

// Xây dựng Timeline để đảm bảo biểu đồ không bị trống các ngày không có dữ liệu
if ($groupBy === 'month') {
    $start = new DateTime(date('Y-m-01', strtotime($from)));
    $end   = new DateTime(date('Y-m-01', strtotime($to)));
    $end->modify('+1 month');
    $it = new DatePeriod($start, new DateInterval('P1M'), $end);
    foreach ($it as $dt) {
        $k = $dt->format('Y-m');
        $mixLabels[] = $dt->format('m/Y');
        $mixOrders[] = $mapO[$k] ?? 0;
        $mixUsers[]  = $mapU[$k] ?? 0;
    }
} else {
    $start = new DateTime($from);
    $end   = new DateTime($to);
    $end->modify('+1 day');
    $it = new DatePeriod($start, new DateInterval('P1D'), $end);
    foreach ($it as $dt) {
        $k = $dt->format('Y-m-d');
        $mixLabels[] = $dt->format('d/m');
        $mixOrders[] = $mapO[$k] ?? 0;
        $mixUsers[]  = $mapU[$k] ?? 0;
    }
}
?>
<main class="admin-main">
  <div class="admin-topbar">
    <div>
      <div class="admin-title">Dashboard</div>
      <div class="admin-sub">Thống kê doanh thu, tình hình kinh doanh sản phẩm</div>
    </div>
    <div class="admin-actions">
      <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/index.php">
        <i class="fa-solid fa-rotate"></i> Reset lọc
      </a>
    </div>
  </div>

  <section class="admin-cards">
    <div class="admin-card">
      <div class="kpi"><?= number_format($kpiRevenue, 0, ',', '.') ?>đ</div>
      <div class="label">Doanh thu (tổng completed)</div>
    </div>
    <div class="admin-card">
      <div class="kpi"><?= $kpiOrders ?></div>
      <div class="label">Tổng đơn hàng</div>
    </div>
    <div class="admin-card">
      <div class="kpi"><?= $kpiUsers ?></div>
      <div class="label">Tổng khách hàng</div>
    </div>
    <div class="admin-card">
      <div class="kpi"><?= (int)$topN ?></div>
      <div class="label">Top sản phẩm hiển thị</div>
    </div>
  </section>

  <!-- ===== Top row: 2 charts ===== -->
  <section class="admin-grid-2">
    <div class="admin-panel">
      <div class="admin-panel__head">
        <strong>Sản phẩm bán chạy (Top <?= (int)$topN ?>)</strong>
        <span class="badge badge-success"> <?= e($from) ?> → <?= e($to) ?></span>
      </div>
      <div class="admin-panel__body">
        <canvas id="chartBest" height="140"></canvas>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel__head">
        <strong>Đơn hàng & User (theo <?= $groupBy === 'month' ? 'tháng' : 'ngày' ?>)</strong>
        <span class="badge badge-info"><?= e($from) ?> → <?= e($to) ?></span>
      </div>
      <div class="admin-panel__body">
        <canvas id="chartMix" height="140"></canvas>
      </div>
    </div>
  </section>

  <!-- ===== Bottom: revenue with filters ===== -->
  <section class="admin-panel">
    <div class="admin-panel__head">
      <strong>Doanh thu</strong>

      <form class="admin-filters" method="GET" action="">
        <div class="field">
          <label>Từ ngày</label>
          <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
          <label>Đến ngày</label>
          <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div class="field">
          <label>Nhóm theo</label>
          <select name="group_by">
            <option value="day" <?= $groupBy==='day'?'selected':'' ?>>Ngày</option>
            <option value="month" <?= $groupBy==='month'?'selected':'' ?>>Tháng</option>
          </select>
        </div>
        <div class="field">
          <label>Top</label>
          <select name="top">
            <?php foreach ([5,10,15,20,30] as $n): ?>
              <option value="<?= $n ?>" <?= $topN===$n?'selected':'' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button class="btn btn-primary btn-sm" type="submit">
          <i class="fa-solid fa-filter"></i> Áp dụng
        </button>
      </form>
    </div>

    <div class="admin-panel__body">
      <canvas id="chartRevenue" height="110"></canvas>
    </div>
  </section>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const money = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(v)) + 'đ';

    const bestLabels = <?= json_encode($bestLabels, JSON_UNESCAPED_UNICODE) ?>;
    const bestValues = <?= json_encode($bestValues, JSON_UNESCAPED_UNICODE) ?>;

    const mixLabels = <?= json_encode($mixLabels, JSON_UNESCAPED_UNICODE) ?>;
    const mixOrders = <?= json_encode($mixOrders, JSON_UNESCAPED_UNICODE) ?>;
    const mixUsers  = <?= json_encode($mixUsers, JSON_UNESCAPED_UNICODE) ?>;

    const revLabels = <?= json_encode($revLabels, JSON_UNESCAPED_UNICODE) ?>;
    const revTotals = <?= json_encode($revTotals, JSON_UNESCAPED_UNICODE) ?>;

    new Chart(document.getElementById('chartBest'), {
      type: 'bar',
      data: { labels: bestLabels, datasets: [{ label: 'Đã bán', data: bestValues }] },
      options: { indexAxis: 'y', responsive: true, plugins: { legend: { display:false } } }
    });

    new Chart(document.getElementById('chartMix'), {
      type: 'bar',
      data: {
        labels: mixLabels,
        datasets: [
          { label: 'Đơn hàng', data: mixOrders },
          { label: 'User mới', data: mixUsers },
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { ticks: { precision: 0 } } }
      }
    });

    new Chart(document.getElementById('chartRevenue'), {
      type: 'line',
      data: { labels: revLabels, datasets: [{ label: 'Doanh thu', data: revTotals, tension: .3 }] },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (c) => money(c.raw) } }
        },
        scales: { y: { ticks: { callback: (v) => money(v) } } }
      }
    });
  </script>
</main>
