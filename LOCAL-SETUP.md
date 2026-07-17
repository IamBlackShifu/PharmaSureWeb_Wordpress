# PharmaSure WordPress - Local Development Setup (No Docker)

## System Requirements

- **PHP**: 8.2 or higher with these extensions:
  - mysqli
  - pdo_mysql
  - gd
  - zip
  - bcmath
  - intl
  - json
  - curl
  - openssl

- **MySQL**: 8.0 or higher (or MariaDB 10.5+)

- **Web Server**: Apache (with mod_rewrite enabled) or Nginx

- **Composer**: Latest version (for dependency management)

## Quick Start (5 Minutes)

### 1. Prerequisites Installation

**Windows (with Chocolatey):**
```powershell
choco install php --version=8.2 -y
choco install mysql --version=8.0 -y
choco install composer -y
choco install git -y
```

**macOS (with Homebrew):**
```bash
brew install php@8.2 mysql composer git
brew link php@8.2
```

**Ubuntu/Debian:**
```bash
sudo apt-get update
sudo apt-get install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-gd php8.2-zip php8.2-bcmath php8.2-intl
sudo apt-get install -y mysql-server
sudo apt-get install -y composer git nginx
```

### 2. Set Up Database

**Windows (PowerShell):**
```powershell
# Start MySQL
net start MySQL80

# Create database and user
mysql -u root -e @"
CREATE DATABASE pharmasure_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pharmasure'@'localhost' IDENTIFIED BY 'pharmasure_pass';
GRANT ALL PRIVILEGES ON pharmasure_db.* TO 'pharmasure'@'localhost';
FLUSH PRIVILEGES;
"@
```

**macOS/Linux:**
```bash
# Start MySQL (if not running)
brew services start mysql  # macOS
# or
sudo systemctl start mysql  # Linux

# Create database and user
mysql -u root -e "
CREATE DATABASE pharmasure_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pharmasure'@'localhost' IDENTIFIED BY 'pharmasure_pass';
GRANT ALL PRIVILEGES ON pharmasure_db.* TO 'pharmasure'@'localhost';
FLUSH PRIVILEGES;
"
```

### 3. Download WordPress & PharmaSure

```bash
# Navigate to your web root (e.g., /var/www or C:\xampp\htdocs)
cd /path/to/webroot

# Clone this repository (or download the zip)
git clone https://github.com/yourorg/pharmasure-wordpress.git
cd pharmasure-wordpress

# Or download the dist package:
# unzip pharmasure-wordpress-dist.zip
# cd pharmasure-wordpress
```

### 4. Configure Environment

```bash
# Copy environment template
cp .env.example .env

# Edit .env with your values:
# - WP_HOME=http://localhost/pharmasure-wordpress
# - WP_SITEURL=http://localhost/pharmasure-wordpress
# - DB_HOST=localhost
# - DB_NAME=pharmasure_db
# - DB_USER=pharmasure
# - DB_PASSWORD=pharmasure_pass
```

### 5. Install Dependencies & WordPress

```bash
# Install PHP dependencies
composer install

# Download WordPress core (if not included)
# mkdir -p wordpress
# wp core download --path=wordpress

# Install WordPress
wp core install \
  --url=http://localhost/pharmasure-wordpress \
  --title="PharmaSure" \
  --admin_user=admin \
  --admin_password=admin123 \
  --admin_email=admin@pharmasure.local

# Convert to Multisite
wp core multisite-convert
```

### 6. Activate Plugins

```bash
# Activate core plugin
wp plugin activate pharmasure-core

# Verify
wp plugin list
```

### 7. Set Up Web Server

**Apache (httpd.conf or .htaccess):**

Create `.htaccess` in your WordPress root:
```apache
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /pharmasure-wordpress/
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /pharmasure-wordpress/index.php [L]
</IfModule>
# END WordPress
```

**Nginx:**

Add to your nginx config:
```nginx
server {
    listen 80;
    server_name localhost;
    root /path/to/webroot;

    # Set max upload size
    client_max_body_size 256M;

    # Logging
    access_log /var/log/nginx/pharmasure-access.log;
    error_log /var/log/nginx/pharmasure-error.log;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # WordPress rewrite rules
    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # PHP handling
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;  # Adjust path as needed
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ~$ {
        deny all;
    }

    # Cache static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

### 8. Verify Installation

```bash
# Check WordPress installation
wp core version

