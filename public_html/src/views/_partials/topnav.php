<div class="row no-print" style="justify-content: flex-end; gap: 8px; padding: 14px 24px 0;">
  <a class="btn btn-sm" href="/">🏠 Browse</a>
  <a class="btn btn-sm" href="/pantry">🥕 Pantry</a>
  <a class="btn btn-sm" href="/favorites">♥ Favorites</a>
  <form method="post" action="/logout" style="display: inline;">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-sm btn-ghost">Sign out</button>
  </form>
</div>
