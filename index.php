<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$pageTitle = 'Trang chủ - ' . SITE_NAME;

// Lấy sản phẩm nổi bật
$result = $conn->query("
    SELECT p.*, b.name as brand_name, c.name as category_name
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 1
    ORDER BY p.created_at DESC
    LIMIT 8
");

$featuredProducts = $result->fetch_all(MYSQLI_ASSOC);

// Lấy brands
$result = $conn->query("
    SELECT b.*, COUNT(p.id) AS product_count
    FROM brands b
    JOIN products p ON p.brand_id = b.id
    WHERE b.status = 1
    GROUP BY b.id
    ORDER BY product_count DESC
    LIMIT 4
");

$brands = $result->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<div class="container">
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-content">
            <div class="hero-text">
                <h1>Chào mừng đến PhoneStore</h1>
                <p>Điện thoại chính hãng, giá tốt nhất thị trường. Giao hàng nhanh chóng toàn quốc với dịch vụ chăm sóc
                    khách hàng tận tâm 24/7.</p>
                <a href="products.php" class="btn btn-light btn-lg">
                    <i class="fas fa-shopping-bag"></i>
                    <span>Mua sắm ngay</span>
                </a>
            </div>
            <div class="hero-image">
                <i class="fas fa-mobile-screen-button"></i>
            </div>
        </div>
    </div>
    <!-- Categories/Brands -->
    <div class="section-title">
        <h2>Thương hiệu điện thoại</h2>
        <p>Chọn thương hiệu yêu thích của bạn</p>
    </div>

    <div class="grid grid-4 mb-5">
        <?php foreach ($brands as $brand): ?>
        <a href="products.php?brand=<?= $brand['id'] ?>" class="brand-card">
            <i class="fas fa-mobile-alt"></i>
            <h5><?= e($brand['name']) ?></h5>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Featured Products -->
    <div class="section-title">
        <h2>Sản phẩm nổi bật</h2>
        <p>Những sản phẩm được yêu thích nhất</p>
    </div>

    <div class="grid grid-4 mb-5">
        <?php foreach ($featuredProducts as $product): ?>
        <div class="card product-card">
            <a href="product-detail.php?id=<?= $product['id'] ?>">
                <img src="<?= $product['thumbnail'] ? UPLOAD_URL . e($product['thumbnail']) : BASE_URL . '/assets/images/no-image.png' ?>"
                    class="card-img" alt="<?= e($product['name']) ?>">
            </a>

            <div class="card-body">
                <h6 class="card-title">
                    <a href="product-detail.php?id=<?= $product['id'] ?>">
                        <?= e($product['name']) ?>
                    </a>
                </h6>

                <div class="card-meta">
                    <i class="fas fa-tag"></i>
                    <span><?= e($product['brand_name']) ?></span>
                </div>

                <div class="card-price">
                    <?= formatPrice($product['price']) ?>
                </div>

                <?php if ($product['quantity'] > 0): ?>
                <form action="cart-action.php" method="POST">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-cart-plus"></i>
                        <span>Thêm vào giỏ</span>
                    </button>
                </form>
                <?php else: ?>
                <button class="btn btn-primary btn-sm" disabled>
                    <span>Hết hàng</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mb-5">
        <a href="products.php" class="btn btn-outline btn-lg">
            <span>Xem tất cả sản phẩm</span>
            <i class="fas fa-arrow-right"></i>
        </a>
    </div>

    <!-- Features -->
    <div class="section-title">
        <h2>Tại sao chọn chúng tôi?</h2>
        <p>Những lý do bạn nên mua sắm tại PhoneStore</p>
    </div>

    <div class="features-grid mb-5">
        <div class="feature-card">
            <i class="fas fa-truck"></i>
            <h5>Giao hàng nhanh</h5>
            <p>Giao hàng toàn quốc trong 24h. Miễn phí vận chuyển cho đơn hàng trên 5 triệu.</p>
        </div>

        <div class="feature-card">
            <i class="fas fa-shield-alt"></i>
            <h5>Bảo hành chính hãng</h5>
            <p>Bảo hành 12 tháng tại các trung tâm bảo hành chính hãng trên toàn quốc.</p>
        </div>

        <div class="feature-card">
            <i class="fas fa-exchange-alt"></i>
            <h5>Đổi trả dễ dàng</h5>
            <p>Đổi trả trong vòng 7 ngày nếu sản phẩm có lỗi từ nhà sản xuất.</p>
        </div>

        <div class="feature-card">
            <i class="fas fa-headset"></i>
            <h5>Hỗ trợ 24/7</h5>
            <p>Đội ngũ tư vấn viên nhiệt tình, chuyên nghiệp luôn sẵn sàng hỗ trợ bạn.</p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>