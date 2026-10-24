<?php

require __DIR__ . '/_init.php';

$values = [
    'from' => '',
    'to' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $values['from'] = (string) ($_POST['from'] ?? '');
    $values['to'] = trim((string) ($_POST['to'] ?? ''));

    [$domain_names, $errors] = validate_domains($values['from']);

    $backend_error = validate_backend($values['to']);

    if ($backend_error !== null) {
        $errors[] = $backend_error;
    }

    if ($errors === []) {
        $stmt = db()->prepare('INSERT INTO domains ("from", "to") VALUES (:from, :to)');
        $stmt->execute([
            ':from' => implode("\n", $domain_names),
            ':to' => $values['to'],
        ]);
        touch_updated();

        flash_set('success', 'Đã thêm domain.');
        redirect('index.php');
    }
}

$title = 'Thêm domain';
require __DIR__ . '/_header.php';

echo '<header>';
echo '<h1>Thêm domain</h1>';
echo '<a class="btn" data-variant="outline" href="index.php">Quay lại</a>';
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

echo '<section class="card">';
echo '<form method="post" action="add.php">';
echo csrf_field();
echo '<label for="from">Domain</label>';
echo '<textarea id="from" name="from" rows="8" required placeholder="a.com&#10;b.com&#10;*.c.com">' . e($values['from']) . '</textarea>';
echo '<small>Mỗi dòng một domain. Hỗ trợ wildcard dạng <code>*.example.com</code>.</small>';
echo '<label for="to">Backend</label>';
echo '<input type="text" id="to" name="to" value="' . e($values['to']) . '" required placeholder="http://127.0.0.1:8080 hoặc /static">';
echo '<small>URL đích:</small>';
echo '<ul>';
echo '<li><code>http://127.0.0.1:8080</code></li>';
echo '<li><code>https://2.2.2.2:443</code></li>';
echo '<li><code>/static</code> → <code>/www/static</code> (máy Apache phục vụ file tĩnh)</li>';
echo '</ul>';
echo '<button class="btn" type="submit">Thêm</button>';
echo '</form>';
echo '</section>';

require __DIR__ . '/_footer.php';
