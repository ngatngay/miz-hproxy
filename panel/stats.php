<?php

require __DIR__ . '/_init.php';

$stats = @file_get_contents('http://127.0.0.1:19869/stats');

if ($stats === false) {
    exit('Không đọc được stats.');
}

echo $stats;
