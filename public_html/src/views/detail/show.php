<?php
/** @var array $recipe */
$colors = STICKER_COLORS[$recipe['color']] ?? STICKER_COLORS['mint'];
$tagColors = TAG_PILL_COLORS;
$isFav = !empty($recipe['is_favorite']);
$ingredientsJson = json_encode($recipe['ingredients'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stepsJson       = json_encode(array_column($recipe['steps'], 'text'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="page recipe-print"
     data-recipe
     data-recipe-id="<?= (int)$recipe['id'] ?>"
     data-base-servings="<?= (int)$recipe['servings'] ?>"
     data-units="metric">
  <div class="row no-print" style="margin-bottom: 20px; gap: 8px;">
    <a class="btn btn-ghost" href="/">← All recipes</a>
    <div style="flex: 1"></div>
    <a class="btn btn-sm" href="/recipes/<?= (int)$recipe['id'] ?>/edit">✏️ Edit</a>
    <button class="btn btn-sm" type="button" data-action="print">🖨️ Print</button>
    <button class="btn btn-sm" type="button" data-action="add-to-shopping" data-recipe-id="<?= (int)$recipe['id'] ?>">🛒 Add to shopping</button>
    <button class="btn btn-sm <?= $isFav ? 'btn-coral' : '' ?>"
            type="button"
            data-action="toggle-favorite"
            data-recipe-id="<?= (int)$recipe['id'] ?>"
            aria-pressed="<?= $isFav ? 'true' : 'false' ?>"><?= $isFav ? '♥ Saved' : '♡ Save' ?></button>
    <button class="btn btn-sm btn-primary" type="button" data-action="cook-mode">▶ Cook mode</button>
  </div>

  <div class="detail-grid">
    <div>
      <div class="detail-hero <?= empty($recipe['photo_url']) ? 'no-photo' : '' ?>"
           style="<?= !empty($recipe['photo_url'])
             ? 'background-image: url(' . h($recipe['photo_url']) . '); background-size: cover; background-position: center;'
             : 'background: ' . h($colors['bg']) . ';' ?>">
        <?php if (empty($recipe['photo_url'])): ?>
          <span><?= h($recipe['glyph']) ?></span>
        <?php endif; ?>
      </div>
      <div class="detail-section">
        <h2>Ingredients</h2>
        <div class="row no-print" style="margin-bottom: 14px; gap: 8px; align-items: center;">
          <span class="mono" style="font-size: 12px; color: var(--ink-soft);">SCALE:</span>
          <button class="btn btn-sm" type="button" data-action="servings-down">−</button>
          <span class="mono" style="font-weight: 700;" data-bind="servings"><?= (int)$recipe['servings'] ?> servings</span>
          <button class="btn btn-sm" type="button" data-action="servings-up">+</button>
        </div>
        <ul class="ingredient-list" data-bind="ingredient-list">
          <?php foreach ($recipe['ingredients'] as $ing): ?>
            <li class="ingredient-row"
                data-qty="<?= h($ing['qty'] ?? '') ?>"
                data-unit="<?= h($ing['unit']) ?>">
              <span class="ingredient-qty"><?php // populated by detail.js on first paint ?></span>
              <span class="ingredient-name"><?= h($ing['name']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div>
      <div class="page-eyebrow"><?= h($recipe['cuisine']) ?></div>
      <h1 style="margin-top: 4px;"><?= h($recipe['title']) ?></h1>
      <p style="font-size: 18px; color: var(--ink-2); margin-top: 10px;"><?= h($recipe['summary']) ?></p>

      <div class="detail-meta-row">
        <div class="detail-stat" style="background: var(--butter);">
          <span class="detail-stat-label">TIME</span>
          <span class="detail-stat-value"><?= (int)$recipe['time_minutes'] ?> min</span>
        </div>
        <div class="detail-stat" style="background: var(--mint);">
          <span class="detail-stat-label">SERVES</span>
          <span class="detail-stat-value"><?= (int)$recipe['servings'] ?></span>
        </div>
        <div class="detail-stat" style="background: var(--lilac);">
          <span class="detail-stat-label">DIFFICULTY</span>
          <span class="detail-stat-value"><?= h($recipe['difficulty']) ?></span>
        </div>
        <div class="detail-stat" style="background: var(--peach);">
          <span class="detail-stat-label">INGREDIENTS</span>
          <span class="detail-stat-value"><?= count($recipe['ingredients']) ?></span>
        </div>
      </div>

      <div class="row" style="margin-top: 6px; flex-wrap: wrap;">
        <?php foreach (($recipe['tags'] ?? []) as $i => $tag): ?>
          <span class="pill <?= h($tagColors[$i % count($tagColors)]) ?>">#<?= h($tag) ?></span>
        <?php endforeach; ?>
      </div>

      <div class="detail-section">
        <h2>Method</h2>
        <ol class="steps-list">
          <?php foreach ($recipe['steps'] as $i => $s): ?>
            <li class="step-row">
              <span class="step-num"><?= $i + 1 ?></span>
              <span class="step-text"><?= h($s['text']) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="detail-section no-print">
        <h2>📝 Notes to self</h2>
        <textarea
          class="notes-area"
          data-action="save-notes"
          data-recipe-id="<?= (int)$recipe['id'] ?>"
          placeholder="Made this Tuesday — needed more salt. Use less coconut milk next time…"><?= h($recipe['notes'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <dialog class="cook-dialog" data-bind="cook-dialog"
          aria-labelledby="cook-dialog-title" aria-modal="true">
    <div class="cook-overlay" style="position: static;">
      <div class="cook-header">
        <div>
          <div class="page-eyebrow">COOKING</div>
          <h2 id="cook-dialog-title" style="margin-top: 4px;"><?= h($recipe['title']) ?> <?= h($recipe['glyph']) ?></h2>
        </div>
        <button class="btn" type="button" data-action="cook-close" aria-label="Exit cooking mode">✕ Exit</button>
      </div>
      <div class="cook-progress"><div class="cook-progress-fill" data-bind="cook-progress" style="width: 0%;"></div></div>
      <div class="cook-step-num" data-bind="cook-step-num">STEP 1 OF <?= count($recipe['steps']) ?></div>
      <div class="cook-step-text" data-bind="cook-step-text"></div>
      <div class="cook-controls">
        <button class="btn" type="button" data-action="cook-prev">← Back</button>
        <span class="mono muted">use ← / → keys</span>
        <button class="btn btn-primary" type="button" data-action="cook-next">Next →</button>
      </div>
    </div>
  </dialog>
</div>

<script type="application/json" data-bind="recipe-ingredients"><?= $ingredientsJson ?></script>
<script type="application/json" data-bind="recipe-steps"><?= $stepsJson ?></script>
<script type="module" src="/assets/js/detail.js"></script>
