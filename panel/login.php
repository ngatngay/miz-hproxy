<?php

define('MIZ_NO_AUTH', true);

require __DIR__ . '/_init.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    redirect('login.php');
}

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password = (string) ($_POST['password'] ?? '');

    if (verify_password($password)) {
        session_regenerate_id(true);
        $_SESSION['miz_authenticated'] = true;
        flash_set('success', 'Đăng nhập thành công.');
        redirect('index.php');
    }

    usleep(500000);
    $error = 'Mật khẩu không đúng.';
}

$title = 'Đăng nhập';
$hide_nav = true;
require __DIR__ . '/_header.php';

echo '<section class="card login">';
echo '<h1>Miz HProxy</h1>';

if ($error !== '') {
    echo '<div class="alert" data-type="danger" role="alert">' . e($error) . '</div>';
}

echo '<form method="post" action="login.php" autocomplete="off">';
echo csrf_field();
echo '<label for="password">Mật khẩu</label>';
echo '<input type="password" id="password" name="password" required autofocus>';
echo '<button class="btn" type="submit">Đăng nhập</button>';
echo '</form>';
echo '</section>';

require __DIR__ . '/_footer.php';
