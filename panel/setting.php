<?php

require __DIR__ . '/_init.php';

$errors = [];

$keepalive = (string) get_setting('client_keepalive_timeout', '60');
$using_default_password = password_verify('!!!123456789!!!', (string) get_setting('password', ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old_keepalive = $keepalive;
    $new_keepalive = trim((string) ($_POST['client_keepalive_timeout'] ?? ''));
    $new_password = (string) ($_POST['password'] ?? '');
    $keepalive = $new_keepalive;

    if (preg_match('/^\d+(ms|s|m|h|d)?$/', $new_keepalive) !== 1) {
        $errors[] = 'client_keepalive_timeout phải là số, có thể kèm đơn vị ms/s/m/h/d.';
    }

    if ($new_password !== '' && strlen($new_password) < 6) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    }

    if ($errors === []) {
        $changed = false;

        if ($new_keepalive !== $old_keepalive) {
            set_setting('client_keepalive_timeout', $new_keepalive);
            $keepalive = $new_keepalive;
            $changed = true;
        }

        if ($new_password !== '') {
            set_setting('password', password_hash($new_password, PASSWORD_DEFAULT));
            $using_default_password = false;
            $changed = true;
        }

        if ($changed) {
            touch_updated();
            flash_set('success', 'Đã lưu cài đặt.');
        } else {
            flash_set('info', 'Không có thay đổi.');
        }

        redirect('setting.php');
    }
}

$updated = (string) get_setting('date_updated', '');
$updated_at = is_numeric($updated) ? date('Y-m-d H:i:s', (int) $updated) : '-';

$title = 'Settings';
require __DIR__ . '/_header.php';

echo '<header>';
echo '<h1>Settings</h1>';
echo '</header>';

if ($errors !== []) {
    echo '<div class="alert" data-type="danger" role="alert">';
    echo '<ul>';
    foreach ($errors as $error) {
        echo '<li>' . e($error) . '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

if ($using_default_password) {
    echo '<div class="alert" data-type="warning">Bạn đang dùng mật khẩu mặc định, hãy đổi mật khẩu bên dưới.</div>';
}

echo '<div class="split">';
echo '<section class="card">';
echo '<form method="post" action="setting.php" autocomplete="off">';
echo csrf_field();
echo '<label for="client_keepalive_timeout">client_keepalive_timeout</label>';
echo '<input type="text" id="client_keepalive_timeout" name="client_keepalive_timeout" value="' . e($keepalive) . '" required>';
echo '<small>Giá trị số thuần sẽ được hiểu là giây, ví dụ <code>60</code> → <code>60s</code>.</small>';
echo '<label for="password">Mật khẩu mới</label>';
echo '<input type="password" id="password" name="password" autocomplete="new-password" placeholder="Để trống nếu không đổi">';
echo '<button class="btn" type="submit">Lưu</button>';
echo '</form>';
echo '</section>';
echo '<section class="card">';
echo '<h2>Thông tin</h2>';
echo '<dl>';
echo '<dt>Cập nhật gần nhất</dt>';
echo '<dd>' . e($updated_at) . '</dd>';
echo '<dt>Queue</dt>';
echo '<dd>Tự động generate config mỗi 5 giây khi có thay đổi.</dd>';
echo '</dl>';
echo '</section>';
echo '</div>';

require __DIR__ . '/_footer.php';
