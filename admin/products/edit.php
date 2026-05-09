<?php
$pageTitle = 'Sửa sản phẩm - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
requireRole(['admin','staff']);
$isAdmin = isAdmin();
$isStaff = isStaff();

$errors = [];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'ID không hợp lệ.');
    redirect(BASE_URL . '/admin/products/list.php');
}

// 1. Dropdown data (MySQLi)
$brands = $conn->query("SELECT id, name FROM brands WHERE status=1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$cats   = $conn->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// 2. Lấy thông tin sản phẩm hiện tại
$stmt = $conn->prepare("
    SELECT p.*, b.name AS brand_name, c.name AS category_name
    FROM products p
    LEFT JOIN brands b ON b.id=p.brand_id
    LEFT JOIN categories c ON c.id=p.category_id
    WHERE p.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    setFlash('danger', 'Sản phẩm không tồn tại.');
    redirect(BASE_URL . '/admin/products/list.php');
}

function isUploadedFile(array $f, string $key): bool {
    return isset($f[$key]) && isset($f[$key]['error']) && $f[$key]['error'] !== UPLOAD_ERR_NO_FILE;
}

function uploadMultiImages(string $field, string $folder='products'): array {
    $result = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return $result;
    $count = count($_FILES[$field]['name']);
    for ($i=0; $i<$count; $i++) {
        if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) continue;
        $file = [
            'name' => $_FILES[$field]['name'][$i],
            'type' => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error' => $_FILES[$field]['error'][$i],
            'size' => $_FILES[$field]['size'][$i],
        ];
        $up = uploadImage($file, $folder);
        if (!empty($up['success'])) $result[] = $up['filename'];
    }
    return $result;
}

