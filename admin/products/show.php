<?php
$pageTitle = 'Chi tiết sản phẩm - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID không hợp lệ.');
    redirect(BASE_URL . '/admin/products/list.php');
}

/** 1. Truy vấn thông tin sản phẩm (MySQLi) */
$sqlProduct = "
    SELECT p.*, b.name AS brand_name, c.name AS category_name
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sqlProduct);
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    setFlash('danger', 'Sản phẩm không tồn tại.');
    redirect(BASE_URL . '/admin/products/list.php');
}

/** 2. Truy vấn bộ sưu tập ảnh*/
$sqlImages = "SELECT id, image_url FROM product_images WHERE product_id = ? ORDER BY id DESC";
$stmtImg = $conn->prepare($sqlImages);
$stmtImg->bind_param("i", $id);
$stmtImg->execute();
$images = $stmtImg->get_result()->fetch_all(MYSQLI_ASSOC);

/** 3. Xử lý logic hiển thị */
// Thumbnail
$thumbUrl = !empty($product['thumbnail'])
    ? (UPLOAD_URL . e($product['thumbnail']))
    : (BASE_URL . '/assets/images/no-image.png');

// Trạng thái
$statusText  = ((int)$product['status'] === 1) ? 'Hiển thị' : 'Ẩn';
$statusClass = ((int)$product['status'] === 1) ? 'success' : 'warning';

?>
<main class="admin-main">

  <div class="admin-topbar">
    <div>
      <div class="admin-title">Chi tiết sản phẩm</div>
      <div class="admin-sub">#<?= (int)$product['id'] ?> • <?= e($product['name']) ?></div>
    </div>

    <div class="admin-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
      <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/list.php">
        <i class="fa-solid fa-arrow-left"></i> Quay lại danh sách
      </a>

      <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/edit.php?id=<?= (int)$product['id'] ?>">
        <i class="fa-solid fa-pen-to-square"></i> Sửa
      </a>

      <button type="button" class="btn btn-primary btn-sm" id="btnOpenDelete">
        <i class="fa-solid fa-trash"></i> Xóa
      </button>
    </div>
  </div>

  <section class="admin-panel">
    <div class="admin-panel__head" style="display:flex; align-items:center; justify-content:space-between;">
      <strong>Thông tin sản phẩm</strong>
      <span class="badge badge-<?= $statusClass ?>"><?= e($statusText) ?></span>
    </div>

    <div class="admin-panel__body">

      <!-- 2 columns -->
      <div class="ps-one-grid">

        <!-- LEFT: images -->
        <div>
          <div class="ps-box">
            <img src="<?= $thumbUrl ?>" alt="thumbnail" class="ps-main-img">
          </div>

          <?php if ($images): ?>
            <div class="ps-thumb-grid">
              <?php foreach ($images as $img): ?>
                <?php $url = UPLOAD_URL . e($img['image_url']); ?>
                <a class="ps-thumb" href="<?= $url ?>" target="_blank" title="Xem ảnh">
                  <img src="<?= $url ?>" alt="img">
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- RIGHT: info + specs -->
        <div>
          <!-- basic info -->
          <div class="ps-basic">
            <div class="ps-name"><?= e($product['name']) ?></div>

            <div class="ps-price"><?= formatPrice($product['price']) ?></div>

            <div class="ps-info-grid">
              <div class="info-item">
                <div class="info-label">Hãng</div>
                <div class="info-value"><?= e($product['brand_name'] ?? '—') ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">Danh mục</div>
                <div class="info-value"><?= e($product['category_name'] ?? '—') ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">Tồn kho</div>
                <div class="info-value"><?= (int)$product['quantity'] ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">Ngày tạo</div>
                <div class="info-value"><?= e(formatDate($product['created_at'], 'd/m/Y H:i')) ?></div>
              </div>
            </div>
          </div>

          <!-- specs BELOW basic info, still RIGHT -->
          <div class="ps-specs">
            <div class="ps-section-title">Cấu hình</div>

            <div class="ps-spec-grid">
              <div class="info-item">
                <div class="info-label">RAM</div>
                <div class="info-value"><?= e($product['ram'] ?: '—') ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">ROM</div>
                <div class="info-value"><?= e($product['rom'] ?: '—') ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">CPU</div>
                <div class="info-value"><?= e($product['cpu'] ?: '—') ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">Camera</div>
                <div class="info-value"><?= e($product['camera'] ?: '—') ?></div>
              </div>

              <div class="info-item">
                <div class="info-label">Pin</div>
                <div class="info-value"><?= e($product['battery'] ?: '—') ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- FULL-WIDTH DESCRIPTION (still same block) -->
        <div class="ps-desc">
          <div class="ps-section-title">Mô tả</div>
          <div class="ps-desc-box">
            <?php
                $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><h1><h2><h3><h4><blockquote><span>';
                $descSafe = strip_tags($product['description'] ?? '', $allowed);
                ?>
                <div class="tab-panel is-active" id="tab-desc">
                <?= $descSafe ?: '<em>Chưa có mô tả</em>' ?>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</main>

<!-- DELETE MODAL -->
<div class="ps-modal" id="deleteModal" aria-hidden="true">
  <div class="ps-modal__overlay" data-close="1"></div>

  <div class="ps-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
    <button type="button" class="ps-modal__close" data-close="1" aria-label="Đóng">×</button>

    <h3 id="deleteTitle" class="ps-modal__title">Xác nhận xóa sản phẩm</h3>

    <div class="ps-modal__body">
      <div style="background:rgba(0,0,0,.04);border-radius:12px;padding:12px;">
        Bạn có chắc muốn xóa:
        <strong><?= e($product['name']) ?></strong> ?
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-outline btn-sm" data-close="1">Hủy</button>

        <form method="POST" action="<?= BASE_URL ?>/admin/products/delete.php" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">
          <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-trash"></i> Xóa luôn
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('deleteModal');
  const openBtn = document.getElementById('btnOpenDelete');
  if (!modal || !openBtn) return;

  const openModal = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  };
  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  };

  openBtn.addEventListener('click', openModal);
  modal.addEventListener('click', (e) => {
    if (e.target.closest('[data-close="1"]')) closeModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });
})();
</script>
