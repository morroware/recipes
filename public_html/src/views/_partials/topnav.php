<div class="row no-print" style="justify-content: flex-end; gap: 8px; padding: 14px 24px 0;">
  <a class="btn btn-sm" href="/">🏠 Browse</a>
  <a class="btn btn-sm" href="/pantry">🥕 Pantry</a>
  <a class="btn btn-sm" href="/plan">📅 Plan</a>
  <a class="btn btn-sm" href="/shopping">🛒 Shopping</a>
  <a class="btn btn-sm" href="/favorites">♥ Favorites</a>
  <a class="btn btn-sm" href="/print">🖨️ Print</a>
  <a class="btn btn-sm btn-mint" href="/add">＋ Add</a>
  <form method="post" action="/logout" style="display: inline;">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-sm btn-ghost">Sign out</button>
  </form>
</div>
