# PharmaSure WordPress - Windows Quick Start

## Fastest Way to Get Running (10 minutes)

### Step 1: Install Prerequisites

Open PowerShell as Administrator and run:

```powershell
# Install Chocolatey (if not already installed)
Set-ExecutionPolicy Bypass -Scope Process -Force
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072
iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))

# Install required tools
choco install php --version=8.2 -y
choco install mysql --version=8.0 -y
choco install composer -y
choco install git -y
choco install wsl2 -y  # Recommended for better experience

# Verify installations
php --version
mysql --version
composer --version
git --version
```

### Step 2: Start MySQL Service

```powershell
# Start MySQL (it usually starts automatically after installation)
net start MySQL80

# Verify it's running
mysql -u root -e "SELECT 1;"
```

If MySQL won't start:
```powershell
# Reset MySQL
mysql --remove  # Stop MySQL
mysql --install  # Reinstall service
net start MySQL80
```

### Step 3: Create Database

```powershell
# Create database and user
mysql -u root -e @"
CREATE DATABASE pharmasure_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pharmasure'@'localhost' IDENTIFIED BY 'pharmasure_pass';
GRANT ALL PRIVILEGES ON pharmasure_db.* TO 'pharmasure'@'localhost';
FLUSH PRIVILEGES;
"@

# Verify
mysql -u pharmasure -ppharmasure_pass -e "SELECT 'Connection OK';"
```

### Step 4: Clone/Download Project

```powershell
# Create your projects directory
mkdir C:\Projects
cd C:\Projects

# Clone the repository (or extract ZIP)
git clone https://github.com/yourorg/pharmasure-wordpress.git
cd pharmasure-wordpress

# Or: Extract ZIP
# unzip pharmasure-wordpress.zip
# cd pharmasure-wordpress
```

### Step 5: Check System & Install

```powershell
# Check if everything is installed correctly
php setup.php check

# If all checks pass, install WordPress
php setup.php install

# If any PHP extensions are missing, install them:
# See "Fix PHP Extensions" section below
```

### Step 6: Configure Web Server

**Option A: Use PHP Built-in Server (Simplest)**

```powershell
cd C:\Projects\pharmasure-wordpress

# Start PHP development server
php -S localhost:8080

# Access at: http://localhost:8080/wp-admin
# Login: admin / admin123
```

**Option B: Use Apache (Better for Production-like Testing)**

Assuming XAMPP is installed in `C:\xampp`:

```powershell
# Copy project to htdocs
Copy-Item -Recurse "C:\Projects\pharmasure-wordpress" "C:\xampp\htdocs\"

# Add to Apache config (C:\xampp\apache\conf\extra\httpd-vhosts.conf)
# <VirtualHost *:80>
#   ServerName pharmasure.local
#   DocumentRoot "C:\xampp\htdocs\pharmasure-wordpress"
#   <Directory "C:\xampp\htdocs\pharmasure-wordpress">
#     AllowOverride All
#     Require all granted
#   </Directory>
# </VirtualHost>

# Add to hosts file (C:\Windows\System32\drivers\etc\hosts)
# 127.0.0.1 pharmasure.local

# Start Apache via XAMPP Control Panel

# Access at: http://pharmasure.local/wp-admin
```

### Step 7: Test Installation

```powershell
# Check WordPress version
wp core version

# List plugins
wp plugin list

# List multisite sites
wp site list
```

### Step 8: Access Admin

Open browser to:
```
http://localhost:8080/wp-admin
```

**Credentials:**
- Username: `admin`
- Password: `admin123`

---

## Troubleshooting

### "PHP not recognized"

**Problem:** `php : The term 'php' is not recognized`

**Solution:**
```powershell
# Add PHP to PATH
$php_path = "C:\tools\php82"  # Adjust based on your Chocolatey installation
[Environment]::SetEnvironmentVariable("Path", "$([Environment]::GetEnvironmentVariable('Path', 'User'));$php_path", 'User')

# Restart PowerShell and try again
php --version
```

### "MySQL not running"

**Problem:** `ERROR 2002 (HY000): Can't connect to MySQL server`

**Solution:**
```powershell
# Check if service is running
Get-Service MySQL80

# Start it
net start MySQL80

# Or restart it
Restart-Service MySQL80
```

### "PHP Extensions Missing"

**Problem:** `Call to undefined function mysqli_connect()`

**Solution:**

