<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/approvals/list.php');
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyToken($csrf)) {
    setFlash('danger', 'CSRF token không hợp lệ.');
    redirect(BASE_URL . '/admin/approvals/list.php');
}

$id = (int)($_POST['id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$note = trim($_POST['note'] ?? '');
$adminId = (int)(getCurrentUser()['id'] ?? 0);

if ($id <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    setFlash('danger', 'Dữ liệu không hợp lệ.');
    redirect(BASE_URL . '/admin/approvals/list.php');
}

try {
    // MySQLi: Bắt đầu transaction
    $conn->begin_transaction();

    // SELECT ... FOR UPDATE
    $stmt = $conn->prepare("SELECT * FROM approvals WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $a = $result->fetch_assoc();

    if (!$a) throw new Exception('Không tìm thấy yêu cầu');
    if ($a['status'] !== 'pending') throw new Exception('Yêu cầu đã được xử lý');

    if ($decision === 'reject') {
        $up = $conn->prepare("
            UPDATE approvals 
            SET status='rejected', admin_id=?, admin_note=?, decided_at=NOW() 
            WHERE id=?
        ");
        $up->bind_param("isi", $adminId, $note, $id);
        $up->execute();

        $conn->commit();
        setFlash('success', 'Đã từ chối yêu cầu.');
        redirect(BASE_URL . '/admin/approvals/list.php');
        exit;
    }

    // approve: apply
    $payload = json_decode($a['payload'] ?? '{}', true) ?: [];
    applyApproval($conn, $a, $payload);

    $up = $conn->prepare("
        UPDATE approvals 
        SET status='approved', admin_id=?, admin_note=?, decided_at=NOW() 
        WHERE id=?
    ");
    $up->bind_param("isi", $adminId, $note, $id);
    $up->execute();

    $conn->commit();
    setFlash('success', 'Đã phê duyệt & áp dụng.');
    redirect(BASE_URL . '/admin/approvals/list.php');
    exit;

} catch (Exception $e) {
    // MySQLi: Rollback nếu có lỗi
    $conn->rollback();
    setFlash('danger', 'Lỗi: ' . $e->getMessage());
    redirect(BASE_URL . '/admin/approvals/list.php');
    exit;
}

// ======================= APPLY ENGINE (MYSQLI VERSION) =======================
function applyApproval(mysqli $conn, array $a, array $p): void {
    $entity   = (string)($a['entity'] ?? '');
    $action   = (string)($a['action'] ?? '');
    $entityId = (int)($a['entity_id'] ?? 0);

    // -------- PRODUCTS --------
    if ($entity === 'products') {
        if ($action === 'create') {
            $sql = "INSERT INTO products 
                    (name, brand_id, category_id, price, quantity, description, ram, rom, cpu, camera, battery, thumbnail, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $st = $conn->prepare($sql);
            
            $name = $p['name'] ?? '';
            $brand_id = (int)($p['brand_id'] ?? 0);
            $cat_id = (int)($p['category_id'] ?? 0);
            $price = (float)($p['price'] ?? 0);
            $qty = (int)($p['quantity'] ?? 0);
            $desc = $p['description'] ?? '';
            $ram = $p['ram'] ?? '';
            $rom = $p['rom'] ?? '';
            $cpu = $p['cpu'] ?? '';
            $cam = $p['camera'] ?? '';
            $bat = $p['battery'] ?? '';
            $thumb = $p['thumbnail'] ?? null;
            $status = (int)($p['status'] ?? 1);

            $st->bind_param("siidssssssssi", $name, $brand_id, $cat_id, $price, $qty, $desc, $ram, $rom, $cpu, $cam, $bat, $thumb, $status);
            $st->execute();

            $newId = $conn->insert_id;

            if (!empty($p['images']) && is_array($p['images'])) {
                $ins = $conn->prepare("INSERT INTO product_images(product_id, image_url) VALUES (?,?)");
                foreach ($p['images'] as $fn) {
                    if ($fn !== '' && $fn !== null) {
                        $ins->bind_param("is", $newId, $fn);
                        $ins->execute();
                    }
                }
            }
            return;
        }

        if ($action === 'update') {
            if ($entityId <= 0) throw new Exception('Thiếu entity_id');

            $st = $conn->prepare("SELECT * FROM products WHERE id=?");
            $st->bind_param("i", $entityId);
            $st->execute();
            $old = $st->get_result()->fetch_assoc();
            if (!$old) throw new Exception('Sản phẩm không tồn tại');

            $thumbToSave = (!empty($p['thumbnail_new'])) ? $p['thumbnail_new'] : ($old['thumbnail'] ?? null);

            $sql = "UPDATE products SET 
                    name=?, brand_id=?, category_id=?, price=?, quantity=?, description=?, 
                    ram=?, rom=?, cpu=?, camera=?, battery=?, thumbnail=?, status=? 
                    WHERE id=?";
            $up = $conn->prepare($sql);

            $name = $p['name'] ?? ($old['name'] ?? '');
            $bid = (int)($p['brand_id'] ?? ($old['brand_id'] ?? 0));
            $cid = (int)($p['category_id'] ?? ($old['category_id'] ?? 0));
            $prc = (float)($p['price'] ?? ($old['price'] ?? 0));
            $qty = (int)($p['quantity'] ?? ($old['quantity'] ?? 0));
            $dsc = $p['description'] ?? ($old['description'] ?? '');
            $ram = $p['ram'] ?? ($old['ram'] ?? '');
            $rom = $p['rom'] ?? ($old['rom'] ?? '');
            $cpu = $p['cpu'] ?? ($old['cpu'] ?? '');
            $cam = $p['camera'] ?? ($old['camera'] ?? '');
            $bat = $p['battery'] ?? ($old['battery'] ?? '');
            $stat = (int)($p['status'] ?? ($old['status'] ?? 1));

            $up->bind_param("siidssssssssii", $name, $bid, $cid, $prc, $qty, $dsc, $ram, $rom, $cpu, $cam, $bat, $thumbToSave, $stat, $entityId);
            $up->execute();

            if (!empty($p['thumbnail_new']) && !empty($p['thumbnail_old']) && $p['thumbnail_new'] !== $p['thumbnail_old']) {
                if (function_exists('deleteImage')) deleteImage($p['thumbnail_old']);
            }

            if (!empty($p['delete_images']) && is_array($p['delete_images'])) {
                $del = $conn->prepare("DELETE FROM product_images WHERE product_id=? AND id=?");
                foreach ($p['delete_images'] as $img) {
                    $imgId = (int)($img['id'] ?? 0);
                    $url = $img['image_url'] ?? '';
                    if ($imgId > 0) {
                        $del->bind_param("ii", $entityId, $imgId);
                        $del->execute();
                        if (!empty($url) && function_exists('deleteImage')) deleteImage($url);
                    }
                }
            }

            if (!empty($p['add_images']) && is_array($p['add_images'])) {
                $ins = $conn->prepare("INSERT INTO product_images(product_id, image_url) VALUES (?,?)");
                foreach ($p['add_images'] as $fn) {
                    if ($fn !== '' && $fn !== null) {
                        $ins->bind_param("is", $entityId, $fn);
                        $ins->execute();
                    }
                }
            }
            return;
        }
    }

        if ($action === 'delete') {
            if ($entityId <= 0) throw new Exception('Thiếu entity_id');

            // 1. Lấy thumbnail
            $stProduct = $conn->prepare("SELECT thumbnail FROM products WHERE id = ?");
            $stProduct->bind_param("i", $entityId);
            $stProduct->execute();
            $product = $stProduct->get_result()->fetch_assoc();

            // 2. Lấy gallery images
            $stGallery = $conn->prepare("SELECT image_url FROM product_images WHERE product_id = ?");
            $stGallery->bind_param("i", $entityId);
            $stGallery->execute();
            $galleryImgs = $stGallery->get_result()->fetch_all(MYSQLI_ASSOC);

            // 3. Xóa ảnh trong DB trước (tránh FK lỗi)
            $delImg = $conn->prepare("DELETE FROM product_images WHERE product_id = ?");
            $delImg->bind_param("i", $entityId);
            $delImg->execute();

            // 4. Xóa product
            $stmtDel = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmtDel->bind_param("i", $entityId);
            $stmtDel->execute();

            // 5. Xóa file vật lý
            if (!empty($product['thumbnail'])) {
                deleteImage($product['thumbnail']);
            }

            foreach ($galleryImgs as $img) {
                if (!empty($img['image_url'])) {
                    deleteImage($img['image_url']);
                }
            }

            return;
    }

    // -------- DISCOUNT CODES --------
    if ($entity === 'discount_codes') {
        if ($action === 'create') {
            $code = strtoupper(trim((string)($p['code'] ?? '')));
            if ($code === '') throw new Exception('CODE rỗng');

            $chk = $conn->prepare("SELECT id FROM discount_codes WHERE code=?");
            $chk->bind_param("s", $code);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('CODE đã tồn tại');

            $dtype = $p['discount_type'] ?? 'percent';
            $dval = (float)($p['discount_value'] ?? 0);
            $minVal = (float)($p['min_order_value'] ?? 0);
            $qty = (int)($p['quantity'] ?? 0);
            $start = $p['start_date'] ?? null;
            $end = $p['end_date'] ?? null;
            $status = (int)($p['status'] ?? 1);
            $maxDiscount = ($dtype === 'percent') ? ($p['max_discount'] ?? null) : null;

            $sql = "INSERT INTO discount_codes 
                    (code, discount_type, discount_value, min_order_value, max_discount, quantity, used_count, start_date, end_date, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";
            $st = $conn->prepare($sql);
            $st->bind_param("ssdddissi", $code, $dtype, $dval, $minVal, $maxDiscount, $qty, $start, $end, $status);
            $st->execute();
            return;
        }

        if ($action === 'update') {
            if ($entityId <= 0) throw new Exception('Thiếu entity_id');

            if (array_key_exists('status_new', $p) && count($p) <= 6) {
                $statusNew = (int)($p['status_new'] ?? -1);
                $st = $conn->prepare("UPDATE discount_codes SET status=? WHERE id=?");
                $st->bind_param("ii", $statusNew, $entityId);
                $st->execute();
                return;
            }

            $st = $conn->prepare("SELECT used_count FROM discount_codes WHERE id=?");
            $st->bind_param("i", $entityId);
            $st->execute();
            $used = $st->get_result()->fetch_column();
            if ($used === null) throw new Exception('Mã không tồn tại');

            $qty = (int)($p['quantity'] ?? 0);
            if ($qty > 0 && (int)$used > $qty) throw new Exception("Quantity không thể nhỏ hơn used_count.");

            $code = strtoupper(trim((string)($p['code'] ?? '')));
            $dtype = $p['discount_type'] ?? 'percent';
            $dval = (float)($p['discount_value'] ?? 0);
            $minVal = (float)($p['min_order_value'] ?? 0);
            $maxDiscount = ($dtype === 'percent') ? ($p['max_discount'] ?? null) : null;
            $start = $p['start_date'] ?? null;
            $end = $p['end_date'] ?? null;
            $status = (int)($p['status'] ?? 1);

            $sql = "UPDATE discount_codes SET 
                    code=?, discount_type=?, discount_value=?, min_order_value=?, max_discount=?, 
                    quantity=?, start_date=?, end_date=?, status=? WHERE id=?";
            $up = $conn->prepare($sql);
            $up->bind_param("ssdddissii", $code, $dtype, $dval, $minVal, $maxDiscount, $qty, $start, $end, $status, $entityId);
            $up->execute();
            return;
        }

        if ($action === 'delete') {
            $st = $conn->prepare("SELECT used_count FROM discount_codes WHERE id=?");
            $st->bind_param("i", $entityId);
            $st->execute();
            $used = $st->get_result()->fetch_column();
            if ($used === null) throw new Exception('Mã không tồn tại');
            if ((int)$used > 0) throw new Exception("Mã đã được sử dụng.");

            $conn->prepare("DELETE FROM discount_codes WHERE id=?")->execute([$entityId]);
            $del = $conn->prepare("DELETE FROM discount_codes WHERE id=?");
            $del->bind_param("i", $entityId);
            $del->execute();
            return;
        }
    }
    throw new Exception('Chưa hỗ trợ entity hoặc action.');
}