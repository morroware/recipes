<?php
/** @var array $items */
/** @var array $inStock */
/** @var array $oos */
/** @var array $grouped */
/** @var array $mostUsed */
/** @var array $suggestions */
/** @var string $mode */
/** @var array $tags */
/** @var array $tagResults */
?>
<div class="page" data-page="pantry">
  <div class="page-header">
    <div class="page-title-wrap">
      <div>
        <div class="page-eyebrow">YOUR KITCHEN</div>
        <h1>Pantry 🥕</h1>
      </div>
      <span class="page-count-pill">
        <?= count($inStock) ?> in stock<?php if (count($oos) > 0): ?> · <?= count($oos) ?> out<?php endif; ?>
      </span>
    </div>
  </div>

  <div class="row" style="margin-bottom: 24px; gap: 6px;" data-js="pantry-mode">
    <a href="<?= h(url_for('/pantry')) ?>"
       class="filter-chip <?= $mode === 'pantry' ? 'active' : '' ?>">📦 Inventory &amp; suggestions</a>
    <a href="<?= h(url_for('/pantry')) ?>?mode=tag"
       class="filter-chip <?= $mode === 'tag' ? 'active' : '' ?>">🔎 Find by ingredient</a>
  </div>

  <?php if ($mode === 'pantry'): ?>
    <div class="pantry-grid">
      <!-- Left: inventory -->
      <div class="pantry-panel">
        <h3 style="margin-bottom: 12px;">Inventory</h3>
        <p class="muted" style="font-size: 13px; margin-bottom: 14px;">
          Tap the checkbox to mark in stock / out. Items stay in your kitchen list either way.
        </p>

        <form class="row" style="margin-bottom: 14px;" data-js="pantry-add">
          <input
            class="search-input"
            name="name"
            placeholder="add an ingredient + Enter…"
            autocomplete="off"
            required>
          <button type="submit" class="btn btn-primary">Add</button>
        </form>

        <?php if (!empty($mostUsed)): ?>
          <div style="margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1.5px dashed rgba(31,27,22,0.18);">
            <div class="page-eyebrow" style="margin-bottom: 8px;">⭐ MOST USED</div>
            <div class="row" style="gap: 6px; flex-wrap: wrap;">
              <?php foreach ($mostUsed as $m): ?>
                <span class="pill"
                      style="background: <?= ((int)$m['in_stock']) ? 'var(--mint)' : 'transparent' ?>;
                             opacity: <?= ((int)$m['in_stock']) ? 1 : 0.6 ?>;">
                  <?= h($m['name']) ?>
                  <span class="muted" style="font-size: 11px; margin-left: 4px;">×<?= (int)$m['purchase_count'] ?></span>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (count($inStock) === 0): ?>
          <div class="muted" style="font-size: 13px; padding: 18px 0;" data-js="empty-stock">
            Nothing in stock. Add some ingredients above.
          </div>
        <?php else: ?>
          <div data-js="pantry-groups">
            <?php foreach (PANTRY_CATEGORIES as $cat): $bucket = $grouped[$cat] ?? []; if (!$bucket) continue; ?>
              <div class="pantry-cat-group" data-cat="<?= h($cat) ?>">
                <div class="pantry-cat-header">
                  <span class="pantry-cat-glyph"><?= h(PANTRY_CATEGORY_GLYPHS[$cat]) ?></span>
                  <span class="pantry-cat-name"><?= h($cat) ?></span>
                  <span class="pantry-cat-count"><?= count($bucket) ?></span>
                </div>
                <?php foreach ($bucket as $row): ?>
                  <?php require SRC_PATH . '/views/pantry/_row.php'; ?>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (count($oos) > 0): ?>
          <div style="margin-top: 18px; padding-top: 14px; border-top: 2.5px dashed rgba(31,27,22,0.25);" data-js="pantry-oos">
            <button type="button" class="pantry-oos-toggle" aria-expanded="false">
              <span style="flex: 1; text-align: left;">
                <span data-js="oos-caret">▸</span> Out of stock
                <span class="muted" style="font-weight: 500;">(<?= count($oos) ?>)</span>
              </span>
            </button>
            <div data-js="oos-list" style="display: none; margin-top: 10px;">
              <?php foreach ($oos as $row): $showRestock = true; ?>
                <?php require SRC_PATH . '/views/pantry/_row.php'; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right: suggestions -->
      <div class="pantry-panel">
        <h3 style="margin-bottom: 12px;">You can make…</h3>
        <p class="muted" style="font-size: 13px; margin-bottom: 14px;">
          Sorted by % match using your <strong>in-stock</strong> items only.
        </p>
        <?php if (count($inStock) === 0): ?>
          <div class="empty" style="padding: 30px;">
            <div class="empty-glyph">🤔</div>
            <div>Mark some items in stock to see suggestions.</div>
          </div>
        <?php else: ?>
          <?php foreach ($suggestions as $s): $r = $s['recipe']; $colors = STICKER_COLORS[$r['color']] ?? STICKER_COLORS['mint']; ?>
            <a class="suggest-card" href="<?= h(url_for('/recipes/' . (int)$r['id'])) ?>" style="text-decoration: none; color: inherit;">
              <div class="suggest-card-glyph"
                   style="<?= !empty($r['photo_url'])
                     ? 'background-image: url(' . h($r['photo_url']) . '); background-size: cover; background-position: center;'
                     : 'background: ' . h($colors['bg']) . ';' ?>">
                <?php if (empty($r['photo_url'])): ?><?= h($r['glyph']) ?><?php endif; ?>
              </div>
              <div class="suggest-card-info">
                <div class="suggest-card-title"><?= h($r['title']) ?></div>
                <div class="suggest-card-meta">
                  <?= h($r['cuisine']) ?> · <?= (int)$r['time_minutes'] ?>m · need <?= count($s['missing']) ?> more
                </div>
                <div class="match-bar"><div class="match-bar-fill" style="width: <?= (int)round($s['pct'] * 100) ?>%;"></div></div>
              </div>
              <div class="mono" style="font-weight: 800; font-size: 18px;"><?= (int)round($s['pct'] * 100) ?>%</div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  <?php else: /* tag mode */ ?>
    <div class="pantry-panel">
      <h3 style="margin-bottom: 12px;">Find recipes containing all of:</h3>
      <form class="row" style="margin-bottom: 14px;" method="get" action="<?= h(url_for('/pantry')) ?>" data-js="pantry-tag-form">
        <input type="hidden" name="mode" value="tag">
        <input type="hidden" name="tags" value="<?= h(implode(',', $tags)) ?>" data-js="tags-hidden">
        <input class="search-input"
               type="text"
               placeholder="ingredient + Enter…"
               autocomplete="off"
               data-js="tag-draft">
        <button type="button" class="btn btn-primary" data-js="tag-add">Add</button>
      </form>

      <div style="margin-bottom: 18px;" data-js="tag-list">
        <?php foreach ($tags as $t): ?>
          <a href="<?php
              $rest = array_values(array_filter($tags, fn($x) => $x !== $t));
              echo h(url_for('/pantry')) . '?mode=tag' . ($rest ? '&tags=' . urlencode(implode(',', $rest)) : '');
            ?>" class="pantry-tag" style="background: var(--butter); text-decoration: none; color: inherit;">
            <?= h($t) ?> <span class="pantry-tag-x">✕</span>
          </a>
        <?php endforeach; ?>
      </div>

      <h3 style="margin-bottom: 12px; margin-top: 20px;">
        <?= count($tags) === 0 ? 'Add ingredients to search.' : (count($tagResults) . ' matching recipes') ?>
      </h3>

      <?php if (count($tagResults) > 0): ?>
        <div class="grid" style="margin-top: 10px;">
          <?php foreach ($tagResults as $recipe): ?>
            <?php require SRC_PATH . '/views/_partials/recipe-card.php'; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<script type="module" src="<?= h(url_for('/assets/js/pantry.js')) ?>"></script>
