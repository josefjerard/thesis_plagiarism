<?php
$nav = [
    'index.php'      => 'Upload essay',
    'activities.php' => 'Activities',
    'review.php'     => 'Admin review',
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
    <span class="brand">Essay Similarity</span>
    <?php foreach ($nav as $href => $label) : ?>
      <a href="<?= e($href) ?>" class="<?= (basename($_SERVER['SCRIPT_NAME']) === $href) ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
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