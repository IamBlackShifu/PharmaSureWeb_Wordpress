#!/usr/bin/env php
<?php
/**
 * PharmaSure WordPress Installation & Setup Helper
 * 
 * Usage: php setup.php [command]
 * 
 * Commands:
 *   check              Check system requirements
 *   install            Install WordPress and PharmaSure
 *   configure          Configure environment
 *   migrate            Run pending migrations
 *   reset              Reset to clean state (destructive)
 *   status             Show installation status
 */

define('PHARMASURE_SETUP_DIR', __DIR__);

class PharmaSureSetup {
    private $errors = [];
    private $warnings = [];
    private $success = [];
    
    public function run($command = 'check') {
        echo "\n═══════════════════════════════════════════════════════\n";
        echo "  PharmaSure WordPress - Local Setup\n";
        echo "═══════════════════════════════════════════════════════\n\n";
        
        switch (strtolower($command)) {
            case 'check':
                $this->checkRequirements();
                break;
            case 'install':
                $this->checkRequirements();
                if (empty($this->errors)) {
                    $this->install();
                }
                break;
            case 'configure':
                $this->configure();
                break;
            case 'migrate':
                $this->migrate();
                break;
            case 'status':
                $this->status();
                break;
            case 'reset':
                $this->reset();
                break;
            default:
                echo "Unknown command: $command\n";
                echo "Available commands: check, install, configure, migrate, status, reset\n";
                return 1;
        }
        
        $this->report();
        return empty($this->errors) ? 0 : 1;
    }
    
    private function checkRequirements() {
        echo "Checking system requirements...\n";
        
        // PHP Version
        $php_version = phpversion();
        if (version_compare($php_version, '8.2.0', '>=')) {
            $this->success[] = "✓ PHP {$php_version}";
        } else {
            $this->errors[] = "✗ PHP 8.2+ required (current: {$php_version})";
        }
        
        // PHP Extensions
        $required_extensions = ['mysqli', 'pdo_mysql', 'gd', 'zip', 'bcmath', 'intl', 'curl', 'openssl'];
        foreach ($required_extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->success[] = "✓ PHP extension: $ext";
            } else {
                $this->errors[] = "✗ PHP extension missing: $ext";
            }
        }
        
        // MySQL/MariaDB
        if ($this->checkMySQL()) {
            $this->success[] = "✓ MySQL/MariaDB connection OK";
        } else {
            $this->warnings[] = "⚠ MySQL/MariaDB not accessible (install later)";
        }
        
        // WP-CLI
        if ($this->commandExists('wp')) {
            exec('wp --version', $output);
            $this->success[] = "✓ WP-CLI: " . trim($output[0]);
        } else {
            $this->warnings[] = "⚠ WP-CLI not installed (required for WordPress setup)";
        }
        
        // File Permissions
        if (is_writable('.')) {
            $this->success[] = "✓ Directory writable";
        } else {
            $this->errors[] = "✗ Directory not writable";
        }
        
