<?php
/** @var array $recipe */
$colors = STICKER_COLORS[$recipe['color']] ?? STICKER_COLORS['mint'];
$showPhoto = !empty($recipe['photo_url']);
$tagColors = TAG_PILL_COLORS;
$tags = $recipe['tags'] ?? [];
$isFav = !empty($recipe['is_favorite']);
$href = url_for('/recipes/' . (int)$recipe['id']);
$title = (string)$recipe['title'];
$extraTags = max(0, count($tags) - 3);
?>
<article class="recipe-card" data-recipe-id="<?= (int)$recipe['id'] ?>">
  <a class="recipe-card-link" href="<?= h($href) ?>" aria-label="Open <?= h($title) ?>">
    <div class="recipe-card-img <?= $showPhoto ? 'has-photo' : '' ?>"
         style="<?= $showPhoto
           ? 'background-image: url(' . h($recipe['photo_url']) . '); background-size: cover; background-position: center;'
           : 'background: ' . h($colors['bg']) . ';' ?>">
      <?php if (!$showPhoto): ?>
        <span style="filter: drop-shadow(2px 2px 0 rgba(0,0,0,0.1));"><?= h($recipe['glyph']) ?></span>
      <?php endif; ?>
      <span class="recipe-card-time-pill">⏱ <?= (int)$recipe['time_minutes'] ?>m</span>
    </div>
    <div class="recipe-card-body">
      <div class="recipe-card-cuisine"><?= h($recipe['cuisine']) ?> · <?= h($recipe['difficulty']) ?></div>
      <div class="recipe-card-title"><?= h($title) ?></div>
      <p class="recipe-card-summary"><?= h($recipe['summary']) ?></p>
      <div class="recipe-card-tags">
        <?php foreach (array_slice($tags, 0, 3) as $i => $tag): ?>
          <span class="pill <?= h($tagColors[$i % count($tagColors)]) ?>">#<?= h($tag) ?></span>
        <?php endforeach; ?>
        <?php if ($extraTags > 0): ?>
          <span class="pill" title="<?= (int)$extraTags ?> more tag<?= $extraTags === 1 ? '' : 's' ?>">+<?= (int)$extraTags ?></span>
        <?php endif; ?>
      </div>
    </div>
  </a>
  <button type="button"
          class="recipe-card-fav <?= $isFav ? 'active' : '' ?>"
          data-action="toggle-favorite"
          data-recipe-id="<?= (int)$recipe['id'] ?>"
          aria-label="<?= $isFav ? 'Unfavorite' : 'Favorite' ?> <?= h($title) ?>"
          aria-pressed="<?= $isFav ? 'true' : 'false' ?>"><?= $isFav ? '♥' : '♡' ?></button>
</article>
