<?php

require __DIR__ . '/_init.php';

$backup_paths = [
    MIZ_DB,
    '/etc/letsencrypt',
];
$backup_archive = MIZ_DATA . '/backup.tar.gz';
$tmp_root = MIZ_DATA . '/tmp';
$backup_staging = $tmp_root . '/backup';
$upload_file = $tmp_root . '/upload.tar.gz';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'export') {
        // WAL chứa phần lớn dữ liệu mới; checkpoint để data.db tự chứa đủ.
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        @unlink($backup_archive);

        $cmd = 'tar -czf ' . escapeshellarg($backup_archive);
        foreach ($backup_paths as $path) {
            $cmd .= ' ' . escapeshellarg($path);
        }

        $output = [];
        $exit_code = 0;
        exec('timeout 300 ' . $cmd . ' 2>&1', $output, $exit_code);

        if ($exit_code !== 0 || !is_file($backup_archive)) {
            @unlink($backup_archive);
            $errors[] = 'Không tạo được file backup: ' . implode(' ', array_slice($output, -10));
        } else {
            // Archive chứa DB và private key SSL.
            @chmod($backup_archive, 0600);

            clearstatcache(true, $backup_archive);
            session_write_close();

            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="miz-hproxy-backup-' . date('YmdHis') . '.tar.gz"');
            header('Content-Length: ' . filesize($backup_archive));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($backup_archive);
            exit;
        }
    } elseif ($action === 'import') {
        $file = $_FILES['backup'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Vui lòng chọn file backup .tar.gz hợp lệ.';
        } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
            $errors[] = 'Upload không hợp lệ.';
        } else {
            if (!is_dir($tmp_root) && !mkdir($tmp_root, 0750, true) && !is_dir($tmp_root)) {
                $errors[] = 'Không tạo được thư mục tạm.';
            }

            if ($errors === []) {
                exec('rm -rf ' . escapeshellarg($backup_staging) . ' 2>&1', $output, $exit_code);

                if (!mkdir($backup_staging, 0750, true) && !is_dir($backup_staging)) {
                    $errors[] = 'Không tạo được thư mục giải nén.';
                } elseif (!move_uploaded_file((string) $file['tmp_name'], $upload_file)) {
                    $errors[] = 'Không lưu được file upload.';
                } else {
                    $output = [];
                    $exit_code = 0;
                    exec(
                        'timeout 300 tar -xzf ' . escapeshellarg($upload_file)
                        . ' -C ' . escapeshellarg($backup_staging) . ' 2>&1',
                        $output,
                        $exit_code
                    );

                    if ($exit_code !== 0) {
                        $errors[] = 'Giải nén thất bại: ' . implode(' ', array_slice($output, -10));
                    } else {
                        foreach ($backup_paths as $path) {
                            if (!file_exists($backup_staging . '/' . ltrim($path, '/'))) {
                                $errors[] = 'File backup thiếu: ' . $path;
                            }
                        }
                    }
                }
            }

            if ($errors === []) {
                $output = [];
                $exit_code = 0;
                exec('systemctl stop miz-hproxy-queue 2>&1', $output, $exit_code);

                if ($exit_code !== 0) {
                    $errors[] = 'Không dừng được queue: ' . implode(' ', array_slice($output, -10));
                } else {
                    // Chỉ ghi đè đúng 2 member trong mảng; symlink/mode/owner giữ nguyên.
                    $cmd = 'tar -xzf ' . escapeshellarg($upload_file) . ' -C /';
                    foreach ($backup_paths as $path) {
                        $cmd .= ' ' . escapeshellarg(ltrim($path, '/'));
                    }

                    $output = [];
                    $exit_code = 0;
                    $start_output = [];
                    $start_code = 0;

                    try {
                        // Tránh WAL cũ ghi đè lên data.db vừa restore.
                        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                        exec('timeout 300 ' . $cmd . ' 2>&1', $output, $exit_code);
                    } catch (Throwable $e) {
                        $errors[] = 'Import thất bại: ' . $e->getMessage();
                    } finally {
                        exec('systemctl start miz-hproxy-queue 2>&1', $start_output, $start_code);
                    }

                    if ($errors === [] && $exit_code !== 0) {
                        $errors[] = 'Ghi đè thất bại: ' . implode(' ', array_slice($output, -10));
                    }

                    if ($errors === [] && $start_code !== 0) {
                        $errors[] = 'Queue không khởi động lại được: ' . implode(' ', array_slice($start_output, -10));
                    }

                    if ($errors === []) {
                        flash_set('success', 'Đã import backup và khôi phục data.db + /etc/letsencrypt/.');
                        redirect('backup.php');
                    }
                }
            }
        }
    } else {
        $errors[] = 'Thao tác không hợp lệ.';
    }
}

$title = 'Backup';
require __DIR__ . '/_header.php';

echo '<header>';
echo '<h1>Backup</h1>';
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

echo '<div class="split">';
echo '<section class="card">';
echo '<h2>Export</h2>';
echo '<p>Sao lưu <code>data/data.db</code> và <code>/etc/letsencrypt/</code> thành file tar.gz.</p>';
echo '<form method="post" action="backup.php">';
echo csrf_field();
echo '<input type="hidden" name="action" value="export">';
echo '<button class="btn" type="submit">Export</button>';
echo '</form>';
echo '</section>';

echo '<section class="card">';
echo '<h2>Import</h2>';
echo '<p>Ghi đè <code>data/data.db</code> và <code>/etc/letsencrypt/</code> từ file backup. Nên Export trước khi Import.</p>';
echo '<form method="post" action="backup.php" enctype="multipart/form-data" data-confirm="Import sẽ ghi đè data.db và /etc/letsencrypt/. Tiếp tục?">';
echo csrf_field();
echo '<input type="hidden" name="action" value="import">';
echo '<label for="backup">File backup (.tar.gz)</label>';
echo '<input type="file" id="backup" name="backup" accept=".tar.gz,.tgz,application/gzip" required>';
echo '<button class="btn" data-variant="outline-danger" type="submit">Import</button>';
echo '</form>';
echo '</section>';
echo '</div>';

require __DIR__ . '/_footer.php';
