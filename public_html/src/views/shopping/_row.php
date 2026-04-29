<?php
/** @var array $item */
if (!function_exists('shopping_trim_qty')) {
    function shopping_trim_qty($v): string {
        if ($v === null || $v === '') return '';
        $s = (string)$v;
        if (strpos($s, '.') === false) return $s;
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
$checked = (int)$item['checked'] === 1;
$source  = (string)$item['source_label'];
$qty     = $item['qty'];
$unit    = (string)$item['unit'];
?>
<div class="shop-row" data-id="<?= (int)$item['id'] ?>" data-name="<?= h($item['name']) ?>">
  <button type="button"
          class="shop-check<?= $checked ? ' checked' : '' ?>"
          data-action="toggle"
          aria-pressed="<?= $checked ? 'true' : 'false' ?>"
          aria-label="<?= $checked ? 'Uncheck' : 'Check' ?>"></button>
  <span class="shop-name<?= $checked ? ' checked' : '' ?>" style="flex: 1; font-weight: 500;">
    <?= h($item['name']) ?>
  </span>
  <?php if ($qty !== null && $qty !== ''): ?>
    <span class="shop-qty"><?= h(shopping_trim_qty($qty)) ?> <?= h($unit) ?></span>
  <?php endif; ?>
  <?php if ($source !== '' && $source !== 'manual'): ?>
    <span class="shop-source">from <?= h($source) ?></span>
  <?php endif; ?>
  <button type="button" class="btn btn-sm btn-ghost no-print" data-action="remove" title="Remove">✕</button>
</div>
