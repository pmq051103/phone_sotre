 <!-- Flash Messages -->
    <?php
    $flash = getFlash();
    if ($flash):
    ?>
    <div class="container">
        <div class="alert alert-<?= $flash['type'] ?>">
            <span><?= e($flash['message']) ?></span>
            <button type="button" class="btn-close" onclick="this.parentElement.remove()">×</button>
        </div>
    </div>
    <?php endif; ?>