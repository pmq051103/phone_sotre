<?php
$pageTitle = 'Thêm sản phẩm - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
requireRole(['admin','staff']);
$isAdmin = isAdmin();
$isStaff = isStaff();

$errors = [];
$success = false;

/** 1. Lấy dữ liệu Brands và Categories (MySQLi) */
$brands = $conn->query("SELECT id, name FROM brands WHERE status=1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$cats   = $conn->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

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
        if (!empty($up['success'])) {
            $result[] = $up['filename'];
        }
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyToken($csrf)) $errors[] = 'CSRF token không hợp lệ.';
    if ($name === '') $errors[] = 'Vui lòng nhập tên sản phẩm.';
    if ($brand_id <= 0) $errors[] = 'Vui lòng chọn hãng.';
    if ($category_id <= 0) $errors[] = 'Vui lòng chọn loại.';
    if ($price <= 0) $errors[] = 'Giá phải > 0.';
    if ($quantity < 0) $errors[] = 'Số lượng tồn không hợp lệ.';
    if (!in_array($status, [0,1], true)) $status = 1;

    // upload thumbnail (optional)
    $thumbFilename = null;
    if (isUploadedFile($_FILES, 'thumbnail')) {
        $up = uploadImage($_FILES['thumbnail'], 'products');
        if (empty($up['success'])) $errors[] = $up['message'] ?? 'Upload thumbnail thất bại.';
        else $thumbFilename = $up['filename'];
    }

    if (empty($errors)) {
        try {
            // Upload gallery images trước
            $imgs = uploadMultiImages('images', 'products');

            // ============ STAFF: tạo yêu cầu phê duyệt ============
            if ($isStaff) {
                $payload = [
                    'name' => $name,
                    'brand_id' => $brand_id,
                    'category_id' => $category_id,
                    'price' => $price,
                    'quantity' => $quantity,
                    'description' => $description,
                    'ram' => $ram,
                    'rom' => $rom,
                    'cpu' => $cpu,
                    'camera' => $camera,
                    'battery' => $battery,
                    'thumbnail' => $thumbFilename, 
                    'status' => $status,
                    'images' => $imgs, 
                ];

                $approvalId = createApproval($conn, [
                    'actor_id' => (int)(getCurrentUser()['id'] ?? 0),
                    'action' => 'create',
                    'entity' => 'products',
                    'entity_id' => null,
                    'payload' => $payload,
                ]);

                setFlash('success', "Đã gửi yêu cầu thêm sản phẩm (#$approvalId). Chờ admin phê duyệt.");
                redirect(BASE_URL . '/admin/products/list.php');
                exit;
            }

            // ============ ADMIN: insert trực tiếp (MySQLi) ============
            $conn->begin_transaction();

            $sql = "INSERT INTO products 
                    (name, brand_id, category_id, price, quantity, description, 
                     ram, rom, cpu, camera, battery, thumbnail, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "siidississssi", 
                $name, $brand_id, $category_id, $price, $quantity, $description,
                $ram, $rom, $cpu, $camera, $battery, $thumbFilename, $status
            );
            $stmt->execute();

            $productId = $conn->insert_id;

            // Chèn ảnh gallery
            if (!empty($imgs)) {
                $ins = $conn->prepare("INSERT INTO product_images (product_id, image_url) VALUES (?, ?)");
                foreach ($imgs as $fn) {
                    $ins->bind_param("is", $productId, $fn);
                    $ins->execute();
                }
            }

            $conn->commit();
            setFlash('success', 'Đã thêm sản phẩm thành công.');
            redirect(BASE_URL . '/admin/products/list.php');
            exit;

        } catch (Exception $e) {
            // rollback nếu có lỗi
            if (isset($conn) && $conn->connect_errno === 0) {
                $conn->rollback();
            }
            $errors[] = 'Có lỗi khi lưu dữ liệu: ' . $e->getMessage();
        }
    }
}
?>

