<?php

require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    session_write_close();

    if (is_file(MIZ_QUEUE_LOG) && is_readable(MIZ_QUEUE_LOG)) {
        readfile(MIZ_QUEUE_LOG);
    } else {
        echo 'Chưa có log.';
    }

    exit;
}

$title = 'Tasks';
require __DIR__ . '/_header.php';

echo '<header>';
echo '<h1>Queue log</h1>';
echo '<small id="queue-status">Đang tải...</small>';
echo '</header>';
echo '<section class="card log">';
echo '<pre id="queue-log" tabindex="0" aria-label="Nhật ký queue">Chưa có log.</pre>';
echo '</section>';

require __DIR__ . '/_footer.php';
