<div class="page">
  <div class="empty">
    <div class="empty-glyph">🤷</div>
    <h1 style="margin-top: 0;">That page doesn't exist</h1>
    <p class="muted" style="margin-top: 6px;">
      The link you followed may be broken, or the recipe was deleted.
    </p>
    <div class="row" style="gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 18px;">
      <a class="btn btn-primary" href="<?= h(url_for('/')) ?>">🏠 Browse recipes</a>
      <a class="btn" href="<?= h(url_for('/pantry')) ?>">🥕 Pantry</a>
      <a class="btn" href="<?= h(url_for('/plan')) ?>">📅 Plan</a>
      <a class="btn" href="<?= h(url_for('/shopping')) ?>">🛒 Shopping</a>
    </div>
  </div>
</div>
