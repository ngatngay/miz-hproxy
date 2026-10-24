<?php

if (PHP_SAPI !== 'cli' || getenv('MIZ_HPROXY_QUEUE') !== '1') {
    fwrite(STDERR, "This is a queue service. Run it from CLI with MIZ_HPROXY_QUEUE=1.\n");
    exit(1);
}

require __DIR__ . '/_init.php';

function qlog(string $message): void
{
    $line = '[' . date('H:i d/m/Y') . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n";

    fwrite(STDOUT, $line);
    fflush(STDOUT);

    @file_put_contents(MIZ_QUEUE_LOG, $line, FILE_APPEND | LOCK_EX);
}

$last_updated = 0;

@file_put_contents(MIZ_QUEUE_LOG, '', LOCK_EX);
qlog('INIT QUEUE');

// Xoá certificate đã hết hạn.
$output = [];
$exit_code = 0;
exec('timeout 120 certbot certificates 2>&1', $output, $exit_code);

if ($exit_code !== 0) {
    qlog('certbot certificates lỗi: ' . implode(' ', array_slice($output, -10)));
}

$expired = [];
$cert_name = null;

foreach ($output as $line) {
    if (preg_match('/^\s*Certificate Name:\s*(\S+)\s*$/', $line, $match) === 1) {
        $cert_name = $match[1];
        continue;
    }

    if ($cert_name !== null
        && str_contains($line, 'Expiry Date:')
        && str_contains($line, 'INVALID:')
        && str_contains($line, 'EXPIRED')) {
        $expired[$cert_name] = true;
    }
}

foreach (array_keys($expired) as $name) {
    qlog("Xoá certificate hết hạn {$name}.");

    $output = [];
    $exit_code = 0;
    exec('timeout 60 certbot delete --cert-name ' . escapeshellarg($name) . ' --non-interactive 2>&1', $output, $exit_code);

    if ($exit_code !== 0) {
        qlog("Không xoá được certificate {$name}: " . implode(' ', array_slice($output, -10)));
    }
}

// Renew một lần lúc khởi động (systemd restart service mỗi 24h).
$output = [];
$exit_code = 0;
exec('timeout 900 certbot renew --quiet --no-random-sleep-on-renew 2>&1', $output, $exit_code);

if ($exit_code === 0) {
    qlog('certbot renew hoàn tất.');
} else {
    qlog('certbot renew lỗi: ' . implode(' ', array_slice($output, -10)));
}