Find PHP installation directory:
```powershell
where php
# Usually: C:\tools\php82
cd C:\tools\php82

# Find php.ini
dir php.ini
```

Edit `php.ini` and uncomment these lines (remove the `;`):
```ini
extension=mysqli
extension=pdo_mysql
extension=gd
extension=curl
extension=openssl
extension=json
extension=zip
extension=bcmath
extension=intl
```

Then restart:
```powershell
# If using PHP built-in server, stop (Ctrl+C) and restart
# Or restart Apache if using XAMPP
```

### "Database Connection Error"

**Problem:** `Error establishing database connection`

**Solution:**
```powershell
# Test connection manually
mysql -u pharmasure -ppharmasure_pass -h localhost pharmasure_db -e "SELECT 1;"

# If it works, check wp-config.php
# Make sure these match exactly:
# define('DB_NAME', 'pharmasure_db');
# define('DB_USER', 'pharmasure');
# define('DB_PASSWORD', 'pharmasure_pass');
# define('DB_HOST', 'localhost');
```

### "Port 8080 Already in Use"

**Problem:** `Address already in use`

**Solution:**
```powershell
# Use different port
php -S localhost:8081

# Or find what's using port 8080
Get-NetTCPConnection -LocalPort 8080 | Select-Object -Property State, OwningProcess
```

---

## Common Commands

### Development Server

```powershell
# Start (port 8080)
php -S localhost:8080

# Start on different port
php -S localhost:8081

# With custom path
cd C:\Projects\pharmasure-wordpress
php -S localhost:8080
```

### WordPress CLI

```powershell
# Check status
wp core version
wp db check
wp plugin list
wp site list

# Run migrations
wp pharmasure-core migrate

# Test isolation
wp pharmasure-core test-isolation

# Test licensing
wp pharmasure-core test-license
```

### Database

```powershell
# Backup database
mysqldump -u pharmasure -ppharmasure_pass pharmasure_db | Out-File -Encoding ASCII "backup-$(Get-Date -Format 'yyyyMMdd-HHmmss').sql"

# Restore from backup
Get-Content "backup-20260716.sql" | mysql -u pharmasure -ppharmasure_pass pharmasure_db

# Direct MySQL queries
mysql -u pharmasure -ppharmasure_pass pharmasure_db -e "SHOW TABLES;"

# Full database client
mysql -u pharmasure -ppharmasure_pass pharmasure_db
```

### File Operations

```powershell
# View WordPress config
Get-Content wp-config.php

# View debug log (last 50 lines)
Get-Content wp-content\debug.log -Tail 50

# Clear debug log
Clear-Content wp-content\debug.log

# View .env settings
Get-Content .env
```

---

## Project Structure

```
pharmasure-wordpress/
├── wp-admin/                          # WordPress admin
├── wp-includes/                       # WordPress core
├── wp-content/
│   ├── plugins/
│   │   ├── pharmasure-core/          # Core plugin
│   │   │   ├── src/
│   │   │   │   ├── TenantContext.php
│   │   │   │   ├── LicenseManager.php
│   │   │   │   └── ...
│   │   │   ├── migrations/
│   │   │   └── pharmasure-core.php
│   │   └── [other plugins]
│   ├── themes/
│   └── uploads/
├── wp-config.php                      # WordPress configuration
├── wp-config-sample.php
├── .env                               # Environment variables
├── .env.example
├── setup.php                          # Installation helper
├── INTENT.md                          # Full specification
├── DEVELOPMENT.md                     # Development guide
├── LOCAL-SETUP.md                     # Local setup guide
├── QUICKSTART.md                      # Quick reference
└── composer.json                      # PHP dependencies
```

---

## Next Steps

1. **Review the specification**: Open `INTENT.md` to understand the full system
2. **Read the development guide**: Open `DEVELOPMENT.md` for plugin development patterns
3. **Start developing**: Create new plugins following the pharmasure-core patterns
4. **Run tests**: Execute `wp pharmasure-core test-isolation` and `wp pharmasure-core test-license`
5. **Deploy**: Follow DEVELOPMENT.md for deployment procedures

---

## Support

For issues or questions:
1. Check `LOCAL-SETUP.md` for detailed local setup
2. Check `DEVELOPMENT.md` for development patterns
3. Check `INTENT.md` for system specification
4. Review `wp-content/debug.log` for error details

---

**Status**: Ready for Local Development  
**Last Updated**: 2026-07-16
