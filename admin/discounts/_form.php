<?php
// expects: $data (array)
function v($k, $default=''){
  global $data;
  return $data[$k] ?? $default;
}
?>

<div class="admin-grid-2">
  <div>
    <label style="font-weight:800;">CODE *</label>
    <input class="input" name="code" required value="<?= e(v('code')) ?>" placeholder="VD: SALE10, TET2026">
    <div style="opacity:.7; font-size:12px; margin-top:6px;">Nên viết HOA, không dấu cách.</div>
  </div>

  <div>
    <label style="font-weight:800;">Trạng thái</label>
    <select class="input" name="status">
      <option value="1" <?= (int)v('status',1)===1?'selected':'' ?>>Hiển thị</option>
      <option value="0" <?= (int)v('status',1)===0?'selected':'' ?>>Ẩn</option>
    </select>
  </div>

  <div>
    <label style="font-weight:800;">Loại giảm *</label>
    <select class="input" name="discount_type" id="dcType" required>
      <option value="percent" <?= v('discount_type','percent')==='percent'?'selected':'' ?>>Giảm theo %</option>
      <option value="fixed" <?= v('discount_type','percent')==='fixed'?'selected':'' ?>>Giảm số tiền</option>
    </select>
  </div>

  <div>
    <label style="font-weight:800;">Giá trị *</label>
    <input class="input" name="discount_value" required value="<?= e(v('discount_value')) ?>" placeholder="VD: 10 hoặc 50000">
  </div>

  <div>
    <label style="font-weight:800;">Min order</label>
    <input class="input" name="min_order_value" value="<?= e(v('min_order_value',0)) ?>" placeholder="VD: 200000">
  </div>

  <div>
    <label style="font-weight:800;">Max discount (chỉ %)</label>
    <input class="input" name="max_discount" id="dcMax" value="<?= e(v('max_discount')) ?>" placeholder="VD: 100000">
  </div>

  <div>
    <label style="font-weight:800;">Số lượt dùng (quantity)</label>
    <input class="input" name="quantity" value="<?= e(v('quantity',0)) ?>" placeholder="0 = không giới hạn">
  </div>

  <div>
    <label style="font-weight:800;">Used count</label>
    <input class="input" value="<?= e(v('used_count',0)) ?>" disabled>
    <div style="opacity:.7; font-size:12px; margin-top:6px;">Tự tăng khi dùng mã (log).</div>
  </div>

  <div>
    <label style="font-weight:800;">Start date</label>
    <input class="input" type="datetime-local" name="start_date"
      value="<?= v('start_date') ? e(date('Y-m-d\TH:i', strtotime(v('start_date')))) : '' ?>">
  </div>

  <div>
    <label style="font-weight:800;">End date</label>
    <input class="input" type="datetime-local" name="end_date"
      value="<?= v('end_date') ? e(date('Y-m-d\TH:i', strtotime(v('end_date')))) : '' ?>">
  </div>
</div>

<script>
(function(){
  const typeEl = document.getElementById('dcType');
  const maxEl  = document.getElementById('dcMax');
  function sync(){
    if(!typeEl || !maxEl) return;
    const isPercent = typeEl.value === 'percent';
    maxEl.disabled = !isPercent;
    maxEl.placeholder = isPercent ? 'VD: 100000' : 'Không áp dụng';
    if (!isPercent) maxEl.value = '';
  }
  typeEl?.addEventListener('change', sync);
  sync();
})();
</script>
