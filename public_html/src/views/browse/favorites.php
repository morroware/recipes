<?php /** @var array $recipes */ ?>
<div class="page">
  <div class="page-header" style="margin: 24px 0 18px;">
    <div class="page-title-wrap">
      <h1>♥ Favorites</h1>
      <span class="page-count-pill"><?= count($recipes) ?> saved</span>
    </div>
  </div>

  <?php if (count($recipes) === 0): ?>
    <div class="empty">
      <div class="empty-glyph">💔</div>
      <div>No favorites yet. Tap the heart on any recipe.</div>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($recipes as $recipe): ?>
        <?php require SRC_PATH . '/views/_partials/recipe-card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
