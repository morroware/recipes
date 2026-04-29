<?php
/** @var array $recipe */
$colors = STICKER_COLORS[$recipe['color']] ?? STICKER_COLORS['mint'];
$showPhoto = !empty($recipe['photo_url']);
$tagColors = TAG_PILL_COLORS;
$tags = $recipe['tags'] ?? [];
$isFav = !empty($recipe['is_favorite']);
?>
<a class="recipe-card" href="/recipes/<?= (int)$recipe['id'] ?>" data-recipe-id="<?= (int)$recipe['id'] ?>" style="text-decoration: none; color: inherit;">
  <div class="recipe-card-img <?= $showPhoto ? 'has-photo' : '' ?>"
       style="<?= $showPhoto
         ? 'background-image: url(' . h($recipe['photo_url']) . '); background-size: cover; background-position: center;'
         : 'background: ' . h($colors['bg']) . ';' ?>">
    <?php if (!$showPhoto): ?>
      <span style="filter: drop-shadow(2px 2px 0 rgba(0,0,0,0.1));"><?= h($recipe['glyph']) ?></span>
    <?php endif; ?>
    <button type="button"
            class="recipe-card-fav <?= $isFav ? 'active' : '' ?>"
            data-action="toggle-favorite"
            data-recipe-id="<?= (int)$recipe['id'] ?>"
            aria-label="favorite"
            aria-pressed="<?= $isFav ? 'true' : 'false' ?>"><?= $isFav ? '♥' : '♡' ?></button>
    <span class="recipe-card-time-pill">⏱ <?= (int)$recipe['time_minutes'] ?>m</span>
  </div>
  <div class="recipe-card-body">
    <div class="recipe-card-cuisine"><?= h($recipe['cuisine']) ?> · <?= h($recipe['difficulty']) ?></div>
    <div class="recipe-card-title"><?= h($recipe['title']) ?></div>
    <p class="recipe-card-summary"><?= h($recipe['summary']) ?></p>
    <div class="recipe-card-tags">
      <?php foreach (array_slice($tags, 0, 3) as $i => $tag): ?>
        <span class="pill <?= h($tagColors[$i % count($tagColors)]) ?>">#<?= h($tag) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</a>
