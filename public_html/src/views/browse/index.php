<?php
/** @var array $recipes */
/** @var array $cuisines */
/** @var array $tags */
/** @var array $times */
/** @var array $opts */
?>
<div class="page" data-page="recipes-index">
  <div class="hero">
    <div class="hero-stickers">
      <div class="hero-sticker" style="top: 20%; right: 8%; background: #FFE9A8; transform: rotate(-12deg);">🍋</div>
      <div class="hero-sticker" style="top: 55%; right: 20%; background: #FFB3B3; transform: rotate(15deg);">🌶️</div>
      <div class="hero-sticker" style="top: 15%; right: 28%; background: #C8F0DC; transform: rotate(8deg); width: 60px; height: 60px; font-size: 28px;">🌿</div>
    </div>
    <div class="page-eyebrow" style="color: rgba(251,247,240,0.6); margin-bottom: 8px;">YOUR LITTLE COOKBOOK</div>
    <h1>What are<br>we cooking?</h1>
    <p style="max-width: 480px; margin-top: 14px; opacity: 0.85;">
      <?= count($recipes) ?> recipes from <?= max(0, count($cuisines) - 1) ?> cuisines. Search, filter, save, plan your week.
    </p>
  </div>

  <form method="get" action="<?= h(url_for('/')) ?>" id="filter-form" class="filter-bar" data-js="browse-filters">
    <input
      type="search"
      name="search"
      class="search-input"
      placeholder="Search recipes, cuisines, tags…"
      value="<?= h($opts['search']) ?>"
      autocomplete="off">
    <input type="hidden" name="cuisine" value="<?= h($opts['cuisine']) ?>">
    <input type="hidden" name="time"    value="<?= h($opts['time']) ?>">
    <input type="hidden" name="tag"     value="<?= h($opts['tag']) ?>">
    <div class="row" style="gap: 6px; flex-wrap: wrap;">
      <?php foreach (array_slice($cuisines, 0, 7) as $c): ?>
        <button type="submit"
                name="cuisine"
                value="<?= h($c) ?>"
                class="filter-chip <?= $opts['cuisine'] === $c ? 'active' : '' ?>"><?= h($c) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="row" style="gap: 6px; align-items: center;">
      <?php foreach ($times as $t): ?>
        <button type="submit"
                name="time"
                value="<?= h($t) ?>"
                class="filter-chip <?= $opts['time'] === $t ? 'active' : '' ?>"><?= h($t) ?></button>
      <?php endforeach; ?>
      <span style="flex: 1;"></span>
      <label class="mono" style="font-size: 12px; color: var(--ink-soft); display: flex; align-items: center; gap: 6px;">
        SORT
        <select name="sort" class="form-input" data-js="sort-select"
                style="padding: 6px 10px; font-size: 12px; width: auto;">
          <option value="title"      <?= $opts['sort'] === 'title'      ? 'selected' : '' ?>>A → Z</option>
          <option value="time"       <?= $opts['sort'] === 'time'       ? 'selected' : '' ?>>Quickest first</option>
          <option value="newest"     <?= $opts['sort'] === 'newest'     ? 'selected' : '' ?>>Newest</option>
          <option value="difficulty" <?= $opts['sort'] === 'difficulty' ? 'selected' : '' ?>>Easiest first</option>
        </select>
      </label>
    </div>
  </form>

  <?php if (count($tags) > 1): ?>
    <div class="row" style="margin-bottom: 20px; gap: 6px; overflow-x: auto;" data-js="tag-bar">
      <?php foreach (array_slice($tags, 0, 12) as $t): $active = ($opts['tag'] === $t || ($t === 'All' && $opts['tag'] === '')); ?>
        <?php
          $params = $opts;
          $params['tag'] = $t === 'All' ? '' : $t;
          $qs = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== 'All'));
          $href = url_for('/') . ($qs !== '' ? '?' . $qs : '');
        ?>
        <a href="<?= h($href) ?>"
           class="pill <?= $active ? 'pill-coral' : '' ?>"
           style="cursor: pointer; text-decoration: none;">#<?= h($t) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="page-header" style="margin-bottom: 16px;">
    <div class="page-title-wrap">
      <h2>All recipes</h2>
      <span class="page-count-pill"><?= count($recipes) ?> found</span>
    </div>
    <div class="row" style="gap:6px;">
      <button type="button" class="btn btn-sm btn-lilac" data-ai-open="suggest">✨ Suggest new</button>
    </div>
  </div>

  <?php if (count($recipes) === 0): ?>
    <div class="empty">
      <div class="empty-glyph">🤷</div>
      <div>Nothing matches. <a href="<?= h(url_for('/')) ?>">Clear filters</a>?</div>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($recipes as $recipe): ?>
        <?php require SRC_PATH . '/views/_partials/recipe-card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script type="module" src="<?= h(url_for('/assets/js/browse.js')) ?>"></script>
