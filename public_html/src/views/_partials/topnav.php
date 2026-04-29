<?php /** @var string $active */ $active = $active ?? ''; ?>

<!-- Mobile sticky top bar (hidden on desktop) -->
<header class="mobile-topbar no-print" role="banner">
  <span class="nav-brand-glyph" aria-hidden="true">🥧</span>
  <span class="brand-text">my little cookbook</span>
  <button type="button" class="icon-btn" data-js="ai-fab-trigger" aria-label="AI assistant">✨</button>
  <button type="button" class="icon-btn" data-js="drawer-open" aria-label="Open menu" aria-expanded="false">≡</button>
</header>

<!-- Mobile slide-in drawer (hidden on desktop) -->
<div class="mobile-drawer no-print" data-js="drawer" aria-hidden="true">
  <div class="mobile-drawer-panel" role="dialog" aria-label="Menu">
    <button type="button" class="mobile-drawer-close" data-js="drawer-close" aria-label="Close menu">✕</button>
    <a href="<?= h(url_for('/')) ?>"          <?= $active === 'browse'    ? 'aria-current="page"' : '' ?>>🏠 Browse</a>
    <a href="<?= h(url_for('/pantry')) ?>"    <?= $active === 'pantry'    ? 'aria-current="page"' : '' ?>>🥕 Pantry</a>
    <a href="<?= h(url_for('/plan')) ?>"      <?= $active === 'plan'      ? 'aria-current="page"' : '' ?>>📅 Plan</a>
    <a href="<?= h(url_for('/shopping')) ?>"  <?= $active === 'shopping'  ? 'aria-current="page"' : '' ?>>🛒 Shopping</a>
    <a href="<?= h(url_for('/favorites')) ?>" <?= $active === 'favorites' ? 'aria-current="page"' : '' ?>>♥ Favorites</a>
    <a href="<?= h(url_for('/print')) ?>"     <?= $active === 'print'     ? 'aria-current="page"' : '' ?>>🖨️ Print</a>
    <a href="<?= h(url_for('/add')) ?>"       <?= $active === 'add'       ? 'aria-current="page"' : '' ?>>＋ Add</a>
    <button type="button" data-js="ai-fab-trigger">✨ AI assistant</button>
    <form method="post" action="<?= h(url_for('/logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit" style="width:100%;">Sign out</button>
    </form>
  </div>
</div>

<!-- Desktop nav (hidden on mobile via CSS) -->
<nav class="row no-print" aria-label="Primary"
     style="justify-content: flex-end; gap: 8px; padding: 14px 24px 0;">
  <a class="btn btn-sm" href="<?= h(url_for('/')) ?>"          <?= $active === 'browse'    ? 'aria-current="page"' : '' ?>>🏠 Browse</a>
  <a class="btn btn-sm" href="<?= h(url_for('/pantry')) ?>"    <?= $active === 'pantry'    ? 'aria-current="page"' : '' ?>>🥕 Pantry</a>
  <a class="btn btn-sm" href="<?= h(url_for('/plan')) ?>"      <?= $active === 'plan'      ? 'aria-current="page"' : '' ?>>📅 Plan</a>
  <a class="btn btn-sm" href="<?= h(url_for('/shopping')) ?>"  <?= $active === 'shopping'  ? 'aria-current="page"' : '' ?>>🛒 Shopping</a>
  <a class="btn btn-sm" href="<?= h(url_for('/favorites')) ?>" <?= $active === 'favorites' ? 'aria-current="page"' : '' ?>>♥ Favorites</a>
  <a class="btn btn-sm" href="<?= h(url_for('/print')) ?>"     <?= $active === 'print'     ? 'aria-current="page"' : '' ?>>🖨️ Print</a>
  <a class="btn btn-sm btn-mint" href="<?= h(url_for('/add')) ?>" <?= $active === 'add'    ? 'aria-current="page"' : '' ?>>＋ Add</a>
  <form method="post" action="<?= h(url_for('/logout')) ?>" style="display: inline;">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-sm btn-ghost">Sign out</button>
  </form>
</nav>

<!-- Mobile bottom tab bar -->
<nav class="mobile-nav no-print" aria-label="Mobile primary">
  <a class="mobile-nav-item" href="<?= h(url_for('/')) ?>"          <?= $active === 'browse'    ? 'aria-current="page"' : '' ?>><span class="glyph">🏠</span><span>Browse</span></a>
  <a class="mobile-nav-item" href="<?= h(url_for('/pantry')) ?>"    <?= $active === 'pantry'    ? 'aria-current="page"' : '' ?>><span class="glyph">🥕</span><span>Pantry</span></a>
  <a class="mobile-nav-item" href="<?= h(url_for('/add')) ?>"       <?= $active === 'add'       ? 'aria-current="page"' : '' ?>><span class="glyph">＋</span><span>Add</span></a>
  <a class="mobile-nav-item" href="<?= h(url_for('/plan')) ?>"      <?= $active === 'plan'      ? 'aria-current="page"' : '' ?>><span class="glyph">📅</span><span>Plan</span></a>
  <a class="mobile-nav-item" href="<?= h(url_for('/shopping')) ?>"  <?= $active === 'shopping'  ? 'aria-current="page"' : '' ?>><span class="glyph">🛒</span><span>Shop</span></a>
</nav>
