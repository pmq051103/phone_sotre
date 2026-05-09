<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$productId = (int)($_GET['id'] ?? 0);
$orderId   = (int)($_GET['order_id'] ?? 0);

// ===== LẤY SẢN PHẨM =====
$stmt = $conn->prepare("
    SELECT p.*, b.name as brand_name, c.name as category_name
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ? AND p.status = 1
");
$stmt->bind_param("i", $productId);
$stmt->execute();

$result = $stmt->get_result();
$product = $result->fetch_assoc();

if (!$product) {
    header('HTTP/1.0 404 Not Found');
    die('Sản phẩm không tồn tại');
}

$pageTitle = $product['name'] . ' - ' . SITE_NAME;

// ===== ẢNH =====
$stmt = $conn->prepare("SELECT * FROM product_images WHERE product_id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();

$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ===== REVIEWS =====
$stmt = $conn->prepare("
    SELECT r.*, u.full_name 
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.product_id = ? AND r.status = 1
    ORDER BY r.created_at DESC
");
$stmt->bind_param("i", $productId);
$stmt->execute();

$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ===== RATING =====
$stmt = $conn->prepare("
    SELECT AVG(rating) as avg_rating, COUNT(*) as total 
    FROM reviews 
    WHERE product_id = ? AND status = 1
");
$stmt->bind_param("i", $productId);
$stmt->execute();

$ratingData = $stmt->get_result()->fetch_assoc();

$avgRating = round($ratingData['avg_rating'] ?? 0, 1);
$totalReviews = (int)($ratingData['total'] ?? 0);

// ===== RELATED =====
$stmt = $conn->prepare("
    SELECT p.*, b.name as brand_name
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE p.brand_id = ? AND p.id != ? AND p.status = 1
    LIMIT 4
");
$stmt->bind_param("ii", $product['brand_id'], $productId);
$stmt->execute();

$relatedProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ===== REVIEW LOGIC =====
$canReview = false;
$hasReviewed = false;
$validOrderForThisProduct = false;

if (isLoggedIn() && $orderId > 0) {
    $uid = (int)($_SESSION['user_id'] ?? 0);

    // check order hợp lệ
    $st = $conn->prepare("
        SELECT 1
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.id = ?
          AND o.user_id = ?
          AND o.status = 'completed'
          AND oi.product_id = ?
        LIMIT 1
    ");
    $st->bind_param("iii", $orderId, $uid, $productId);
    $st->execute();

    $validOrderForThisProduct = (bool)$st->get_result()->fetch_row();

    // check đã review chưa
    $st = $conn->prepare("
        SELECT id 
        FROM reviews 
        WHERE order_id=? AND product_id=? 
        LIMIT 1
    ");
    $st->bind_param("ii", $orderId, $productId);
    $st->execute();

    $hasReviewed = (bool)$st->get_result()->fetch_row();

    $canReview = $validOrderForThisProduct && !$hasReviewed;
}

include 'includes/header.php';
?>
<div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="index.php">Trang chủ</a>
        <span class="sep">/</span>
        <a href="products.php">Sản phẩm</a>
        <span class="sep">/</span>
        <span class="current"><?= e($product['name']) ?></span>
    </div>

    <div class="pd-layout">
        <!-- Left: Gallery -->
        <section class="pd-gallery card">
            <div class="pd-main-img">
                <img src="<?= $product['thumbnail'] ? UPLOAD_URL . e($product['thumbnail']) : BASE_URL . '/assets/images/no-image.png' ?>"
                    id="mainImage" alt="<?= e($product['name']) ?>">
            </div>

            <?php if (!empty($images)): ?>
            <div class="pd-thumbs">
                <?php foreach ($images as $img): ?>
                <button type="button" class="pd-thumb"
                    onclick="document.getElementById('mainImage').src=this.querySelector('img').src"
                    aria-label="Xem ảnh">
                    <img src="<?= UPLOAD_URL . e($img['image_url']) ?>" alt="thumb">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- Right: Info -->
        <section class="pd-info">
            <h1 class="pd-title"><?= e($product['name']) ?></h1>

            <div class="pd-rating">
                <?php if ($totalReviews > 0): ?>
                <div class="stars" aria-label="Đánh giá">
                    <?php
                        $starFill = (int)round($avgRating);
                        for ($i = 1; $i <= 5; $i++):
                        ?>
                    <i class="fas fa-star <?= $i <= $starFill ? 'is-on' : 'is-off' ?>"></i>
                    <?php endfor; ?>
                </div>
                <div class="rating-text">
                    <strong><?= $avgRating ?></strong>/5 (<?= (int)$totalReviews ?> đánh giá)
                </div>
                <?php else: ?>
                <div class="rating-text muted">Chưa có đánh giá</div>
                <?php endif; ?>
            </div>

            <div class="pd-tags">
                <span class="tag tag-primary"><?= e($product['brand_name']) ?></span>
                <span class="tag tag-gray"><?= e($product['category_name']) ?></span>
            </div>

            <div class="pd-price"><?= formatPrice($product['price']) ?></div>

            <!-- Specs -->
            <div class="card pd-specs">
                <div class="pd-specs-head">
                    <h3>Thông số</h3>
                </div>
                <div class="pd-specs-body">
                    <div class="spec-row">
                        <div class="spec-k">RAM</div>
                        <div class="spec-v"><?= e($product['ram']) ?></div>
                    </div>
                    <div class="spec-row">
                        <div class="spec-k">ROM</div>
                        <div class="spec-v"><?= e($product['rom']) ?></div>
                    </div>
                    <div class="spec-row">
                        <div class="spec-k">CPU</div>
                        <div class="spec-v"><?= e($product['cpu']) ?></div>
                    </div>
                    <div class="spec-row">
                        <div class="spec-k">Camera</div>
                        <div class="spec-v"><?= e($product['camera']) ?></div>
                    </div>
                    <div class="spec-row">
                        <div class="spec-k">Pin</div>
                        <div class="spec-v"><?= e($product['battery']) ?></div>
                    </div>

                    <div class="spec-row">
                        <div class="spec-k">Tình trạng</div>
                        <div class="spec-v">
                            <?php if ((int)$product['quantity'] > 0): ?>
                            <span class="stock ok">
                                <i class="fas fa-check-circle"></i>
                                Còn hàng (<?= (int)$product['quantity'] ?>)
                            </span>
                            <?php else: ?>
                            <span class="stock out">
                                <i class="fas fa-times-circle"></i>
                                Hết hàng
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add to cart -->
            <?php if ((int)$product['quantity'] > 0): ?>
            <form action="cart-action.php" method="POST" class="pd-buy card">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">

                <div class="pd-buy-row">
                    <label class="field">
                        <span class="label">Số lượng</span>
                        <input class="input" type="number" name="quantity" value="1" min="1"
                            max="<?= (int)$product['quantity'] ?>">
                    </label>

                    <button type="submit" class="btn btn-primary btn-lg pd-buy-btn">
                        <i class="fas fa-cart-plus"></i> Thêm vào giỏ hàng
                    </button>
                </div>

                <a href="products.php" class="btn btn-outline btn-lg pd-back-btn">
                    <i class="fas fa-arrow-left"></i> Tiếp tục mua sắm
                </a>
            </form>
            <?php else: ?>
            <div class="alert alert-warning">
                <span><i class="fas fa-exclamation-triangle"></i> Sản phẩm tạm thời hết hàng</span>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Tabs: Description + Reviews -->
    <section class="pd-tabs card">
        <div class="tabs-head">
            <button class="tab-btn is-active" type="button" data-tab="desc">Mô tả sản phẩm</button>
            <button class="tab-btn" type="button" data-tab="reviews">Đánh giá (<?= (int)$totalReviews ?>)</button>
        </div>

        <div class="tabs-body">
            <?php
                $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><h1><h2><h3><h4><blockquote><span>';
                $descSafe = strip_tags($product['description'] ?? '', $allowed);
                ?>
            <div class="tab-panel is-active" id="tab-desc">
                <?= $descSafe ?: '<em>Chưa có mô tả</em>' ?>
            </div>

           <div class="tab-panel" id="tab-reviews">
    <?php if (!isLoggedIn()): ?>
        <div class="alert alert-info">
            <span>Vui lòng <a href="login.php">đăng nhập</a> để đánh giá sản phẩm.</span>
        </div>

    <?php else: ?>

        <?php if ($orderId <= 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                Để đánh giá, bạn hãy vào <a href="user/orders.php">Đơn hàng</a> → chọn đơn <strong>Hoàn tất</strong> → bấm <strong>Đánh giá</strong>.
            </div>

        <?php elseif (!$validOrderForThisProduct): ?>
            <div class="alert alert-warning">
                <i class="fas fa-lock"></i>
                Bạn chỉ có thể đánh giá sản phẩm này thông qua <strong>đơn hàng đã hoàn tất</strong> có chứa sản phẩm.
            </div>

        <?php elseif ($hasReviewed): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Bạn đã đánh giá sản phẩm này cho đơn hàng này rồi. Cảm ơn bạn!
            </div>

        <?php else: ?>
            <div class="card pd-review-form">
                <div class="card-body">
                    <h3 class="pd-h3">Viết đánh giá của bạn</h3>

                    <form action="review-action.php" method="POST" class="review-form">
                        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">

                        <div class="field">
                            <span class="label">Đánh giá</span>
                            <div class="star-input">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <input type="radio" name="rating" value="<?= $i ?>" id="star<?= $i ?>" required>
                                    <label for="star<?= $i ?>" title="<?= $i ?> sao"><i class="fas fa-star"></i></label>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="field">
                            <span class="label">Nhận xét</span>
                            <textarea name="comment" class="textarea" rows="3"
                                placeholder="Chia sẻ trải nghiệm của bạn..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top:6px;">
                            <i class="fas fa-paper-plane"></i> Gửi đánh giá
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <!-- List reviews -->
    <?php if (empty($reviews)): ?>
        <p class="muted">Chưa có đánh giá nào.</p>
    <?php else: ?>
        <div class="pd-reviews">
            <?php foreach ($reviews as $review): ?>
                <div class="card review-item">
                    <div class="card-body">
                        <div class="review-head">
                            <div>
                                <strong><?= e($review['full_name']) ?></strong>
                                <div class="stars small">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?= $i <= (int)$review['rating'] ? 'is-on' : 'is-off' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="muted"><?= formatDate($review['created_at']) ?></div>
                        </div>

                        <?php if (!empty($review['comment'])): ?>
                            <p class="review-comment"><?= e($review['comment']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

        </div>
    </section>

    <!-- Related Products -->
    <?php if (!empty($relatedProducts)): ?>
    <section class="pd-related">
        <h2 class="pd-h2">Sản phẩm liên quan</h2>

        <div class="pd-related-grid">
            <?php foreach ($relatedProducts as $prod): ?>
            <div class="card product-card">
                <a href="product-detail.php?id=<?= $prod['id'] ?>" class="product-thumb">
                    <img src="<?= $prod['thumbnail'] ? UPLOAD_URL . e($prod['thumbnail']) : BASE_URL . '/assets/images/no-image.png' ?>"
                        class="product-img" alt="<?= e($prod['name']) ?>">
                </a>

                <div class="card-body">
                    <h6 class="card-title">
                        <a href="product-detail.php?id=<?= $prod['id'] ?>">
                            <?= e($prod['name']) ?>
                        </a>
                    </h6>

                    <div class="card-price"><?= formatPrice($prod['price']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<script>
(function() {
    // Tabs
    const btns = document.querySelectorAll('.tab-btn');
    const panels = {
        desc: document.getElementById('tab-desc'),
        reviews: document.getElementById('tab-reviews')
    };

    btns.forEach(b => b.addEventListener('click', () => {
        btns.forEach(x => x.classList.remove('is-active'));
        b.classList.add('is-active');

        Object.values(panels).forEach(p => p.classList.remove('is-active'));
        const key = b.dataset.tab;
        if (key && panels[key]) panels[key].classList.add('is-active');
    }));
})();
</script>
<?php include 'includes/footer.php'; ?>