        // .env file
        if (file_exists('.env')) {
            $this->success[] = "✓ .env file exists";
        } else {
            if (file_exists('.env.example')) {
                $this->warnings[] = "⚠ .env not found, but .env.example exists";
            } else {
                $this->warnings[] = "⚠ Neither .env nor .env.example found";
            }
        }
    }
    
    private function install() {
        echo "\nInstalling PharmaSure WordPress...\n";
        
        // Create .env if not exists
        if (!file_exists('.env')) {
            if (file_exists('.env.example')) {
                copy('.env.example', '.env');
                $this->success[] = "Created .env from template";
            } else {
                $this->errors[] = "Cannot create .env - .env.example not found";
                return;
            }
        }
        
        // Check if WordPress already installed
        if (file_exists('wp-config.php')) {
            $this->warnings[] = "WordPress appears to be already installed (wp-config.php exists)";
            return;
        }
        
        // Install WordPress using WP-CLI
        if (!$this->commandExists('wp')) {
            $this->errors[] = "WP-CLI required for installation";
            return;
        }
        
        echo "\nStep 1: Installing WordPress core...\n";
        $url = $this->getEnvValue('WP_HOME') ?? 'http://localhost/pharmasure-wordpress';
        $cmd = "wp core install --url=" . escapeshellarg($url) . " --title='PharmaSure' --admin_user=admin --admin_password=admin123 --admin_email=admin@pharmasure.local --skip-email 2>&1";
        
        exec($cmd, $output, $status);
        if ($status === 0) {
            $this->success[] = "WordPress installed";
        } else {
            $this->errors[] = "WordPress installation failed: " . implode("\n", $output);
            return;
        }
        
        echo "\nStep 2: Converting to Multisite...\n";
        exec('wp core multisite-convert 2>&1', $output, $status);
        if ($status === 0 || strpos(implode($output), 'already') !== false) {
            $this->success[] = "Multisite enabled";
        } else {
            $this->warnings[] = "Multisite conversion: " . implode("\n", $output);
        }
        
        echo "\nStep 3: Activating PharmaSure plugins...\n";
        exec('wp plugin activate pharmasure-core 2>&1', $output, $status);
        if ($status === 0) {
            $this->success[] = "pharmasure-core plugin activated";
        } else {
            $this->warnings[] = "Plugin activation: " . implode("\n", $output);
        }
        
        $this->success[] = "Installation complete!";
    }
    
    private function configure() {
        echo "\nConfiguring environment...\n";
        
        if (!file_exists('.env.example')) {
            $this->errors[] = ".env.example not found";
            return;
        }
        
        $example = file_get_contents('.env.example');
        $env_content = $example;
        
        // Ask for configuration
        echo "\nEnter configuration (press Enter for default):\n\n";
        
        $wp_home = $this->prompt('WordPress Home URL', 'http://localhost/pharmasure-wordpress');
        $db_host = $this->prompt('Database Host', 'localhost');
        $db_name = $this->prompt('Database Name', 'pharmasure_db');
        $db_user = $this->prompt('Database User', 'pharmasure');
        
        // Update env content
        $env_content = str_replace('WP_HOME=', "WP_HOME={$wp_home}\n# Old: ", $env_content);
        $env_content = str_replace('DB_HOST=', "DB_HOST={$db_host}\n# Old: ", $env_content);
        $env_content = str_replace('DB_NAME=', "DB_NAME={$db_name}\n# Old: ", $env_content);
        $env_content = str_replace('DB_USER=', "DB_USER={$db_user}\n# Old: ", $env_content);
        
        file_put_contents('.env', $env_content);
        $this->success[] = ".env configured";
    }
    
    private function migrate() {
        if (!$this->commandExists('wp')) {
            $this->errors[] = "WP-CLI required for migrations";
            return;
        }
        
        echo "\nRunning pending migrations...\n";
        exec('wp pharmasure-core migrate 2>&1', $output, $status);
        
        if ($status === 0) {
            $this->success[] = "Migrations completed";
            foreach ($output as $line) {
                echo "  $line\n";
            }
        } else {
            $this->errors[] = implode("\n", $output);
        }
    }
    
    private function status() {
        echo "\nInstallation Status:\n";
        
        $checks = [
            'wp-config.php exists' => file_exists('wp-config.php'),
            '.env file exists' => file_exists('.env'),
            'wp-content/plugins/pharmasure-core exists' => is_dir('wp-content/plugins/pharmasure-core'),
            'Database migrations created' => is_dir('wp-content/plugins/pharmasure-core/migrations'),
        ];
        
        foreach ($checks as $name => $status) {
            $symbol = $status ? '✓' : '✗';
            echo "  $symbol $name\n";
        }
        
        if (file_exists('wp-config.php')) {
            $this->success[] = "WordPress is configured";
            
            // Try to get more info
            if ($this->commandExists('wp')) {
                exec('wp core version 2>&1', $output);
                echo "  WordPress: " . trim($output[0]) . "\n";
                
                exec('wp site list --field=url 2>&1', $output);
                if (!empty($output) && $output[0] !== '') {
                    echo "  Sites:\n";
                    foreach ($output as $site) {
                        echo "    - $site\n";
                    }
                }
            }
        }
    }
    
    private function reset() {
        echo "\n⚠️  WARNING: This will delete all WordPress data and reset to clean state!\n";
        $confirm = $this->prompt('Type "yes" to confirm', 'no');
        
        if (strtolower($confirm) !== 'yes') {
            echo "Reset cancelled.\n";
            return;
        }
        
        echo "\nResetting installation...\n";
        
        if (file_exists('wp-config.php')) {
            unlink('wp-config.php');
            $this->success[] = "Deleted wp-config.php";
        }
        
        if (file_exists('.env')) {
            unlink('.env');
            $this->success[] = "Deleted .env";
        }
        
        $this->success[] = "Installation reset";
    }
    
    private function checkMySQL() {
        $db_host = $this->getEnvValue('DB_HOST') ?? 'localhost';
        $db_user = $this->getEnvValue('DB_USER') ?? 'pharmasure';
        $db_pass = $this->getEnvValue('DB_PASSWORD') ?? 'pharmasure_pass';
        
        try {
            $conn = new mysqli($db_host, $db_user, $db_pass);
            if ($conn->connect_error) {
                return false;
            }
            $conn->close();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function commandExists($cmd) {
        $output = shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>&1');
        return !empty($output);
    }
    
    private function getEnvValue($key) {
        if (!file_exists('.env')) {
            return null;
        }
        
        $lines = file('.env');
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, $key . '=') === 0) {
                return trim(substr($line, strlen($key) + 1), '\'"');
            }
        }
        
        return null;
    }
    
    private function prompt($question, $default = '') {
        echo $question;
        if ($default) {
            echo " [{$default}]";
        }
        echo ": ";
        
        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);
        
        return $input ?: $default;
    }
    
    private function report() {
        if (!empty($this->success)) {
            echo "\n✓ Success:\n";
            foreach ($this->success as $msg) {
                echo "  $msg\n";
            }
        }
        
        if (!empty($this->warnings)) {
            echo "\n⚠ Warnings:\n";
            foreach ($this->warnings as $msg) {
                echo "  $msg\n";
            }
        }
        
        if (!empty($this->errors)) {
            echo "\n✗ Errors:\n";
            foreach ($this->errors as $msg) {
                echo "  $msg\n";
            }
        }
        
        echo "\n═══════════════════════════════════════════════════════\n\n";
    }
}

// Run CLI
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'check';
    $setup = new PharmaSureSetup();
    exit($setup->run($command));
}
