<?php
/** @var string $mode */
/** @var array  $recipes */
/** @var array  $shoppingByAisle */
/** @var int    $shoppingTotal */
/** @var int|null $cardId */
/** @var array|null $cardRecipe */
/** @var int[] $bookletIds */
/** @var array $bookletRecipes */
/** @var array $weekRecipes */

$dateLabel = (new DateTime('today'))->format('l, F j');
?>
<div class="page" data-page="print" data-mode="<?= h($mode) ?>"
     data-card-id="<?= (int)($cardId ?? 0) ?>"
     data-booklet-ids="<?= h(implode(',', $bookletIds)) ?>">
  <div class="page-header no-print">
    <div class="page-title-wrap">
      <div>
        <div class="page-eyebrow">PRINT &amp; EXPORT</div>
        <h1>Print shop 🖨️</h1>
      </div>
    </div>
    <button type="button" class="btn btn-primary" data-js="print-now">🖨️ Print this sheet</button>
  </div>

  <div class="row no-print" style="margin-bottom: 24px; gap: 6px;">
    <a class="filter-chip <?= $mode === 'shopping' ? 'active' : '' ?>" href="/print?mode=shopping">🛒 Shopping list</a>
    <a class="filter-chip <?= $mode === 'card'     ? 'active' : '' ?>" href="/print?mode=card<?= $cardId ? '&id=' . $cardId : '' ?>">🗂️ Recipe card (4×6)</a>
    <a class="filter-chip <?= $mode === 'booklet'  ? 'active' : '' ?>" href="/print?mode=booklet<?= $bookletIds ? '&ids=' . implode(',', $bookletIds) : '' ?>">📚 Recipe booklet</a>
    <a class="filter-chip <?= $mode === 'week'     ? 'active' : '' ?>" href="/print?mode=week">📅 Week's plan</a>
  </div>

  <?php if ($mode === 'shopping'): ?>
    <div class="print-sheet">
      <div class="print-sheet-eyebrow">
        <span>SHOPPING LIST</span>
        <span><?= h($dateLabel) ?></span>
      </div>
      <h1 class="print-sheet-h1">Groceries 🛒</h1>
      <p style="margin-top: 4px; color: #555; font-size: 13px;">
        <?= (int)$shoppingTotal ?> item<?= $shoppingTotal === 1 ? '' : 's' ?> · grouped by aisle
      </p>
      <hr>
      <?php if ($shoppingTotal === 0): ?>
        <p style="font-style: italic; color: #888;">No items in your shopping list yet.</p>
      <?php else: ?>
        <div class="print-shop-grid">
          <?php foreach ($shoppingByAisle as $aisle => $items): ?>
            <div class="print-shop-aisle">
              <div class="print-shop-aisle-title"><?= h($aisle) ?></div>
              <?php foreach ($items as $it): ?>
                <div class="print-shop-row">
                  <span class="print-shop-box"></span>
                  <span class="print-shop-name"><?= h($it['name']) ?></span>
                  <?php if (!empty($it['qty'])): ?>
                    <span class="print-shop-qty"><?= h($it['qty']) ?> <?= h($it['unit']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($it['source_label']) && $it['source_label'] !== 'manual'): ?>
                    <span class="print-shop-source">(<?= h($it['source_label']) ?>)</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($mode === 'card'): ?>
    <div class="split-2 no-print">
      <div>
        <div class="page-eyebrow" style="margin-bottom: 10px;">PICK A RECIPE</div>
        <div data-js="card-picker" style="height: 520px;"></div>
      </div>
      <div>
        <div class="page-eyebrow" style="margin-bottom: 10px;">PREVIEW</div>
        <?php if ($cardRecipe): $recipe = $cardRecipe; ?>
          <?php require SRC_PATH . '/views/print/_card.php'; ?>
        <?php else: ?>
          <div class="print-preview-empty">Pick a recipe →</div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($cardRecipe): $recipe = $cardRecipe; ?>
      <div class="print-only-block" style="display: none;">
        <?php require SRC_PATH . '/views/print/_card.php'; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($mode === 'booklet'): ?>
    <div class="split-2 no-print">
      <div>
        <div class="row" style="justify-content: space-between; margin-bottom: 10px;">
          <div class="page-eyebrow">BUILD A BOOKLET</div>
          <?php if ($bookletIds): ?>
            <button type="button" class="btn btn-sm btn-ghost" data-js="booklet-clear">Clear</button>
          <?php endif; ?>
        </div>
        <div class="row" style="margin-bottom: 10px; gap: 6px;">
          <button type="button" class="btn btn-sm btn-blush" data-js="booklet-add-favs">＋ All favorites</button>
          <button type="button" class="btn btn-sm btn-mint"  data-js="booklet-add-week">＋ This week's plan</button>
        </div>
        <div data-js="booklet-picker" style="height: 480px;"></div>
      </div>
      <div>
        <div class="page-eyebrow" style="margin-bottom: 10px;">
          PREVIEW <?= count($bookletRecipes) > 0 ? '· ~' . count($bookletRecipes) . ' page' . (count($bookletRecipes) === 1 ? '' : 's') : '' ?>
        </div>
        <?php if (count($bookletRecipes) > 8): ?>
          <div class="print-warning">
            ⚠️ <?= count($bookletRecipes) ?> recipes selected — that's a long print job. Sure?
          </div>
        <?php endif; ?>
        <?php if (!$bookletRecipes): ?>
          <div class="print-preview-empty">
            <div style="font-size: 36px; margin-bottom: 8px;">📚</div>
            <div>Pick recipes from the left to build a booklet.</div>
          </div>
        <?php else: ?>
          <div style="max-height: 600px; overflow: auto; padding: 10px;
                      background: var(--cream-2); border-radius: var(--r-md); border: 2px solid var(--ink);">
            <?php foreach (array_slice($bookletRecipes, 0, 3) as $recipe): ?>
              <div style="transform: scale(0.8); transform-origin: top left; margin-bottom: -50px;">
                <?php require SRC_PATH . '/views/print/_card.php'; ?>
              </div>
            <?php endforeach; ?>
            <?php if (count($bookletRecipes) > 3): ?>
              <div class="muted center" style="padding: 16px; font-size: 13px;">
                …and <?= count($bookletRecipes) - 3 ?> more.
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($bookletRecipes): ?>
      <div class="print-only-block" style="display: none;">
        <?php foreach ($bookletRecipes as $recipe): ?>
          <?php require SRC_PATH . '/views/print/_card.php'; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($mode === 'week'): ?>
    <?php if (!$weekRecipes): ?>
      <div class="empty no-print">
        <div class="empty-glyph">📅</div>
        <div>Nothing planned this week. <a href="/plan">Open the plan</a> to assign recipes.</div>
      </div>
    <?php else: ?>
      <p class="muted no-print" style="margin-bottom: 16px;">
        <?= count($weekRecipes) ?> planned recipe<?= count($weekRecipes) === 1 ? '' : 's' ?> this week. Each prints as a 4×6 card.
      </p>
      <div class="print-card-grid">
        <?php foreach ($weekRecipes as $w): $recipe = $w['recipe']; $dayBadge = $w['day']; ?>
          <?php require SRC_PATH . '/views/print/_card.php'; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script type="application/json" data-bind="print-recipes"><?= json_encode(array_map(fn($r) => [
  'id'           => (int)$r['id'],
  'title'        => $r['title'],
  'cuisine'      => $r['cuisine'],
  'time_minutes' => (int)$r['time_minutes'],
  'servings'     => (int)$r['servings'],
  'glyph'        => $r['glyph'],
  'color'        => $r['color'],
  'photo_url'    => $r['photo_url'] ?? null,
  'tags'         => $r['tags'] ?? [],
  'is_favorite'  => (int)($r['is_favorite'] ?? 0),
], $recipes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="module" src="/assets/js/print.js"></script>
