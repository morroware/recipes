<?php
// public_html/src/views/layout.php — base shell.
// Mirrors project/Recipe Book.html: same Google Fonts, same stylesheets, same
// data-* attribute hooks on <html> so prototype CSS theming works unchanged.

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Phase 0 only renders defaults; Phase 6 will load these from user_settings.
$tweaks = $tweaks ?? [
    'density'        => 'cozy',
    'theme'          => 'rainbow',
    'mode'           => 'light',
    'fontPair'       => 'default',
    'radius'         => 'default',
    'stickerRotate'  => 'on',
    'dotGrid'        => 'on',
];
$title  = $title  ?? 'my little cookbook';
$active = $active ?? '';
?>
<!doctype html>
<html lang="en"
      data-density="<?= $h($tweaks['density']) ?>"
      data-theme="<?= $h($tweaks['theme']) ?>"
      data-mode="<?= $h($tweaks['mode']) ?>"
      data-fontpair="<?= $h($tweaks['fontPair']) ?>"
      data-radius="<?= $h($tweaks['radius']) ?>"
      data-sticker-rotate="<?= $h($tweaks['stickerRotate']) ?>"
      data-dot-grid="<?= $h($tweaks['dotGrid']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $h($title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500;700&family=Fraunces:wght@600;700;800&family=Inter:wght@400;500;700&family=Space+Grotesk:wght@500;700&family=JetBrains+Mono:wght@400;500;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/recipe-picker.css">
  <?php if (!empty($_SESSION['csrf_token'])): ?>
    <meta name="csrf-token" content="<?= $h($_SESSION['csrf_token']) ?>">
  <?php endif; ?>
</head>
<body>
  <div id="app">
    <?php require $body_view; ?>
  </div>
</body>
</html>
