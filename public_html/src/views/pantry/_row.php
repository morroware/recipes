<?php
/** @var array $row */
/** @var bool|null $showRestock */
$showRestock = $showRestock ?? false;
$inStock = (int)$row['in_stock'] === 1;
$ago = pantry_time_ago($row['last_bought'] ?? null);
$cat = $row['category'] ?? 'Other';
$glyph = PANTRY_CATEGORY_GLYPHS[$cat] ?? '📦';
?>
<div class="pantry-row<?= $inStock ? '' : ' oos' ?>" data-id="<?= (int)$row['id'] ?>" data-name="<?= h($row['name']) ?>">
  <button type="button"
          class="pantry-check<?= $inStock ? ' checked' : '' ?>"
          data-action="toggle-stock"
          aria-pressed="<?= $inStock ? 'true' : 'false' ?>"
          aria-label="<?= $inStock ? 'Mark out of stock' : 'Mark in stock' ?>"
          title="<?= $inStock ? 'Mark out of stock' : 'Mark in stock' ?>"></button>
  <div class="pantry-row-main">
    <div class="pantry-row-name"><?= h($row['name']) ?></div>
    <div class="pantry-row-meta">
      <span class="pantry-row-cat" data-action="edit-category" title="Change category">
        <span data-js="cat-glyph"><?= h($glyph) ?></span>
        <span data-js="cat-label"><?= h($cat) ?></span>
      </span>
      <select class="pantry-cat-select" data-action="set-category" hidden>
        <?php foreach (PANTRY_CATEGORIES as $c): ?>
          <option value="<?= h($c) ?>" <?= $c === $cat ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ((int)$row['purchase_count'] > 0): ?>
        <span class="pantry-row-stat">×<?= (int)$row['purchase_count'] ?> bought</span>
      <?php endif; ?>
      <?php if ($ago): ?>
        <span class="pantry-row-stat">last: <?= h($ago) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($showRestock): ?>
    <button type="button" class="btn btn-sm btn-mint" data-action="shop" title="Add to shopping list">+ Shop</button>
  <?php endif; ?>
  <button type="button" class="btn btn-sm btn-ghost" data-action="remove" title="Remove from pantry">✕</button>
</div>
