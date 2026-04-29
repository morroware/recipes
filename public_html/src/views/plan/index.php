<?php
/** @var array $byDay */
/** @var array $recipes */
/** @var array<string, DateTime> $dates */
/** @var string $todayKey */
$colors = STICKER_COLORS;
?>
<div class="page" data-page="plan">
  <div class="page-header">
    <div class="page-title-wrap">
      <div>
        <div class="page-eyebrow">THIS WEEK</div>
        <h1>Meal plan 📅</h1>
      </div>
    </div>
    <div class="row no-print">
      <button type="button" class="btn btn-sm btn-mint" data-js="build-shopping">🛒 Build shopping list</button>
      <button type="button" class="btn btn-sm" data-js="clear-week">Clear week</button>
    </div>
  </div>

  <div class="week-grid">
    <?php foreach (PLAN_DAYS as $day): $entry = $byDay[$day] ?? null; $isToday = ($day === $todayKey); ?>
      <div class="day-col<?= $isToday ? ' today' : '' ?>" data-day="<?= h($day) ?>">
        <div class="day-name"><?= h($day) ?></div>
        <div class="day-date"><?= h($dates[$day]->format('M j')) ?></div>

        <?php if ($entry): $rcolor = $colors[$entry['color']] ?? $colors['mint']; ?>
          <a class="day-slot-filled" href="/recipes/<?= (int)$entry['id'] ?>" style="text-decoration: none; color: inherit;">
            <div style="font-size: 28px; margin-bottom: 4px;"><?= h($entry['glyph']) ?></div>
            <div style="font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 14px; line-height: 1.1;">
              <?= h($entry['title']) ?>
            </div>
            <div class="mono" style="font-size: 10px; color: var(--ink-soft); margin-top: 4px;">
              <?= (int)$entry['time_minutes'] ?>m · <?= h($entry['cuisine']) ?>
            </div>
            <button type="button" class="x-btn no-print" data-action="clear-day" title="Clear">✕</button>
          </a>
        <?php else: ?>
          <button type="button" class="day-slot" data-action="open-picker" style="width: 100%; cursor: pointer; font: inherit; color: inherit;">
            <div>＋ pick a recipe</div>
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Recipe picker modal -->
  <div class="modal-overlay no-print" data-js="picker-overlay" hidden>
    <div class="modal" data-js="picker-modal" role="dialog" aria-modal="true" aria-labelledby="picker-title">
      <div class="row" style="justify-content: space-between; margin-bottom: 16px;">
        <h2 id="picker-title">Plan <span data-js="picker-day"></span></h2>
        <button type="button" class="btn btn-sm" data-js="picker-close" aria-label="Close">✕</button>
      </div>
      <div class="recipe-picker" style="height: 500px;">
        <div class="recipe-picker-header">
          <input class="search-input" placeholder="Search recipes…" data-js="picker-search" autocomplete="off">
        </div>
        <ul class="recipe-picker-list" data-js="picker-list" role="listbox">
          <?php foreach ($recipes as $r): $rcolor = $colors[$r['color']] ?? $colors['mint']; ?>
            <li class="recipe-picker-row"
                data-recipe-id="<?= (int)$r['id'] ?>"
                data-search="<?= h(strtolower($r['title'] . ' ' . $r['cuisine'])) ?>"
                role="option"
                tabindex="0">
              <span class="recipe-picker-thumb" style="background: <?= h($rcolor['bg']) ?>;"><?= h($r['glyph']) ?></span>
              <span class="recipe-picker-body">
                <span class="recipe-picker-title"><?= h($r['title']) ?></span>
                <span class="recipe-picker-meta"><?= h($r['cuisine']) ?> · <?= (int)$r['time_minutes'] ?>m</span>
              </span>
              <span class="recipe-picker-mark recipe-picker-mark-single"></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
<script type="module" src="/assets/js/plan.js"></script>