// 3. Lấy Gallery hiện tại
$stmtImg = $conn->prepare("SELECT id, image_url FROM product_images WHERE product_id=? ORDER BY id DESC");
$stmtImg->bind_param("i", $id);
$stmtImg->execute();
$images = $stmtImg->get_result()->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyToken($csrf)) $errors[] = 'CSRF token không hợp lệ.';

    $name = trim($_POST['name'] ?? '');
    $brand_id = (int)($_POST['brand_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $description = $_POST['description'] ?? '';
    $ram = trim($_POST['ram'] ?? '');
    $rom = trim($_POST['rom'] ?? '');
    $cpu = trim($_POST['cpu'] ?? '');
    $camera = trim($_POST['camera'] ?? '');
    $battery = trim($_POST['battery'] ?? '');
    $status = (int)($_POST['status'] ?? 1);

    if ($name === '') $errors[] = 'Vui lòng nhập tên sản phẩm.';
    if ($brand_id <= 0) $errors[] = 'Vui lòng chọn hãng.';
    if ($category_id <= 0) $errors[] = 'Vui lòng chọn loại.';
    if ($price <= 0) $errors[] = 'Giá phải > 0.';
    if ($quantity < 0) $errors[] = 'Số lượng tồn không hợp lệ.';

    $deleteImgs = $_POST['delete_images'] ?? [];
    if (!is_array($deleteImgs)) $deleteImgs = [];
    $deleteImgs = array_values(array_filter(array_map('intval', $deleteImgs)));

    $newThumb = null;
    $replaceThumb = false;
    if (isUploadedFile($_FILES, 'thumbnail')) {
        $up = uploadImage($_FILES['thumbnail'], 'products');
        if (empty($up['success'])) $errors[] = $up['message'] ?? 'Upload thumbnail thất bại.';
        else {
            $newThumb = $up['filename'];
            $replaceThumb = true;
        }
    }

    if (empty($errors)) {
        // =================== STAFF: tạo yêu cầu phê duyệt ===================
        if ($isStaff) {
            try {
                $newGalleryImgs = uploadMultiImages('images', 'products');
                $toDel = [];
                if ($deleteImgs) {
                    $placeholders = implode(',', array_fill(0, count($deleteImgs), '?'));
                    $st = $conn->prepare("SELECT id, image_url FROM product_images WHERE product_id=? AND id IN ($placeholders)");
                    $types = "i" . str_repeat("i", count($deleteImgs));
                    $params = array_merge([$id], $deleteImgs);
                    $st->bind_param($types, ...$params);
                    $st->execute();
                    $toDel = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                }

                $payload = [
                    'id' => $id,
                    'name' => $name, 'brand_id' => $brand_id, 'category_id' => $category_id,
                    'price' => $price, 'quantity' => $quantity, 'description' => $description,
                    'ram' => $ram, 'rom' => $rom, 'cpu' => $cpu, 'camera' => $camera, 'battery' => $battery,
                    'status' => $status,
                    'thumbnail_old' => $product['thumbnail'] ?? null,
                    'thumbnail_new' => $replaceThumb ? $newThumb : null,
                    'add_images' => $newGalleryImgs,
                    'delete_images' => $toDel,
                ];

                $approvalId = createApproval($conn, [
                    'actor_id' => (int)(getCurrentUser()['id'] ?? 0),
                    'action' => 'update', 'entity' => 'products', 'entity_id' => $id, 'payload' => $payload,
                ]);

                setFlash('success', "Đã gửi yêu cầu cập nhật sản phẩm (#$approvalId). Chờ admin phê duyệt.");
                redirect(BASE_URL . '/admin/products/list.php');
                exit;
            } catch (Exception $e) {
                $errors[] = 'Có lỗi khi gửi phê duyệt.';
            }
        }

        // =================== ADMIN: update trực tiếp (MySQLi) ===================
        if ($isAdmin) {
            try {
                $conn->begin_transaction();

                // Xóa ảnh gallery được chọn
                if ($deleteImgs) {
                    $placeholders = implode(',', array_fill(0, count($deleteImgs), '?'));
                    $st = $conn->prepare("SELECT id, image_url FROM product_images WHERE product_id=? AND id IN ($placeholders)");
                    $types = "i" . str_repeat("i", count($deleteImgs));
                    $params = array_merge([$id], $deleteImgs);
                    $st->bind_param($types, ...$params);
                    $st->execute();
                    $toDelItems = $st->get_result()->fetch_all(MYSQLI_ASSOC);

                    $delStmt = $conn->prepare("DELETE FROM product_images WHERE product_id=? AND id=?");
                    foreach ($toDelItems as $imgItem) {
                        if (!empty($imgItem['image_url'])) deleteImage($imgItem['image_url']);
                        $delStmt->bind_param("ii", $id, $imgItem['id']);
                        $delStmt->execute();
                    }
                }

                // Thêm ảnh gallery mới
                $addedImgs = uploadMultiImages('images', 'products');
                if ($addedImgs) {
                    $ins = $conn->prepare("INSERT INTO product_images (product_id, image_url) VALUES (?, ?)");
                    foreach ($addedImgs as $fn) {
                        $ins->bind_param("is", $id, $fn);
                        $ins->execute();
                    }
                }

                // Cập nhật thông tin chính
                $thumbToSave = $product['thumbnail'];
                if ($replaceThumb && $newThumb) {
                    if (!empty($product['thumbnail'])) deleteImage($product['thumbnail']);
                    $thumbToSave = $newThumb;
                }

                $updateSql = "UPDATE products SET 
                    name=?, brand_id=?, category_id=?, price=?, quantity=?, description=?, 
                    ram=?, rom=?, cpu=?, camera=?, battery=?, thumbnail=?, status=? 
                    WHERE id=?";
                $upStmt = $conn->prepare($updateSql);
                $upStmt->bind_param(
                    "siidississsssi", 
                    $name, $brand_id, $category_id, $price, $quantity, $description,
                    $ram, $rom, $cpu, $camera, $battery, $thumbToSave, $status, $id
                );
                $upStmt->execute();

                $conn->commit();
                setFlash('success', 'Đã cập nhật sản phẩm.');
                redirect(BASE_URL . '/admin/products/edit.php?id=' . $id);
                exit;

            } catch(Exception $e) {
                $conn->rollback();
                $errors[] = 'Có lỗi khi cập nhật: ' . $e->getMessage();
            }
        }
    }
}

// 4. Làm mới dữ liệu sau khi xử lý (MySQLi)
$stmt = $conn->prepare("SELECT * FROM products WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

$stmtImg = $conn->prepare("SELECT id, image_url FROM product_images WHERE product_id=? ORDER BY id DESC");
$stmtImg->bind_param("i", $id);
$stmtImg->execute();
$images = $stmtImg->get_result()->fetch_all(MYSQLI_ASSOC);

$thumbUrl = !empty($product['thumbnail'])
    ? (UPLOAD_URL . e($product['thumbnail']))
    : (BASE_URL . '/assets/images/no-image.png');
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Sửa sản phẩm</div>
            <div class="admin-sub">#<?= (int)$id ?> • <?= e($product['name']) ?></div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/list.php">
                <i class="fa-solid fa-arrow-left"></i> Quay lại
            </a>
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/show.php?id=<?= (int)$id ?>">
                <i class="fa-regular fa-eye"></i> Xem chi tiết
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
        <div class="admin-panel__head">
            <strong>Thông tin</strong>
            <span class="badge badge-info">Cập nhật & quản lý ảnh</span>
        </div>

        <div class="admin-panel__body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">

                <div class="admin-grid-2">
                    <div>
                        <label style="font-weight:800;">Tên sản phẩm *</label>
                        <input class="input" style="width:100%;margin-top:6px;" name="name"
                            value="<?= e($product['name']) ?>" required>
                    </div>

                    <div>
                        <label style="font-weight:800;">Trạng thái</label>
                        <select class="input" style="width:100%;margin-top:6px;" name="status">
                            <option value="1" <?= ((int)$product['status']===1)?'selected':'' ?>>Hiển thị</option>
                            <option value="0" <?= ((int)$product['status']===0)?'selected':'' ?>>Ẩn</option>
                        </select>
                    </div>
                </div>

                <div class="admin-grid-2" style="margin-top:12px;">
                    <div>
                        <label style="font-weight:800;">Hãng *</label>
                        <select class="input" style="width:100%;margin-top:6px;" name="brand_id" required>
                            <option value="">-- Chọn hãng --</option>
                            <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"
                                <?= ((int)$product['brand_id']===(int)$b['id'])?'selected':'' ?>>
                                <?= e($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="font-weight:800;">Loại *</label>
                        <select class="input" style="width:100%;margin-top:6px;" name="category_id" required>
                            <option value="">-- Chọn loại --</option>
                            <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= ((int)$product['category_id']===(int)$c['id'])?'selected':'' ?>>
                                <?= e($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="admin-grid-2" style="margin-top:12px;">
                    <div>
                        <label style="font-weight:800;">Giá *</label>
                        <input class="input" style="width:100%;margin-top:6px;" type="number" name="price" min="0"
                            step="1000" value="<?= e($product['price']) ?>" required>
                    </div>
                    <div>
                        <label style="font-weight:800;">Tồn kho</label>
                        <input class="input" style="width:100%;margin-top:6px;" type="number" name="quantity" min="0"
                            step="1" value="<?= e($product['quantity']) ?>">
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label style="font-weight:800;">Mô tả</label>
                    <textarea id="descriptionEditor" name="description"><?= $product['description'] ?? '' ?></textarea>
                </div>

                <div class="admin-grid-2" style="margin-top:12px;">
                    <div><label style="font-weight:800;">RAM</label><input class="input"
                            style="width:100%;margin-top:6px;" name="ram" value="<?= e($product['ram']) ?>"></div>
                    <div><label style="font-weight:800;">ROM</label><input class="input"
                            style="width:100%;margin-top:6px;" name="rom" value="<?= e($product['rom']) ?>"></div>
                </div>
                <div class="admin-grid-2" style="margin-top:12px;">
                    <div><label style="font-weight:800;">CPU</label><input class="input"
                            style="width:100%;margin-top:6px;" name="cpu" value="<?= e($product['cpu']) ?>"></div>
                    <div><label style="font-weight:800;">Camera</label><input class="input"
                            style="width:100%;margin-top:6px;" name="camera" value="<?= e($product['camera']) ?>"></div>
                </div>
                <div style="margin-top:12px;">
                    <label style="font-weight:800;">Pin</label>
                    <input class="input" style="width:100%;margin-top:6px;" name="battery"
                        value="<?= e($product['battery']) ?>">
                </div>

                <hr style="margin:16px 0;border:0;border-top:1px solid rgba(0,0,0,.08);">

                <div class="admin-grid-2">
                    <div>
                        <div style="font-weight:800;margin-bottom:8px;">Thumbnail hiện tại</div>
                        <img src="<?= $thumbUrl ?>" alt="thumb"
                            style="width:140px;height:140px;object-fit:contain;border-radius:14px;border:1px solid rgba(0,0,0,.06);background:#fff;">
                        <div style="margin-top:10px;">
                            <label style="font-weight:800;">Đổi thumbnail</label>
                            <input class="input" style="width:100%;margin-top:6px;" type="file" name="thumbnail"
                                accept="image/*">
                        </div>
                    </div>

                    <div>
                        <div style="font-weight:800;margin-bottom:8px;">Ảnh chi tiết hiện tại</div>

                        <?php if (!$images): ?>
                        <div style="color:var(--text-secondary);">Chưa có ảnh chi tiết.</div>
                        <?php else: ?>
                        <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px;">
                            <?php foreach ($images as $img): ?>
                            <?php $url = UPLOAD_URL . e($img['image_url']); ?>
                            <label
                                style="border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:8px; background:#fff; display:block;">
                                <img src="<?= $url ?>"
                                    style="width:100%;height:70px;object-fit:contain;border-radius:10px;background:#f8f9fa;">
                                <div style="margin-top:8px; font-size:12px; display:flex; gap:6px; align-items:center;">
                                    <input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>">
                                    <span style="opacity:.8;">Xóa</span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:12px;opacity:.7;margin-top:8px;">Tick “Xóa” rồi bấm “Lưu thay đổi”</div>
                        <?php endif; ?>

                        <div style="margin-top:12px;">
                            <label style="font-weight:800;">Thêm ảnh chi tiết (nhiều ảnh)</label>
                            <input class="input" style="width:100%;margin-top:6px;" type="file" name="images[]"
                                accept="image/*" multiple>
                        </div>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                    <a href="<?= BASE_URL ?>/admin/products/list.php" class="btn btn-outline btn-sm">
                        <i class="fa-solid fa-arrow-left"></i>
                        Quay lại
                    </a>

                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="fa-solid fa-save"></i>
                        <?= $isStaff ? 'Gửi phê duyệt' : 'Lưu sản phẩm' ?>
                    </button>
                </div>

            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.jQuery && $('#descriptionEditor').length) {
                $('#descriptionEditor').summernote({
                    placeholder: 'Nhập mô tả sản phẩm...',
                    height: 260,
                    toolbar: [
                        ['style', ['bold', 'italic', 'underline', 'clear']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link']],
                        ['view', ['codeview']]
                    ]
                });
            } else {
                console.log('Missing jQuery or #descriptionEditor not found');
            }
        });
        </script>

    </section>
</main>