<?php
$current = basename($_SERVER['SCRIPT_NAME']);
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
    <a class="brand" href="index.php">Plagiarism Scanner</a>
    <a href="upload_essays.php" class="nav-right <?= ($current === 'upload_essays.php') ? 'active' : '' ?>">Upload Essays</a>
    <a href="about.php" class="<?= ($current === 'about.php') ? 'active' : '' ?>">About</a>
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