<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$pageTitle = 'Sản phẩm - ' . SITE_NAME;

// ===== Params =====
$search = $_GET['search'] ?? '';
$brand = $_GET['brand'] ?? '';
$category = $_GET['category'] ?? '';
$price_range = $_GET['price'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// ===== Pagination =====
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$offset = ($page - 1) * $perPage;

// ===== Build WHERE =====
$where = ["p.status = 1"];
$params = [];
$types = "";

if ($search) {
    $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($brand) {
    $where[] = "p.brand_id = ?";
    $params[] = (int)$brand;
    $types .= "i";
}

if ($category) {
    $where[] = "p.category_id = ?";
    $params[] = (int)$category;
    $types .= "i";
}

if ($price_range) {
    switch ($price_range) {
        case '1': $where[] = "p.price < 5000000"; break;
        case '2': $where[] = "p.price BETWEEN 5000000 AND 10000000"; break;
        case '3': $where[] = "p.price BETWEEN 10000000 AND 20000000"; break;
        case '4': $where[] = "p.price > 20000000"; break;
    }
}

$whereClause = implode(' AND ', $where);

// ===== Sort =====
$orderBy = match($sort) {
    'price_asc' => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name' => 'p.name ASC',
    default => 'p.created_at DESC'
};

// ===== COUNT =====
$countSql = "SELECT COUNT(*) as total FROM products p WHERE $whereClause";
$stmt = $conn->prepare($countSql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total = (int)$row['total'];

$totalPages = ceil($total / $perPage);

// ===== PRODUCTS =====
$sql = "
    SELECT p.*, b.name as brand_name, c.name as category_name
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

// thêm limit + offset
$params2 = $params;
$types2 = $types . "ii";
$params2[] = $perPage;
$params2[] = $offset;

$stmt->bind_param($types2, ...$params2);
$stmt->execute();

$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);

// ===== Filters data =====
$brands = $conn->query("SELECT * FROM brands WHERE status = 1 ORDER BY name")
               ->fetch_all(MYSQLI_ASSOC);

$categories = $conn->query("SELECT * FROM categories WHERE status = 1 ORDER BY name")
                   ->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<div class="container">
    <div class="page-head">
        <h2 class="page-title">Sản phẩm</h2>
    </div>

    <div class="products-layout">
        <!-- Sidebar Filter -->
        <aside class="filter-sidebar">
            <div class="card">
                <div class="card-header filter-header">
                    <i class="fas fa-filter"></i> Bộ lọc
                </div>

                <div class="card-body">
                    <form method="GET" action="">
                        <!-- Search -->
                        <?php if ($search): ?>
                            <input type="hidden" name="search" value="<?= e($search) ?>">
                        <?php endif; ?>

                        <!-- Brand Filter -->
                        <div class="filter-block">
                            <h6 class="filter-title">Hãng</h6>
                            <div class="filter-options">
                                <?php foreach ($brands as $b): ?>
                                    <label class="radio-item">
                                        <input type="radio" name="brand"
                                            value="<?= $b['id'] ?>"
                                            <?= $brand == $b['id'] ? 'checked' : '' ?>
                                            onchange="this.form.submit()">
                                        <span><?= e($b['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($brand): ?>
                                <a class="btn btn-sm btn-link"
                                   href="?<?= http_build_query(array_diff_key($_GET, ['brand' => ''])) ?>">
                                    Xóa bộ lọc
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="divider"></div>

                        <!-- Category Filter -->
                        <div class="filter-block">
                            <h6 class="filter-title">Phân loại</h6>
                            <div class="filter-options">
                                <?php foreach ($categories as $cat): ?>
                                    <label class="radio-item">
                                        <input type="radio" name="category"
                                            value="<?= $cat['id'] ?>"
                                            <?= $category == $cat['id'] ? 'checked' : '' ?>
                                            onchange="this.form.submit()">
                                        <span><?= e($cat['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($category): ?>
                                <a class="btn btn-sm btn-link"
                                   href="?<?= http_build_query(array_diff_key($_GET, ['category' => ''])) ?>">
                                    Xóa bộ lọc
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="divider"></div>

                        <!-- Price Filter -->
                        <div class="filter-block">
                            <h6 class="filter-title">Khoảng giá</h6>

                            <div class="filter-options">
                                <label class="radio-item">
                                    <input type="radio" name="price" value="1"
                                        <?= $price_range == '1' ? 'checked' : '' ?>
                                        onchange="this.form.submit()">
                                    <span>Dưới 5 triệu</span>
                                </label>

                                <label class="radio-item">
                                    <input type="radio" name="price" value="2"
                                        <?= $price_range == '2' ? 'checked' : '' ?>
                                        onchange="this.form.submit()">
                                    <span>5 - 10 triệu</span>
                                </label>

                                <label class="radio-item">
                                    <input type="radio" name="price" value="3"
                                        <?= $price_range == '3' ? 'checked' : '' ?>
                                        onchange="this.form.submit()">
                                    <span>10 - 20 triệu</span>
                                </label>

                                <label class="radio-item">
                                    <input type="radio" name="price" value="4"
                                        <?= $price_range == '4' ? 'checked' : '' ?>
                                        onchange="this.form.submit()">
                                    <span>Trên 20 triệu</span>
                                </label>
                            </div>

                            <?php if ($price_range): ?>
                                <a class="btn btn-sm btn-link"
                                   href="?<?= http_build_query(array_diff_key($_GET, ['price' => ''])) ?>">
                                    Xóa bộ lọc
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </aside>

        <!-- Products Grid -->
        <main class="products-main">
            <!-- Sort -->
            <div class="products-toolbar">
                <div class="products-count">
                    Tìm thấy <strong><?= (int)$total ?></strong> sản phẩm
                </div>

                <form method="GET" class="sort-form">
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if ($key !== 'sort'): ?>
                            <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <label class="sort-label">Sắp xếp:</label>
                    <select name="sort" class="select" onchange="this.form.submit()">
                        <option value="newest" <?= $sort == 'newest' ? 'selected' : '' ?>>Mới nhất</option>
                        <option value="price_asc" <?= $sort == 'price_asc' ? 'selected' : '' ?>>Giá tăng dần</option>
                        <option value="price_desc" <?= $sort == 'price_desc' ? 'selected' : '' ?>>Giá giảm dần</option>
                        <option value="name" <?= $sort == 'name' ? 'selected' : '' ?>>Tên A-Z</option>
                    </select>
                </form>
            </div>

            <!-- Products -->
            <?php if (empty($products)): ?>
                <div class="alert alert-info">
                    <span><i class="fas fa-info-circle"></i> Không tìm thấy sản phẩm nào.</span>
                </div>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="card product-card">
                            <a href="product-detail.php?id=<?= $product['id'] ?>" class="product-thumb">
                                <img
                                    src="<?= $product['thumbnail'] ? UPLOAD_URL . e($product['thumbnail']) : BASE_URL . '/assets/images/no-image.png' ?>"
                                    alt="<?= e($product['name']) ?>"
                                    class="product-img"
                                >
                                <span class="cat-badge"><?= e($product['category_name'] ?? '') ?></span>
                            </a>

                            <div class="card-body">
                                <h6 class="card-title">
                                    <a href="product-detail.php?id=<?= $product['id'] ?>">
                                        <?= e($product['name']) ?>
                                    </a>
                                </h6>

                                <div class="card-meta">
                                    <i class="fas fa-tag"></i> <?= e($product['brand_name']) ?>
                                </div>

                                <div class="product-row">
                                    <div class="card-price"><?= formatPrice($product['price']) ?></div>
                                    <div class="badge"><?= (int)$product['quantity'] ?> sản phẩm</div>
                                </div>

                                <?php if ((int)$product['quantity'] > 0): ?>
                                    <form action="cart-action.php" method="POST">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-cart-plus"></i> Thêm vào giỏ
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-disabled" disabled>Hết hàng</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav class="pagination-wrap">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li>
                                    <a class="page-link"
                                       href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        &laquo; Trước
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li>
                                    <a class="page-link <?= $i == $page ? 'active' : '' ?>"
                                       href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li>
                                    <a class="page-link"
                                       href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        Sau &raquo;
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</div>
<?php include 'includes/footer.php'; ?>