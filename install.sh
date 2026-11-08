#!/usr/bin/env bash
set -euo pipefail

if [ "$(. /etc/os-release && echo "$ID-$VERSION_ID")" != "ubuntu-24.04" ]; then
    echo "Require Ubuntu 24.04 LTS" >&2
    exit 1
fi

# >> install tool
if ! command -v haproxy >/dev/null 2>&1; then
    apt-get update -y
    apt-get install -y --no-install-recommends software-properties-common
    add-apt-repository -n -y ppa:vbernat/haproxy-3.2
fi

apt-get update --yes
apt-get install --yes --no-upgrade sudo haproxy apache2 socat fail2ban fish neovim cron php-cli php-fpm php-sqlite3 ssl-cert python3 python3-pip python3-dev python3-venv libaugeas-dev gcc

pip install ps_mem --break-system-packages

# Certbot

python3 -m venv /opt/certbot/

/opt/certbot/bin/pip install --upgrade pip
/opt/certbot/bin/pip install --upgrade certbot

ln -sf /opt/certbot/bin/certbot /usr/bin/certbot

# <<


# >> setup

## data dir
mkdir -p /opt/miz-hproxy/data

## user service
getent group miz-hproxy >/dev/null || groupadd --system miz-hproxy
getent passwd miz-hproxy >/dev/null || useradd --system --gid miz-hproxy --no-create-home --shell /usr/sbin/nologin miz-hproxy
usermod --home /nonexistent --shell /usr/sbin/nologin miz-hproxy
# <<


# >> create self cert
mkdir -p /var/lib/haproxy/certs
chmod 750 /var/lib/haproxy/certs
chown haproxy:haproxy /var/lib/haproxy/certs

openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /opt/miz-hproxy/data/cert.key \
  -out /opt/miz-hproxy/data/cert.crt \
  -days 3650 \
  -subj "/CN=localhost"

chmod 600 /opt/miz-hproxy/data/cert.key
cat /opt/miz-hproxy/data/cert.crt /opt/miz-hproxy/data/cert.key > /var/lib/haproxy/certs/0.pem
# <<


# >> php
cat > /etc/systemd/system/miz-hproxy-admin.service <<'EOF'
[Unit]
Description=Miz HProxy Admin PHP-FPM
After=network.target

[Service]
Type=simple
ExecStart=/usr/sbin/php-fpm8.3 --allow-to-run-as-root --fpm-config /opt/miz-hproxy/tpl/php-fpm-admin.conf --nodaemonize
Restart=always
RestartSec=2

User=root
Group=root

StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable miz-hproxy-admin
systemctl restart miz-hproxy-admin

# queue

cat > /etc/systemd/system/miz-hproxy-queue.service <<'EOF'
[Unit]
Description=Miz HProxy Config Queue
After=network.target haproxy.service apache2.service
Wants=haproxy.service apache2.service

[Service]
Type=simple
Environment=MIZ_HPROXY_QUEUE=1
ExecStart=/usr/bin/php /opt/miz-hproxy/panel/_queue.php
Restart=always
RestartSec=2
RuntimeMaxSec=86400

User=root
Group=root

StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable miz-hproxy-queue
systemctl restart miz-hproxy-queue

# <<


# >> apache
mkdir -p /www
chmod 750 /www
chown www-data:www-data /www

usermod -aG miz-hproxy www-data

cat > /etc/apache2/ports.conf <<'EOL'
Listen 18080
Listen 18081
EOL

a2dismod -f '*' || true
a2enmod access_compat alias auth_basic authn_core authn_file authz_core authz_host authz_user autoindex deflate dir env expires filter headers mime mpm_event proxy proxy_fcgi proxy_http remoteip rewrite setenvif || true

cp /opt/miz-hproxy/tpl/apache.conf /etc/apache2/conf-available/0-nightmare.conf
a2disconf '*' 1>/dev/null || true
a2enconf '0-*' 1>/dev/null

rm -f /etc/apache2/sites-available/*
cp -f /opt/miz-hproxy/tpl/apache-vhost-default.conf /etc/apache2/sites-available/0-nightmare.conf

a2dissite '*' >/dev/null
a2ensite '*' >/dev/null
apachectl configtest && systemctl restart apache2

# <<


# >> proxy

cp -f /opt/miz-hproxy/tpl/haproxy.cfg /etc/haproxy/haproxy.cfg
haproxy -c -f /etc/haproxy/haproxy.cfg
systemctl restart haproxy

# <<


# >> final setup
chmod 750 /opt/miz-hproxy
chown -R miz-hproxy:miz-hproxy /opt/miz-hproxy
# <<