<main class="admin-main">
    <div class="admin-topbar">
        <div>
            <div class="admin-title">Thêm sản phẩm</div>
            <div class="admin-sub">Tạo mới sản phẩm + upload nhiều ảnh</div>
        </div>
        <div class="admin-actions">
            <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/list.php">
                <i class="fa-solid fa-arrow-left"></i> Quay lại
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
            <strong>Thông tin sản phẩm</strong>
            <span class="badge badge-info">(*) bắt buộc</span>
        </div>

        <div class="admin-panel__body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(generateToken()) ?>">

                <div class="admin-grid-2">
                    <div>
                        <label style="font-weight:800;">Tên sản phẩm *</label>
                        <input class="input" style="width:100%;margin-top:6px;" name="name"
                            value="<?= e($_POST['name'] ?? '') ?>" required>
                    </div>

                    <div>
                        <label style="font-weight:800;">Trạng thái</label>
                        <select class="input" style="width:100%;margin-top:6px;" name="status">
                            <option value="1" <?= (($_POST['status'] ?? '1')==='1')?'selected':'' ?>>Hiển thị</option>
                            <option value="0" <?= (($_POST['status'] ?? '')==='0')?'selected':'' ?>>Ẩn</option>
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
                                <?= ((int)($_POST['brand_id'] ?? 0)===(int)$b['id'])?'selected':'' ?>>
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
                                <?= ((int)($_POST['category_id'] ?? 0)===(int)$c['id'])?'selected':'' ?>>
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
                            step="1000" value="<?= e($_POST['price'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label style="font-weight:800;">Tồn kho</label>
                        <input class="input" style="width:100%;margin-top:6px;" type="number" name="quantity" min="0"
                            step="1" value="<?= e($_POST['quantity'] ?? '0') ?>">
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label style="font-weight:800;">Mô tả</label>
                    <textarea id="descriptionEditor" name="description"><?= $product['description'] ?? '' ?></textarea>
                </div>

                <div class="admin-grid-2" style="margin-top:12px;">
                    <div>
                        <label style="font-weight:800;">RAM</label>
                        <input class="input" style="width:100%;margin-top:6px;" name="ram"
                            value="<?= e($_POST['ram'] ?? '') ?>" placeholder="vd: 8GB">
                    </div>
                    <div>
                        <label style="font-weight:800;">ROM</label>
                        <input class="input" style="width:100%;margin-top:6px;" name="rom"
                            value="<?= e($_POST['rom'] ?? '') ?>" placeholder="vd: 256GB">
                    </div>
                </div>

                <div class="admin-grid-2" style="margin-top:12px;">
                    <div>
                        <label style="font-weight:800;">CPU</label>
                        <input class="input" style="width:100%;margin-top:6px;" name="cpu"
                            value="<?= e($_POST['cpu'] ?? '') ?>" placeholder="vd: Snapdragon 8 Gen 3">
                    </div>
                    <div>
                        <label style="font-weight:800;">Camera</label>
                        <input class="input" style="width:100%;margin-top:6px;" name="camera"
                            value="<?= e($_POST['camera'] ?? '') ?>" placeholder="vd: 48MP + 12MP">
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label style="font-weight:800;">Pin</label>
                    <input class="input" style="width:100%;margin-top:6px;" name="battery"
                        value="<?= e($_POST['battery'] ?? '') ?>" placeholder="vd: 5000mAh">
                </div>

                <div class="admin-grid-2" style="margin-top:12px;">
                    <div>
                        <label style="font-weight:800;">Thumbnail (ảnh đại diện)</label>
                        <input class="input" style="width:100%;margin-top:6px;" type="file" name="thumbnail"
                            accept="image/*">
                        <div style="font-size:12px;opacity:.7;margin-top:6px;">jpg/png/webp/gif • tối đa 5MB</div>
                    </div>

                    <div>
                        <label style="font-weight:800;">Ảnh chi tiết (nhiều ảnh)</label>
                        <input class="input" style="width:100%;margin-top:6px;" type="file" name="images[]"
                            accept="image/*" multiple>
                        <div style="font-size:12px;opacity:.7;margin-top:6px;">Có thể chọn nhiều ảnh</div>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                    <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/admin/products/list.php">Hủy</a>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="fa-solid fa-save"></i> <?= $isStaff ? 'Gửi phê duyệt' : 'Thêm sản phẩm' ?>
                    </button>
                </div>
            </form>
        </div>
    </section>
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
</main>