<?php
/**
 * Plugin Name: PharmaSure Integrations
 * Description: Secure provider configurations, signed webhooks, callbacks and retryable outbox delivery.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Network: true
 */
namespace PharmaSure\Integrations;
if(!defined('ABSPATH')){exit;} const VERSION='1.0.0';const DB_VERSION='1';
spl_autoload_register(static function($class){$prefix=__NAMESPACE__.'\\';if(0!==strpos($class,$prefix)){return;}$file=__DIR__.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_readable($file)){require_once $file;}});
require_once __DIR__.'/src/Installer.php';register_activation_hook(__FILE__,array(Installer::class,'install'));
add_action('plugins_loaded',static function(){if(!class_exists('PharmaSure\Core\TenantContext')){return;}if(DB_VERSION!==get_site_option('pharmasure_integrations_db_version')){Installer::install();}add_action('rest_api_init',array(Rest\IntegrationController::class,'register_routes'));add_action('pharmasure_process_integration_outbox',array(Services\DeliveryService::class,'process_due'));},60);
add_action('init',static function(){if(!wp_next_scheduled('pharmasure_process_integration_outbox')){wp_schedule_event(time()+120,'pharmasure_five_minutes','pharmasure_process_integration_outbox');}});
add_filter('cron_schedules',static function($s){$s['pharmasure_five_minutes']=array('interval'=>300,'display'=>'Every five minutes');return $s;});
