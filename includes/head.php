<?php
$nav = [
    'index.php'  => 'Upload Essays',
    'review.php' => 'History',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Plagiarism Detection</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<nav class="topnav">
  <div class="container nav-inner">
    <span class="brand">Plagiarism Scanner</span>
    <?php $current = basename($_SERVER['SCRIPT_NAME']); ?>
    <?php foreach ($nav as $href => $label) : ?>
      <a href="<?= e($href) ?>" class="<?= ($current === $href) ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="about.php" class="nav-right <?= ($current === 'about.php') ? 'active' : '' ?>">About</a>
  </div>
</nav>
<div class="container main">
<?php
$flash = flash_get();
if ($flash !== null) :
?>
  <div class="box ok"><?= e($flash) ?></div>
<?php
endif;