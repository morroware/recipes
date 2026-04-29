<?php
/** @var array $items */
/** @var int   $checkedCount */
$total = count($items);
?>
<div class="page" data-page="shopping">
  <div class="page-header">
    <div class="page-title-wrap">
      <div>
        <div class="page-eyebrow">GROCERIES</div>
        <h1>Shopping list 🛒</h1>
      </div>
      <?php if ($total > 0): ?>
        <span class="page-count-pill" data-js="count-pill">
          <span data-js="count-checked"><?= (int)$checkedCount ?></span>/<span data-js="count-total"><?= (int)$total ?></span> ✓
        </span>
      <?php endif; ?>
    </div>
    <div class="row no-print">
      <button type="button"
              class="btn btn-sm btn-mint"
              data-js="stock-pantry"
              <?= $checkedCount === 0 ? 'hidden' : '' ?>
              title="Remove checked items from list and add them to your pantry">
        🥕 Stock pantry (<span data-js="stock-count"><?= (int)$checkedCount ?></span>)
      </button>
      <button type="button" class="btn btn-sm" data-js="print-btn">🖨️ Print</button>
      <?php if ($total > 0): ?>
        <button type="button" class="btn btn-sm" data-js="clear-all">Clear all</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="pantry-panel no-print" style="margin-bottom: 20px;">
    <form class="row" data-js="add-form">
      <input class="search-input" name="name" placeholder="add an item…" autocomplete="off" required>
      <button type="submit" class="btn btn-primary">Add</button>
    </form>
  </div>

  <?php if ($total === 0): ?>
    <div class="empty" data-js="empty">
      <div class="empty-glyph">🛒</div>
      <div>List is empty. Add items here, or add ingredients from a recipe.</div>
    </div>
  <?php else: ?>
    <div class="pantry-panel" data-js="list">
      <?php foreach ($items as $item): ?>
        <?php require SRC_PATH . '/views/shopping/_row.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script type="module" src="/assets/js/shopping.js"></script>
