<?php

require __DIR__ . '/_init.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$domain = $id > 0 ? get_domain($id) : null;

if ($domain === null) {
    flash_set('danger', 'Không tìm thấy domain.');
    redirect('index.php');
}

$values = [
    'from' => (string) $domain['from'],
    'to' => (string) $domain['to'],
    'status' => (int) $domain['status'] === 0 ? 0 : 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $values['from'] = (string) ($_POST['from'] ?? '');
    $values['to'] = trim((string) ($_POST['to'] ?? ''));
    $values['status'] = (int) ($_POST['status'] ?? 1) === 0 ? 0 : 1;

    [$domain_names, $errors] = validate_domains($values['from']);

    $backend_error = validate_backend($values['to']);

    if ($backend_error !== null) {
        $errors[] = $backend_error;
    }

    if ($errors === []) {
        $stmt = db()->prepare('UPDATE domains SET "from" = :from, "to" = :to, status = :status WHERE id = :id');
        $stmt->execute([
            ':from' => implode("\n", $domain_names),
            ':to' => $values['to'],
            ':status' => (int) $values['status'],
            ':id' => $id,
        ]);
        touch_updated();

        flash_set('success', 'Đã cập nhật domain #' . $id . '.');
        redirect('index.php');
    }
}

$title = 'Sửa domain #' . $id;
require __DIR__ . '/_header.php';

echo '<header>';
echo '<h1>Sửa domain #' . $id . '</h1>';
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
echo '<form method="post" action="edit.php?id=' . $id . '">';
echo csrf_field();
echo '<input type="hidden" name="id" value="' . $id . '">';
echo '<label for="status">Trạng thái</label>';
echo '<select id="status" name="status">';
echo '<option value="1"' . ($values['status'] === 1 ? ' selected' : '') . '>Bật</option>';
echo '<option value="0"' . ($values['status'] === 0 ? ' selected' : '') . '>Tắt</option>';
echo '</select>';
echo '<small>Tắt: domain bị loại khỏi cấu hình HAProxy/Apache.</small>';
echo '<label for="from">Domain</label>';
echo '<textarea id="from" name="from" rows="8" required>' . e($values['from']) . '</textarea>';
echo '<small>Mỗi dòng một domain. Hỗ trợ wildcard dạng <code>*.example.com</code>.</small>';
echo '<label for="to">Backend</label>';
echo '<input type="text" id="to" name="to" value="' . e($values['to']) . '" required placeholder="http://127.0.0.1:8080 hoặc /static">';
echo '<small>URL đích:</small>';
echo '<ul>';
echo '<li><code>http://127.0.0.1:8080</code></li>';
echo '<li><code>https://2.2.2.2:443</code></li>';
echo '<li><code>/static</code> → <code>/www/static</code> (máy Apache phục vụ file tĩnh)</li>';
echo '</ul>';
echo '<button class="btn" type="submit">Lưu</button>';
echo '</form>';
echo '</section>';

require __DIR__ . '/_footer.php';
