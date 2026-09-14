# Deploy PharmaSure on Oracle Linux 7.9

This deployment runs PHP 8.2, WordPress, MySQL 8, Redis, and the internal Nginx
server in containers. A host Nginx instance terminates HTTPS and proxies only to
`127.0.0.1:8080`; the database and Redis are never published on the host.

> Oracle Linux 7 entered Extended Support in January 2025. Use OL8 or OL9 for a
> new production server if you can. This guide exists for an OL7.9 constraint.

## 1. Network and DNS

Point the domain's A/AAAA record at the server. In OCI, allow inbound TCP 22,
80, and 443 in both the VCN security list/NSG and the instance firewall. Do not
open ports 3306, 6379, 9000, or 8080 publicly.

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

## 2. Install the container runtime

Copy or clone the entire repository to `/opt/pharmasure`, then run:

```bash
cd /opt/pharmasure
sudo bash deploy/oracle-linux-7/install-container-runtime.sh
uname -r
sudo docker info
```

Oracle's runtime requires UEK R5 or later. If the installer updates the kernel,
reboot, reconnect, and repeat the last two checks. The script installs the final
Compose v1 release because current Docker Compose v2 does not target OL7's old
userspace. Treat the host as a migration bridge, not a long-term platform.

## 3. Create production secrets

```bash
cd /opt/pharmasure/deploy/oracle-linux-7
cp .env.production.example .env.production
chmod 600 .env.production
openssl rand -base64 48
```

Edit `.env.production`; set the real domain, database passwords, all eight
WordPress keys/salts, and PharmaSure keys. Run `openssl rand -base64 48` once
for every secret. Do not reuse values and do not commit this file.

## 4. Start the application

The relative mounts require the repository to remain intact.

```bash
cd /opt/pharmasure/deploy/oracle-linux-7
sudo docker-compose --env-file .env.production \
  -f docker-compose.production.yml config --quiet
sudo docker-compose --env-file .env.production \
  -f docker-compose.production.yml up -d --build
sudo docker-compose --env-file .env.production \
  -f docker-compose.production.yml ps
curl --fail http://127.0.0.1:8080/health
```

On first boot, open the HTTPS site and complete WordPress installation. Then
activate the PharmaSure plugins and theme. If WP-CLI is preferred:

```bash
sudo docker-compose --env-file .env.production -f docker-compose.production.yml \
  exec wordpress php -r 'echo "PHP ".PHP_VERSION.PHP_EOL;'
```

## 5. Configure HTTPS

Obtain a trusted certificate using your organisation's ACME/client process or
OCI Load Balancer. If terminating TLS on this host, place the certificate and
private key at the paths in `nginx-host.conf.example`, then:

```bash
sudo cp nginx-host.conf.example /etc/nginx/conf.d/pharmasure.conf
sudo sed -i 's/pharmasure\.example\.com/your.real.domain/g' \
  /etc/nginx/conf.d/pharmasure.conf
sudo nginx -t
sudo systemctl enable --now nginx
sudo systemctl reload nginx
```

Never start with the example certificate paths unresolved. If TLS terminates at
an OCI load balancer, proxy it to host port 80 or 8080 over a private subnet and
preserve `Host` and `X-Forwarded-Proto: https`; adjust the host Nginx listener.

## 6. Verify

```bash
curl -I https://your.real.domain/health
curl -I https://your.real.domain/wp-login.php
sudo docker-compose --env-file .env.production -f docker-compose.production.yml ps
sudo docker-compose --env-file .env.production -f docker-compose.production.yml logs --tail=100
```

In WordPress, use a subdirectory multisite unless wildcard DNS and a wildcard
certificate are deliberately configured. Network-activate required plugins and
run the repository's production-readiness checks before admitting real data.

## Updates

Back up first, deploy the new repository revision, and rebuild:

```bash
cd /opt/pharmasure/deploy/oracle-linux-7
sudo docker-compose --env-file .env.production -f docker-compose.production.yml pull
sudo docker-compose --env-file .env.production -f docker-compose.production.yml \
  up -d --build --remove-orphans
```

Pin image digests in `docker-compose.production.yml` for a controlled production
change process; test each image update in staging first.

## Backup and restore

Create a database dump and archive uploads (the two stateful assets):

```bash
mkdir -p /opt/pharmasure-backups
cd /opt/pharmasure/deploy/oracle-linux-7
set -a; source .env.production; set +a
sudo docker-compose --env-file .env.production -f docker-compose.production.yml \
  exec -T db mysqldump -uroot -p"$DB_ROOT_PASSWORD" --single-transaction \
  --routines --triggers "$DB_NAME" | gzip > \
  "/opt/pharmasure-backups/db-$(date +%F-%H%M%S).sql.gz"
sudo docker run --rm --volumes-from "${COMPOSE_PROJECT_NAME}_wordpress_1" \
  -v /opt/pharmasure-backups:/backup alpine \
  tar czf "/backup/uploads-$(date +%F-%H%M%S).tar.gz" \
  -C /var/www/html/wp-content uploads
unset DB_ROOT_PASSWORD DB_PASSWORD
```

Container names can differ; get the exact WordPress name with
`sudo docker-compose ... ps -q wordpress`. Encrypt backups, copy them off-host,
set retention, and regularly test restoration. A basic database restore is:

```bash
gunzip -c /path/to/db.sql.gz | sudo docker-compose --env-file .env.production \
  -f docker-compose.production.yml exec -T db \
  mysql -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME"
```

## Troubleshooting

```bash
sudo docker-compose --env-file .env.production -f docker-compose.production.yml ps
sudo docker-compose --env-file .env.production -f docker-compose.production.yml logs -f wordpress nginx db
sudo journalctl -u docker -u nginx --since "30 minutes ago"
sudo ss -lntp
getenforce
```

If host Nginx returns `502`, confirm `curl http://127.0.0.1:8080/health` works.
On an enforcing SELinux host, permit the host proxy's loopback connection with
`sudo setsebool -P httpd_can_network_connect 1`; do not disable SELinux.

