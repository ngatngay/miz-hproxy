<?php

require __DIR__ . '/_init.php';

$domains = get_domains();
$using_default_password = password_verify('!!!123456789!!!', (string) get_setting('password', ''));

$title = 'Domains';
require __DIR__ . '/_header.php';

echo '<header>';
echo '<h1>Domains</h1>';
echo '<a class="btn" href="add.php">Thêm domain</a>';
echo '</header>';

if ($using_default_password) {
    echo '<div class="alert" data-type="warning">Bạn đang dùng mật khẩu mặc định. Hãy đổi mật khẩu tại <a href="setting.php">Settings</a>.</div>';
}

if ($domains === []) {
    echo '<p class="card empty">Chưa có domain nào.</p>';
} else {
    // One card per record; cards always stack in a single column.
    foreach ($domains as $domain) {
        $record_id = (int) $domain['id'];
        $target = (string) $domain['to'];
        $names = normalize_domains((string) $domain['from']);
        $status = (int) $domain['status'];
        $confirm = 'Xóa record #' . $record_id . '? Các domain: '
            . ($names === [] ? '(không có domain)' : implode(', ', $names));

        echo '<article class="card">';
        echo '<p>';
        echo '<span class="pill" data-kind="' . ($status === 1 ? 'on' : 'off') . '">' . ($status === 1 ? 'Bật' : 'Tắt') . '</span>';

        if ($names === []) {
            echo '<small>(không có domain)</small>';
        } else {
            foreach ($names as $name) {
                $host = str_starts_with($name, '*.') ? substr($name, 2) : $name;
                echo '<a class="pill" data-kind="domain" href="https://' . e($host) . '" target="_blank" rel="noopener noreferrer" title="Mở ' . e($name) . '">' . e($name) . '</a>';
            }
        }

        echo '</p>';
        echo '<p>';

        if ($target === '') {
            echo '<small>(chưa có URL đích)</small>';
        } else {
            echo '<span class="pill" data-kind="backend" title="' . e($target) . '">' . e($target) . '</span>';
        }

        echo '</p>';
        echo '<footer>';
        echo '<a class="btn" data-variant="outline-primary" href="edit.php?id=' . $record_id . '">Sửa</a>';
        echo '<form method="post" action="delete.php" data-confirm="' . e($confirm) . '">';
        echo csrf_field();
        echo '<input type="hidden" name="id" value="' . $record_id . '">';
        echo '<button class="btn" data-variant="outline-danger" type="submit">Xóa</button>';
        echo '</form>';
        echo '</footer>';
        echo '</article>';
    }
}

require __DIR__ . '/_footer.php';
