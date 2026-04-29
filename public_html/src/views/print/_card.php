<?php
/** @var array $recipe */
/** @var string|null $dayBadge */
$dayBadge = $dayBadge ?? null;
?>
<div class="print-card">
  <div class="print-card-header">
    <div>
      <div class="print-card-meta">
        <?= h($recipe['cuisine']) ?> · <?= (int)$recipe['time_minutes'] ?> min ·
        serves <?= (int)$recipe['servings'] ?> · <?= h($recipe['difficulty']) ?>
        <?php if ($dayBadge): ?> · <?= h(strtoupper($dayBadge)) ?><?php endif; ?>
      </div>
      <div class="print-card-title"><?= h($recipe['glyph']) ?> <?= h($recipe['title']) ?></div>
    </div>
    <div style="font-family: var(--font-mono); font-size: 10px; color: #888;
                text-align: right; text-transform: uppercase; letter-spacing: 0.1em;">
      recipe<br>card
    </div>
  </div>
  <div>
    <div class="print-card-section-title">Ingredients</div>
    <ul class="print-card-ing">
      <?php foreach ($recipe['ingredients'] as $ing): ?>
        <li>
          <span class="qty"><?= h(($ing['qty'] ?? '') . ($ing['unit'] ? ' ' . $ing['unit'] : '')) ?></span>
          <?= h($ing['name']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div>
    <div class="print-card-section-title">Method</div>
    <ol class="print-card-steps">
      <?php foreach ($recipe['steps'] as $s): ?>
        <li><?= h($s['text']) ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
</div>
