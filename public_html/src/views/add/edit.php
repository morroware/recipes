<?php
/** @var array|null $recipe */
/** @var string $mode  'create' | 'edit' */
$isEdit = $mode === 'edit' && $recipe !== null;
$colorOpts = ['mint','butter','peach','lilac','sky','blush','lime','coral'];
$diffs = ['Easy','Medium','Hard'];

// Defaults
$d = [
    'id'           => $isEdit ? (int)$recipe['id'] : 0,
    'title'        => $isEdit ? $recipe['title'] : '',
    'cuisine'      => $isEdit ? $recipe['cuisine'] : '',
    'time_minutes' => $isEdit ? (int)$recipe['time_minutes'] : 30,
    'servings'     => $isEdit ? (int)$recipe['servings'] : 2,
    'difficulty'   => $isEdit ? $recipe['difficulty'] : 'Easy',
    'glyph'        => $isEdit ? $recipe['glyph'] : '🍽️',
    'color'        => $isEdit ? $recipe['color'] : 'mint',
    'summary'      => $isEdit ? $recipe['summary'] : '',
    'photo_url'    => $isEdit ? ($recipe['photo_url'] ?? '') : '',
    'gallery_urls' => $isEdit ? ($recipe['gallery_urls'] ?? []) : [],
    'tags'         => $isEdit ? implode(', ', $recipe['tags'] ?? []) : '',
    'notes'        => $isEdit ? ($recipe['notes'] ?? '') : '',
    'ingredients'  => $isEdit ? $recipe['ingredients'] : [['qty'=>'','unit'=>'','name'=>'','aisle'=>'Pantry']],
    'steps'        => $isEdit ? array_column($recipe['steps'], 'text') : [''],
];
?>
<div class="page" data-page="add" data-mode="<?= h($mode) ?>" data-recipe-id="<?= (int)$d['id'] ?>">
  <div class="row no-print" style="margin-bottom: 20px;">
    <a class="btn btn-ghost" href="<?= h(url_for('/')) ?>">← All recipes</a>
    <?php if (!$isEdit): ?>
      <button type="button" class="btn btn-sm btn-lilac" data-ai-open="import">✨ Import from text</button>
    <?php endif; ?>
    <?php if ($isEdit): ?>
      <a class="btn btn-ghost" href="<?= h(url_for('/recipes/' . (int)$d['id'])) ?>">View recipe</a>
      <span style="flex: 1"></span>
      <button type="button" class="btn btn-sm btn-ghost" data-js="delete-btn">🗑 Delete</button>
    <?php endif; ?>
  </div>
  <div class="page-header">
    <div class="page-title-wrap">
      <div>
        <div class="page-eyebrow"><?= $isEdit ? 'EDIT RECIPE' : 'NEW RECIPE' ?></div>
        <h1><?= $isEdit ? 'Edit ' . h($d['title']) : 'Add to your book ✏️' ?></h1>
      </div>
    </div>
  </div>

  <form data-js="recipe-form" novalidate>
    <div class="detail-grid">
      <!-- Basics -->
      <div class="pantry-panel">
        <h3 style="margin-bottom: 16px;">Basics</h3>
        <div class="form-field">
          <label class="form-label">Title</label>
          <input class="form-input" name="title" value="<?= h($d['title']) ?>" maxlength="160" required>
        </div>
        <div class="form-field">
          <label class="form-label">One-line summary</label>
          <input class="form-input" name="summary" value="<?= h($d['summary']) ?>"
                 placeholder="Three layers of bechamel and bolognese.">
        </div>
        <div class="row" style="gap: 12px;">
          <div class="form-field" style="flex: 1;">
            <label class="form-label">Cuisine</label>
            <input class="form-input" name="cuisine" value="<?= h($d['cuisine']) ?>" maxlength="64">
          </div>
          <div class="form-field" style="width: 100px;">
            <label class="form-label">Time (m)</label>
            <input class="form-input" type="number" name="time_minutes" min="0" max="1440" value="<?= (int)$d['time_minutes'] ?>">
          </div>
          <div class="form-field" style="width: 100px;">
            <label class="form-label">Serves</label>
            <input class="form-input" type="number" name="servings" min="1" max="100" value="<?= (int)$d['servings'] ?>">
          </div>
        </div>
        <div class="form-field">
          <label class="form-label">Difficulty</label>
          <div class="row" data-js="diff-row">
            <?php foreach ($diffs as $df): ?>
              <button type="button" class="filter-chip <?= $d['difficulty'] === $df ? 'active' : '' ?>"
                      data-difficulty="<?= h($df) ?>"><?= h($df) ?></button>
            <?php endforeach; ?>
            <input type="hidden" name="difficulty" value="<?= h($d['difficulty']) ?>">
          </div>
        </div>
        <div class="form-field">
          <label class="form-label">Tags (comma separated)</label>
          <input class="form-input" name="tags" value="<?= h($d['tags']) ?>"
                 placeholder="weeknight, pasta, comfort">
        </div>
        <div class="form-field">
          <label class="form-label">Card glyph (1 emoji)</label>
          <input class="form-input" name="glyph" value="<?= h($d['glyph']) ?>" maxlength="4"
                 style="font-size: 24px; width: 90px; text-align: center;">
        </div>
        <div class="form-field">
          <label class="form-label">Card color</label>
          <div class="row" data-js="color-row">
            <?php foreach ($colorOpts as $c): $bg = STICKER_COLORS[$c]['bg']; ?>
              <button type="button"
                      data-color="<?= h($c) ?>"
                      title="<?= h($c) ?>"
                      style="width:36px;height:36px;border-radius:10px;cursor:pointer;background:<?= h($bg) ?>;
                             border:<?= $d['color'] === $c ? '3px solid var(--ink)' : '2px solid var(--ink)' ?>;
                             box-shadow:<?= $d['color'] === $c ? '3px 3px 0 var(--ink)' : 'none' ?>;"></button>
            <?php endforeach; ?>
            <input type="hidden" name="color" value="<?= h($d['color']) ?>">
          </div>
        </div>
        <div class="form-field">
          <label class="form-label">Photo URL (optional)</label>
          <input class="form-input" name="photo_url" value="<?= h($d['photo_url']) ?>"
                 placeholder="/assets/img/uploads/lasagna.jpg or https://…">
        </div>
        <div class="form-field">
          <label class="form-label">Gallery image URLs (optional, comma separated)</label>
          <input class="form-input" name="gallery_urls" value="<?= h(implode(', ', (array)$d['gallery_urls'])) ?>"
                 placeholder="/assets/img/uploads/lasagna-1.jpg, https://…">
        </div>
        <div class="form-field">
          <label class="form-label">Upload images (gallery)</label>
          <input class="form-input" type="file" name="gallery_files" data-js="gallery-files" accept="image/*" multiple>
          <div class="muted" style="margin-top:8px;">Uploads append to the gallery URL list above.</div>
        </div>
      </div>

      <!-- Ingredients + steps -->
      <div class="pantry-panel">
        <h3 style="margin-bottom: 16px;">Ingredients</h3>
        <div data-js="ingredients">
          <?php foreach ($d['ingredients'] as $ing): ?>
            <?php $ing = (array)$ing + ['qty'=>'','unit'=>'','name'=>'','aisle'=>'Pantry']; ?>
            <div class="form-row-mini" data-js="ingredient-row">
              <input class="form-input" data-field="qty"  placeholder="qty"  value="<?= h($ing['qty'] ?? '') ?>">
              <input class="form-input" data-field="unit" placeholder="unit" value="<?= h($ing['unit'] ?? '') ?>">
              <input class="form-input" data-field="name" placeholder="ingredient" value="<?= h($ing['name'] ?? '') ?>">
              <select class="form-input" data-field="aisle">
                <?php foreach (AISLES as $a): ?>
                  <option value="<?= h($a) ?>" <?= ($ing['aisle'] ?? 'Pantry') === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-sm btn-ghost" data-action="remove-ingredient">✕</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm" data-action="add-ingredient">＋ Add ingredient</button>

        <h3 style="margin-bottom: 16px; margin-top: 24px;">Steps</h3>
        <div data-js="steps">
          <?php foreach ($d['steps'] as $i => $step): ?>
            <div class="form-field" data-js="step-row">
              <div class="row" style="align-items: flex-start;">
                <span class="step-num" style="flex-shrink: 0;" data-js="step-num"><?= $i + 1 ?></span>
                <textarea class="form-textarea" data-field="step" style="min-height: 60px;"><?= h($step) ?></textarea>
                <button type="button" class="btn btn-sm btn-ghost" data-action="remove-step">✕</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm" data-action="add-step">＋ Add step</button>

        <h3 style="margin-bottom: 12px; margin-top: 24px;">Notes (private)</h3>
        <textarea class="form-textarea" name="notes" style="min-height: 80px;"
                  placeholder="Made it Tuesday. Less coconut milk next time."><?= h($d['notes']) ?></textarea>

        <div style="margin-top: 30px; border-top: 2px dashed var(--ink); padding-top: 20px;">
          <button type="submit" class="btn btn-primary" data-js="save-btn">
            💾 <?= $isEdit ? 'Save changes' : 'Save recipe' ?>
          </button>
          <span class="muted" style="margin-left: 12px;" data-js="save-status"></span>
        </div>
      </div>
    </div>
  </form>
</div>
<script type="module" src="<?= h(url_for('/assets/js/add.js')) ?>"></script>