while (true) {
    try {
        $current_updated = get_setting('date_updated');

        if ($current_updated === $last_updated) {
            sleep(5);
            continue;
        }

        qlog('Phát hiện thay đổi cấu hình.');

        $haproxy_tpl = file_get_contents(MIZ_TPL . '/haproxy.cfg');
        $apache_tpl = file_get_contents(MIZ_TPL . '/apache-vhost.conf');

        if ($haproxy_tpl === false || $apache_tpl === false) {
            throw new RuntimeException('Không đọc được template haproxy.cfg hoặc apache-vhost.conf.');
        }

        $keepalive = keepalive_timeout_value();
        $cpu_number = (int) trim((string) shell_exec('nproc'));
        $cpu_number = $cpu_number < 1 ? 1 : $cpu_number;

        $domains = [];
        $wanted_apache = [];
        $frontend = "\n\n"
            . "    option http-keep-alive\n"
            . "    timeout http-keep-alive {$keepalive}\n\n";
        $backend = '';

        foreach (get_domains() as $row) {
            $id = (int) $row['id'];

            if ((int) $row['status'] === 0) {
                qlog("Bỏ qua domain #{$id}: trạng thái tắt.");
                continue;
            }

            $domain_names = normalize_domains((string) $row['from']);

            // SSL: mỗi domain unique chỉ xử lý một lần.
            foreach ($domain_names as $domain) {
                if (isset($domains[$domain])) {
                    continue;
                }

                $domains[$domain] = true;

                if (!is_valid_domain($domain)) {
                    qlog("Bỏ qua domain không hợp lệ #{$id}: {$domain}");
                    continue;
                }

                if (str_starts_with($domain, '*.')) {
                    qlog("Bỏ qua SSL wildcard {$domain}, cần cấp thủ công.");
                    continue;
                }

                $cert = '/etc/letsencrypt/live/' . $domain . '/fullchain.pem';

                if (is_file($cert)) {
                    continue;
                }

                qlog("Yêu cầu SSL cho {$domain}.");

                $output = [];
                $exit_code = 0;
                exec(
                    'timeout 300 certbot certonly'
                    . ' --quiet'
                    . ' --standalone'
                    . ' --http-01-port 18082'
                    . ' -d ' . escapeshellarg($domain)
                    . ' --cert-name ' . escapeshellarg($domain)
                    . ' --expand'
                    . ' --non-interactive'
                    . ' --keep-until-expiring'
                    . ' --register-unsafely-without-email'
                    . ' --agree-tos'
                    . ' 2>&1',
                    $output,
                    $exit_code
                );

                if ($exit_code !== 0 || !is_file($cert)) {
                    qlog("Không lấy được SSL cho {$domain}: " . implode(' ', array_slice($output, -10)));
                }
            }

            try {
                $target = parse_backend((string) $row['to']);
            } catch (InvalidArgumentException $e) {
                qlog("Bỏ qua domain #{$id}: " . $e->getMessage());
                continue;
            }

            $valid_domains = [];

            foreach ($domain_names as $domain) {
                if (is_valid_domain($domain)) {
                    $valid_domains[] = $domain;
                }
            }

            if ($valid_domains === []) {
                continue;
            }

            // HAProxy: ACL + backend.
            $acl = 'miz_acl_' . $id;

            foreach ($valid_domains as $domain) {
                if (str_starts_with($domain, '*.')) {
                    $frontend .= "    acl {$acl} ssl_fc_sni -m end " . substr($domain, 1) . "\n";
                } else {
                    $frontend .= "    acl {$acl} ssl_fc_sni -i {$domain}\n";
                }
            }

            $frontend .= "    use_backend miz_backend_{$id} if {$acl}\n\n";

            $ssl_option = $target['scheme'] === 'https' ? ' ssl verify none sni ssl_fc_sni' : '';

            $backend .= "backend miz_backend_{$id}\n";
            $backend .= '    description ' . implode(', ', $valid_domains) . "\n";
            $backend .= "    mode http\n";
            $backend .= "    option forwardfor except 127.0.0.1\n";
            $backend .= "    http-reuse always\n";
            $backend .= "    server proxy {$target['host']}:{$target['port']}"
                . ' check'
                . ' pool-low-conn ' . ($cpu_number * 2)
                . ' pool-purge-delay 900s'
                . $ssl_option;
            $backend .= "\n\n";

            // Apache vhost cho backend static.
            if ($target['path'] === null) {
                continue;
            }

            $dir = rtrim('/www/' . trim($target['path'], '/'), '/');

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            @chown($dir, 'www-data');
            @chgrp($dir, 'www-data');

            $vhost = str_replace(
                ['{{ domain }}', '{{ domains }}', '{{ dir }}'],
                [$valid_domains[0], implode(' ', $valid_domains), $dir],
                $apache_tpl
            );

            $file = '/etc/apache2/sites-available/9-' . $id . '.conf';

            if (file_put_contents($file, $vhost) === false) {
                qlog("Không ghi được Apache vhost: {$file}");
                continue;
            }

            @unlink('/etc/apache2/sites-enabled/9-' . $id . '.conf');
            @symlink($file, '/etc/apache2/sites-enabled/9-' . $id . '.conf');

            $wanted_apache[$file] = true;
        }

        // Xoá Apache vhost không còn dùng.
        foreach (glob('/etc/apache2/sites-available/9-*.conf') ?: [] as $file) {
            if (!isset($wanted_apache[$file])) {
                @unlink($file);
            }
        }

        foreach (glob('/etc/apache2/sites-enabled/9-*.conf') ?: [] as $link) {
            if (!isset($wanted_apache['/etc/apache2/sites-available/' . basename($link)])) {
                @unlink($link);
            }
        }

        // Copy toàn bộ certificate hiện hữu cho HAProxy.
        $wanted_pem = [];
        $copied = 0;

        foreach (glob('/etc/letsencrypt/live/*/fullchain.pem') ?: [] as $fullchain) {
            $live_dir = dirname($fullchain);
            $privkey = $live_dir . '/privkey.pem';

            if (!is_file($privkey)) {
                qlog('Bỏ qua certificate thiếu privkey: ' . basename($live_dir));
                continue;
            }

            $chain = @file_get_contents($fullchain);
            $key = @file_get_contents($privkey);

            if ($chain === false || $key === false) {
                qlog('Không đọc được certificate: ' . basename($live_dir));
                continue;
            }

            $pem = '/var/lib/haproxy/certs/9-' . basename($live_dir) . '.pem';

            if (file_put_contents($pem, $chain . $key) === false) {
                qlog("Không ghi được PEM: {$pem}");
                continue;
            }

            chmod($pem, 0600);
            $wanted_pem[$pem] = true;
            $copied++;
        }

        foreach (glob('/var/lib/haproxy/certs/9-*.pem') ?: [] as $pem) {
            if (!isset($wanted_pem[$pem])) {
                @unlink($pem);
            }
        }

        qlog("Đã đồng bộ {$copied} certificate cho HAProxy.");

        // Apply Apache.
        $output = [];
        $exit_code = 0;
        exec('timeout 60 apachectl configtest 2>&1', $output, $exit_code);

        if ($exit_code !== 0) {
            qlog('apachectl configtest lỗi: ' . implode(' ', array_slice($output, -10)));
        } else {
            $output = [];
            $exit_code = 0;
            exec('timeout 60 systemctl reload apache2 2>&1', $output, $exit_code);

            if ($exit_code !== 0) {
                qlog('Reload apache2 lỗi: ' . implode(' ', array_slice($output, -10)));
            }
        }

        // Apply HAProxy: ghi đè trực tiếp, không backup.
        $config = str_replace('#miz_frontend', $frontend, $haproxy_tpl);
        $config = str_replace('#miz_backend', $backend, $config);

        if (file_put_contents('/etc/haproxy/haproxy.cfg', $config) === false) {
            qlog('Không ghi được /etc/haproxy/haproxy.cfg.');
        } else {
            $output = [];
            $exit_code = 0;
            exec('timeout 60 haproxy -c -f /etc/haproxy/haproxy.cfg 2>&1', $output, $exit_code);

            if ($exit_code !== 0) {
                qlog('HAProxy config lỗi: ' . implode(' ', array_slice($output, -10)));
            } else {
                $output = [];
                $exit_code = 0;
                exec('timeout 60 systemctl restart haproxy 2>&1', $output, $exit_code);

                if ($exit_code !== 0) {
                    qlog('Restart haproxy lỗi: ' . implode(' ', array_slice($output, -10)));
                }
            }
        }

        $last_updated = $current_updated;

        qlog('Đã cập nhật config HAProxy/Apache.');
    } catch (Throwable $e) {
        qlog('ERROR: ' . $e->getMessage());
    }

    sleep(5);
}
