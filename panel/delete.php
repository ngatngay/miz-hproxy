<?php

require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$deleted = false;

if ($id > 0) {
    $stmt = db()->prepare('DELETE FROM domains WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $deleted = $stmt->rowCount() > 0;
}

if ($deleted) {
    touch_updated();
    flash_set('success', 'Đã xóa domain #' . $id . '.');
} else {
    flash_set('danger', 'Không tìm thấy domain để xóa.');
}

redirect('index.php');
