<?php
/**
 * Plugin Name: PharmaSure Offline
 * Description: Scoped device credentials and conflict-safe offline mutation intake.
 * Version: 1.2.0
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core, pharmasure-integrations
 * Network: true
 */
namespace PharmaSure\Offline;
if(!defined('ABSPATH')){exit;}const VERSION='1.2.0';const DB_VERSION='2';
spl_autoload_register(static function($class){$prefix=__NAMESPACE__.'\\';if(0!==strpos($class,$prefix)){return;}$file=__DIR__.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_readable($file)){require_once $file;}});
require_once __DIR__.'/src/Installer.php';register_activation_hook(__FILE__,array(Installer::class,'install'));
add_action('plugins_loaded',static function(){if(!class_exists('PharmaSure\Core\TenantContext')||!class_exists('PharmaSure\Integrations\Services\CredentialVault')){return;}if(DB_VERSION!==get_site_option('pharmasure_offline_db_version')){Installer::install();}Admin\OfflineAdmin::register_actions();add_action('rest_api_init',array(Rest\OfflineController::class,'register_routes'));add_action('admin_menu',array(Admin\OfflineAdmin::class,'register_page'));add_action('pharmasure_replay_offline_mutations',array(Services\ReplayService::class,'process_due'));},70);
add_filter('cron_schedules',static function($s){if(!isset($s['pharmasure_five_minutes'])){$s['pharmasure_five_minutes']=array('interval'=>300,'display'=>'Every five minutes');}return $s;});
add_action('init',static function(){if(!wp_next_scheduled('pharmasure_replay_offline_mutations')){wp_schedule_event(time()+180,'pharmasure_five_minutes','pharmasure_replay_offline_mutations');}});
add_action('template_redirect',static function(){if(isset($_GET['pharmasure_offline_sw'])){nocache_headers();header('Content-Type: application/javascript; charset=utf-8');header('Service-Worker-Allowed: /');readfile(__DIR__.'/assets/service-worker.js');exit;}if(isset($_GET['pharmasure_manifest'])){nocache_headers();header('Content-Type: application/manifest+json; charset=utf-8');echo wp_json_encode(array('name'=>'PharmaSure Pharmacy','short_name'=>'PharmaSure','start_url'=>home_url('/'),'display'=>'standalone','background_color'=>'#ffffff','theme_color'=>'#0b6b57'));exit;}});
add_action('wp_head',static function(){echo '<link rel="manifest" href="'.esc_url(home_url('/?pharmasure_manifest=1')).'"><meta name="theme-color" content="#0b6b57">';});
add_action('wp_footer',static function(){if(is_user_logged_in()){echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",()=>navigator.serviceWorker.register('.wp_json_encode(home_url('/?pharmasure_offline_sw=1')).',{scope:"/"}));}</script>';}});
