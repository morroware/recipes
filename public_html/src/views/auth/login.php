<?php /** @var string $next */ /** @var string $error */ ?>
<div class="page" style="max-width: 440px; margin: 60px auto;">
  <div class="page-eyebrow" style="margin-bottom: 8px;">YOUR LITTLE COOKBOOK</div>
  <h1>Sign in</h1>
  <p style="color: var(--ink-soft); margin-top: 6px;">One account. The one you set up in the installer.</p>

  <?php if ($error !== ''): ?>
    <div class="pill pill-coral" style="margin-top: 16px; display: inline-block;">⚠ <?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= h(url_for('/login')) ?>" style="margin-top: 24px; display: grid; gap: 14px;">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label>
      <div class="page-eyebrow">EMAIL</div>
      <input class="search-input" type="email" name="email" required autofocus autocomplete="username" style="width: 100%;">
    </label>
    <label>
      <div class="page-eyebrow">PASSWORD</div>
      <input class="search-input" type="password" name="password" required autocomplete="current-password" style="width: 100%;">
    </label>
    <button type="submit" class="btn btn-primary" style="margin-top: 6px;">Sign in →</button>
  </form>
</div>
