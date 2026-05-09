<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    setFlash('warning', 'Vui lòng đăng nhập để xem giỏ hàng');
    $_SESSION['redirect_after_login'] = 'cart.php';
    redirect('login.php');
}

$pageTitle = 'Giỏ hàng - ' . SITE_NAME;

$cartId = getOrCreateCart($_SESSION['user_id']);

// Lấy items trong giỏ
$sql = "
    SELECT ci.*, p.name, p.price, p.thumbnail, p.quantity as stock
    FROM cart_items ci
    JOIN products p ON ci.product_id = p.id
    WHERE ci.cart_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cartId);
$stmt->execute();

$result = $stmt->get_result();
$cartItems = $result->fetch_all(MYSQLI_ASSOC);

// Tính tổng tiền
$totalAmount = 0;
foreach ($cartItems as $item) {
    $totalAmount += $item['price'] * $item['quantity'];
}

include 'includes/header.php';
?>

<div class="container">
    <div class="cart-head">
        <h1 class="cart-title">
            <i class="fas fa-shopping-cart"></i> Giỏ hàng của bạn
        </h1>
        <a href="products.php" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Tiếp tục mua sắm
        </a>
    </div>

    <?php if (empty($cartItems)): ?>
        <div class="cart-empty card">
            <div class="empty-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <h2>Giỏ hàng trống</h2>
            <p>Hãy thêm sản phẩm vào giỏ hàng để tiếp tục mua sắm.</p>
            <a href="products.php" class="btn btn-primary btn-lg">
                <i class="fas fa-shopping-bag"></i> Mua sắm ngay
            </a>
        </div>
    <?php else: ?>
        <div class="cart-layout">
            <!-- Left: Items -->
            <section class="card cart-items">
                <div class="cart-items-head">
                    <h2>Danh sách sản phẩm</h2>
                    <span class="muted"><?= count($cartItems) ?> món</span>
                </div>

                <div class="cart-list">
                    <?php foreach ($cartItems as $item): ?>
                        <div class="cart-item">
                            <a class="cart-thumb" href="product-detail.php?id=<?= $item['product_id'] ?>">
                                <img
                                    src="<?= $item['thumbnail'] ? UPLOAD_URL . e($item['thumbnail']) : BASE_URL . '/assets/images/no-image.png' ?>"
                                    alt="<?= e($item['name']) ?>"
                                >
                            </a>

                            <div class="cart-info">
                                <a class="cart-name" href="product-detail.php?id=<?= $item['product_id'] ?>">
                                    <?= e($item['name']) ?>
                                </a>
                                <div class="cart-stock">Còn <?= (int)$item['stock'] ?> sản phẩm</div>

                                <div class="cart-meta">
                                    <div class="price"><?= formatPrice($item['price']) ?></div>

                                    <form class="qty-form" action="cart-action.php" method="POST">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">

                                        <button type="button" class="qty-btn" data-step="-1" aria-label="Giảm">−</button>
                                        <input
                                            class="qty-input"
                                            type="number"
                                            name="quantity"
                                            value="<?= (int)$item['quantity'] ?>"
                                            min="1"
                                            max="<?= (int)$item['stock'] ?>"
                                        >
                                        <button type="button" class="qty-btn" data-step="1" aria-label="Tăng">+</button>

                                        <button type="submit" class="qty-save" title="Cập nhật số lượng">
                                            <i class="fas fa-rotate-right"></i>
                                        </button>
                                    </form>

                                    <div class="line-total">
                                        <?= formatPrice($item['price'] * $item['quantity']) ?>
                                    </div>

                                    <form class="remove-form" action="cart-action.php" method="POST"
                                          onsubmit="return confirm('Xác nhận xóa sản phẩm này?')">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
                                        <button type="submit" class="remove-btn" title="Xóa">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Right: Summary -->
            <aside class="card cart-summary">
                <h2>Tổng cộng</h2>

                <div class="sum-row">
                    <span>Tạm tính</span>
                    <strong><?= formatPrice($totalAmount) ?></strong>
                </div>

                <div class="sum-row">
                    <span>Phí vận chuyển</span>
                    <span class="muted">Miễn phí</span>
                </div>

                <div class="sum-divider"></div>

                <div class="sum-row sum-total">
                    <span>Tổng cộng</span>
                    <strong class="sum-price"><?= formatPrice($totalAmount) ?></strong>
                </div>

                <a href="checkout.php" class="btn btn-primary btn-lg w-full">
                    <i class="fas fa-credit-card"></i> Tiến hành đặt hàng
                </a>

                <div class="sum-note">
                    Bằng việc đặt hàng, bạn đồng ý với các điều khoản mua hàng của PhoneStore.
                </div>
            </aside>
        </div>

        <script>
        // + / - quantity (không submit ngay, bấm nút refresh để update)
        document.addEventListener('click', (e) => {
          const btn = e.target.closest('.qty-btn');
          if (!btn) return;

          const form = btn.closest('.qty-form');
          const input = form.querySelector('.qty-input');
          const step = parseInt(btn.dataset.step || '0', 10);

          const min = parseInt(input.min || '1', 10);
          const max = parseInt(input.max || '999999', 10);
          let val = parseInt(input.value || '1', 10);

          val = val + step;
          if (val < min) val = min;
          if (val > max) val = max;
          input.value = val;
        });
        </script>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>