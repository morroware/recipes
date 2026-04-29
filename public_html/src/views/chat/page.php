<?php
/** @var array $memories */
/** @var array $conversations */
/** @var array $recent_cooks */
$mem      = $memories      ?? [];
$convs    = $conversations ?? [];
$cooks    = $recent_cooks  ?? [];
?>
<div class="page" data-page="chat">
  <div class="row" style="margin-bottom: 16px; gap:8px; flex-wrap: wrap;">
    <div>
      <div class="page-eyebrow">KITCHEN BRAIN</div>
      <h1 style="margin-top: 4px;">✨ Chat with your cookbook</h1>
      <p class="muted" style="margin-top: 6px;">
        I remember your preferences, allergies, equipment, and what you've cooked.
        Ask me anything — I can also save changes to your shopping list, plan, and pantry.
      </p>
    </div>
    <div style="flex:1;"></div>
    <button class="btn btn-sm" type="button" data-chat="new-conv">＋ New chat</button>
  </div>

  <div class="chat-layout">

    <!-- Left rail: conversations + memories -->
    <aside class="chat-rail">
      <section class="chat-rail-section">
        <header class="row" style="justify-content: space-between; align-items: baseline;">
          <h3 style="margin:0;">💬 Conversations</h3>
          <span class="muted mono" style="font-size:11px;"><?= count($convs) ?></span>
        </header>
        <ul class="chat-conv-list" data-chat="conv-list">
          <?php if (!$convs): ?>
            <li class="muted" style="font-size: 13px; padding: 6px 4px;">No chats yet — say hi!</li>
          <?php else: ?>
            <?php foreach ($convs as $c): ?>
              <li>
                <button class="chat-conv-item" type="button"
                        data-conv-id="<?= (int)$c['id'] ?>">
                  <span class="chat-conv-title"><?= h($c['title']) ?></span>
                  <span class="muted mono" style="font-size: 11px;"><?= h(substr((string)$c['updated_at'], 0, 10)) ?></span>
                </button>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </section>

      <section class="chat-rail-section">
        <header class="row" style="justify-content: space-between; align-items: baseline;">
          <h3 style="margin:0;">🧠 Memories</h3>
          <span class="muted mono" style="font-size:11px;" data-chat="mem-count"><?= count($mem) ?></span>
        </header>
        <p class="muted" style="font-size:12px; margin: 4px 0 8px;">
          Things I've learned about you. Edit or remove anything.
        </p>
        <form class="chat-mem-add" data-chat="mem-form" autocomplete="off">
          <input class="search-input" name="fact" placeholder="e.g. allergic to peanuts" required>
          <select name="category" class="search-input" style="max-width: 130px;">
            <option value="diet">Diet</option>
            <option value="allergy">Allergy</option>
            <option value="dislike">Dislike</option>
            <option value="like">Like</option>
            <option value="cuisine">Cuisine</option>
            <option value="household">Household</option>
            <option value="equipment">Equipment</option>
            <option value="skill">Skill</option>
            <option value="schedule">Schedule</option>
            <option value="goal">Goal</option>
            <option value="other" selected>Other</option>
          </select>
          <button class="btn btn-sm btn-primary" type="submit">＋</button>
        </form>
        <ul class="chat-mem-list" data-chat="mem-list">
          <?php foreach ($mem as $m): ?>
            <li class="chat-mem-item" data-mem-id="<?= (int)$m['id'] ?>">
              <span class="chat-mem-cat pill"><?= h($m['category']) ?></span>
              <span class="chat-mem-fact"><?= h($m['fact']) ?></span>
              <button class="icon-btn" type="button" data-chat="mem-del" aria-label="Forget">✕</button>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <?php if ($cooks): ?>
      <section class="chat-rail-section">
        <header><h3 style="margin:0;">🍽️ Recently cooked</h3></header>
        <ul class="chat-cook-list">
          <?php foreach ($cooks as $c): ?>
            <li>
              <span><?= h($c['recipe_title']) ?></span>
              <?php if ($c['rating']): ?>
                <span class="muted mono"><?= str_repeat('★', (int)$c['rating']) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </aside>

    <!-- Main chat surface -->
    <section class="chat-main">
      <header class="chat-main-header row" style="justify-content: space-between; align-items: center;">
        <div>
          <span class="chat-status-dot" data-chat="status-dot"></span>
          <strong data-chat="conv-title">New conversation</strong>
        </div>
        <div class="row" style="gap:6px;">
          <button class="btn btn-sm" type="button" data-chat="extract">🧠 Save preferences</button>
          <button class="btn btn-sm" type="button" data-chat="rename">✏️</button>
          <button class="btn btn-sm" type="button" data-chat="delete">🗑️</button>
        </div>
      </header>

      <div class="chat-log" data-chat="log">
        <div class="chat-empty">
          <div style="font-size: 48px;">✨</div>
          <p>Start a conversation. Try one of these:</p>
          <div class="chat-suggestions">
            <button class="filter-chip" type="button" data-chat-prompt="What can I make tonight from what I have?">What can I make tonight?</button>
            <button class="filter-chip" type="button" data-chat-prompt="I'm vegetarian and don't like cilantro. Remember that.">I'm vegetarian (remember this)</button>
            <button class="filter-chip" type="button" data-chat-prompt="Plan a balanced 7-day meal plan for me.">Plan my week</button>
            <button class="filter-chip" type="button" data-chat-prompt="Suggest a comfort food recipe similar to the ones I've rated highest.">Comfort food I'd love</button>
            <button class="filter-chip" type="button" data-chat-prompt="Add eggs, milk, and bread to my shopping list.">Quick shopping list</button>
          </div>
        </div>
      </div>

      <form class="chat-input-row" data-chat="form" autocomplete="off">
        <textarea data-chat="input" name="message" rows="1"
                  placeholder="Ask anything — I know your kitchen and remember what you tell me…"
                  required></textarea>
        <button class="btn btn-primary" type="submit" data-chat="send">Send →</button>
      </form>
      <div class="muted mono" style="font-size: 11px; padding: 0 12px 12px;" data-chat="footer">
        Claude Sonnet 4.6 · enter sends · shift+enter for newline
      </div>
    </section>
  </div>
</div>

<script type="module" src="<?= h(url_for('/assets/js/chat.js')) ?>"></script>
