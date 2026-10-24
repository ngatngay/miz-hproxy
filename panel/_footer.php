<?php

if (!defined('MIZ_BOOTSTRAPPED')) {
    exit;
}

$js_version = (int) (@filemtime(__DIR__ . '/panel.js') ?: 0);

echo '</main>';
echo '<script src="panel.js?v=' . $js_version . '"></script>';
echo '</body>';
echo '</html>';
