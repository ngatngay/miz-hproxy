<?php

if (!defined('MIZ_BOOTSTRAPPED')) {
    exit;
}

$title = $title ?? 'Miz HProxy';
$hide_nav = $hide_nav ?? false;
$active = basename($_SERVER['SCRIPT_NAME'] ?? '');
$flash = $_SESSION['flash'] ?? null;
$css_version = (int) (@filemtime(__DIR__ . '/panel.css') ?: 0);

if ($flash !== null) {
    unset($_SESSION['flash']);
}

$domains_active = in_array($active, ['index.php', 'add.php', 'edit.php'], true);
$stats_active = $active === 'stats.php';
$tasks_active = $active === 'task.php';
$backup_active = $active === 'backup.php';
$settings_active = $active === 'setting.php';

echo '<!doctype html>';
echo '<html lang="vi">';
echo '<head>';
echo '<meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>' . e($title) . ' - Miz HProxy</title>';
echo '<link rel="stylesheet" href="panel.css?v=' . $css_version . '">';
echo '</head>';
echo '<body>';

if (!$hide_nav) {
    echo '<header class="topbar">';
    echo '<nav aria-label="Chính">';
    echo '<a class="brand" href="index.php">Miz HProxy</a>';
    echo '<button class="menu-toggle" type="button" aria-controls="main-nav" aria-expanded="false" aria-label="Mở menu">';
    echo '<span aria-hidden="true"></span>';
    echo '</button>';
    echo '<ul class="menu" id="main-nav">';
    echo '<li><a href="index.php"' . ($domains_active ? ' aria-current="page"' : '') . '>Domains</a></li>';
    echo '<li><a href="stats.php"' . ($stats_active ? ' aria-current="page"' : '') . '>Stats</a></li>';
    echo '<li><a href="task.php"' . ($tasks_active ? ' aria-current="page"' : '') . '>Tasks</a></li>';
    echo '<li><a href="backup.php"' . ($backup_active ? ' aria-current="page"' : '') . '>Backup</a></li>';
    echo '<li><a href="setting.php"' . ($settings_active ? ' aria-current="page"' : '') . '>Settings</a></li>';
    echo '</ul>';
    echo '<a class="btn" data-variant="light" href="login.php?logout=1">Logout</a>';
    echo '</nav>';
    echo '</header>';
}

echo '<main id="main">';

if ($flash !== null) {
    echo '<div class="alert" data-type="' . e($flash['type']) . '" data-dismissible role="alert">';
    echo e($flash['message']);
    echo '<button type="button" data-dismiss="alert" aria-label="Đóng thông báo">&times;</button>';
    echo '</div>';
}
