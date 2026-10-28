<?php
/* Page shell (top). Pages set $pageTitle and $active before including this. */
$user = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';
$active = $active ?? '';
$lowCount = low_stock_count();
$nav = [
    ['index.php', 'dashboard', 'grid', 'Dashboard'],
    ['sales.php', 'sales', 'cart', 'Record Sale'],
    ['products.php', 'products', 'box', 'Products'],
    ['stock.php', 'stock', 'swap', 'Stock In / Out'],
    ['reports.php', 'reports', 'chart', 'Reports'],
    ['settings.php', 'settings', 'sliders', 'Settings'],
];
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> · JV Hardware</title>
<link rel="stylesheet" href="assets/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/style.css') ?>">
<script src="assets/jv.js"></script>
</head><body>
<?php include __DIR__ . '/icons.php'; ?>
<div class="app">
<div class="scrim" id="scrim"></div>
<aside class="sidebar" id="sidebar">
  <a class="brand" href="index.php" aria-label="JV Hardware home"><img src="assets/jv-hardware-logo.png" alt="JV Hardware" width="190"></a>
  <nav>
    <?php foreach ($nav as $n): ?>
      <a href="<?= $n[0] ?>" class="<?= $active === $n[1] ? 'active' : '' ?>"><?= icon($n[2]) ?><span><?= $n[3] ?></span>
        <?php if ($n[1] === 'stock' && $lowCount > 0): ?><em class="nav-badge" title="Products needing restock"><?= $lowCount ?></em><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <div class="side-user">
      <span class="avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span>
      <div><b><?= e($user['full_name']) ?></b><small>Store owner</small></div>
    </div>
    <a class="logout" href="logout.php" title="Log out"><?= icon('logout') ?><span>Log out</span></a>
  </div>
</aside>
<main class="main">
  <header class="topbar">
    <button class="icon-btn menu-btn" id="menu-btn" aria-label="Open menu"><?= icon('menu') ?></button>
    <div class="top-title"><h1><?= e($pageTitle) ?></h1></div>
    <div class="top-right"><time class="clock" id="clock" datetime="<?= e(date('c')) ?>"><?= date('D, M j · g:i A') ?></time></div>
  </header>
  <div class="toasts" id="toasts">
    <?php foreach (($_SESSION['flash'] ?? []) as $f): ?>
      <div class="toast <?= e($f['t']) ?>"><?= icon($f['t'] === 'err' ? 'alert' : 'check') ?><span><?= e($f['m']) ?></span><button aria-label="Dismiss"><?= icon('x') ?></button></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
  </div>
  <section class="content">