# Check plugins
wp plugin list

# Test database connection
wp db check

# List multisite sites
wp site list
```

## Access Your Installation

- **WordPress Admin**: http://localhost/pharmasure-wordpress/wp-admin
- **Frontend**: http://localhost/pharmasure-wordpress/
- **PHPMyAdmin** (if installed): http://localhost/phpmyadmin

**Default Credentials:**
- Username: `admin`
- Password: `admin123`

## Common Development Tasks

### Run Pending Migrations

```bash
wp pharmasure-core migrate
```

### Check Tenant Isolation

```bash
wp pharmasure-core test-isolation
```

### View Logs

**PHP Errors:**
```bash
# Windows
type wp-content\debug.log | tail -50

# macOS/Linux
tail -50 wp-content/debug.log
```

**MySQL Slow Queries:**
```bash
tail -50 /var/log/mysql/slow.log  # Linux
```

### Enable Debug Mode

Edit `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## Troubleshooting

### "Error establishing database connection"

**Solution:**
```bash
# Check MySQL is running
mysql -u pharmasure -ppharmasure_pass -h localhost pharmasure_db -e "SELECT 1"

# Reset connection in wp-config.php
# Verify DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
```

### PHP Extension Missing

```bash
# Check installed extensions
php -m | grep -i "mysqli\|gd\|zip"

# Install missing (Ubuntu/Debian)
sudo apt-get install php8.2-EXTENSION_NAME
sudo systemctl restart php8.2-fpm
```

### Multisite Not Converting

```bash
# Try manual conversion with verbose output
wp core multisite-convert --subdomains

# If fails, check table prefix in wp-config.php
wp eval 'echo $GLOBALS["table_prefix"];'
```

### Permission Denied Errors

```bash
# Fix WordPress directory permissions
chmod -R 755 wp-content
chmod -R 755 wp-config.php

# Set proper ownership (Linux)
sudo chown -R www-data:www-data /var/www/pharmasure-wordpress
```

## Development Tips

### Create a New Tenant (After Multisite Setup)

```bash
# Create new site
wp site create --slug=tenant2 --title="Tenant 2"

# List all sites
wp site list

# Switch to specific site for admin
wp site select tenant2
```

### Database Backup

```bash
# Backup
mysqldump -u pharmasure -ppharmasure_pass pharmasure_db > backup-$(date +%Y%m%d-%H%M%S).sql

# Restore
mysql -u pharmasure -ppharmasure_pass pharmasure_db < backup-20260716-120000.sql
```

### Test License Validation

```bash
wp pharmasure-core test-license
```

## Performance Tuning

### MySQL Optimization

Edit `my.cnf` or `my.ini`:
```ini
[mysqld]
max_connections = 500
buffer_pool_size = 256M
innodb_log_file_size = 100M
slow_query_log = 1
long_query_time = 2
```

### PHP-FPM Optimization

Edit `php-fpm.conf`:
```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

### WordPress Optimization

```bash
# Install caching plugin
wp plugin install wp-super-cache --activate

# Or use Redis (if installed)
wp plugin install redis-cache --activate
```

## Security Checklist

- [ ] Change default admin password from `admin123`
- [ ] Set proper file permissions (644 files, 755 directories)
- [ ] Enable SSL/TLS (https://)
- [ ] Configure firewall to allow only necessary ports
- [ ] Keep WordPress and plugins updated
- [ ] Regular database backups
- [ ] Enable WordPress security headers
- [ ] Use environment variables for secrets (never in code)

## Next Steps

1. **Review INTENT.md** for complete specification
2. **Read DEVELOPMENT.md** for plugin development patterns
3. **Start building domain plugins** following the pharmasure-core patterns
4. **Set up Git workflow** for version control
5. **Configure CI/CD** for automated testing and deployment

## Support Resources

- [WordPress Handbook](https://developer.wordpress.org/)
- [WordPress Multisite](https://developer.wordpress.org/plugins/multisite/)
- [WP-CLI Documentation](https://developer.wordpress.org/cli/)
- [PharmaSure DEVELOPMENT.md](./DEVELOPMENT.md)
- [PharmaSure INTENT.md](./INTENT.md)

---

**Last Updated**: 2026-07-16  
**Status**: Ready for Local Development
