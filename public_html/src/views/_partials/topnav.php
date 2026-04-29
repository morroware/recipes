<?php /** @var string $active */ $active = $active ?? ''; ?>
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
