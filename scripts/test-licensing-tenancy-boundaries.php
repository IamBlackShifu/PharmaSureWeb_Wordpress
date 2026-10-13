<?php
/** Actual licensing/provisioning/access services with isolated WordPress/database boundaries. */
const ARRAY_A = 'ARRAY_A';
const PHARMASURE_TABLE_PREFIX = 'ps_';
class WP_Error { public function __construct(public $code, public $message = '', public $data = null) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
function wp_generate_password($length, ...$args) { return str_repeat('a', $length); }
function do_action(...$args) {}
function wp_cache_get($key) { return $GLOBALS['cache'][$key] ?? false; }
function wp_cache_set($key, $value, ...$args) { $GLOBALS['cache'][$key] = $value; }
function wp_cache_delete($key) { unset($GLOBALS['cache'][$key]); }
function is_multisite() { return true; }
function current_user_can($cap) { return $GLOBALS['network_admin'] && $cap === 'manage_network_options'; }
function is_super_admin() { return $GLOBALS['network_admin']; }
function is_user_logged_in() { return true; }
function is_main_site() { return false; }
function wp_doing_ajax() { return false; }
$network_admin = false; $cache = []; $checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; echo "PASS: $message\n"; }
class BoundaryDatabase {
 public $prefix = 'wp_3_', $base_prefix = 'wp_', $insert_id = 0, $inserts = [], $queries = [];
 public function prepare($sql, ...$args) { $this->queries[] = [$sql,$args]; return $sql; }
 public function get_row($sql, $format) { return null; }
 public function get_var($sql) { return 0; }
 public function insert($table, $data) { $this->inserts[] = [$table,$data]; ++$this->insert_id; return 1; }
}
$wpdb = new BoundaryDatabase();
$root = dirname(__DIR__) . '/wp-content/plugins/';
require $root . 'pharmasure-core/src/LicenseManager.php';
require $root . 'pharmasure-tenancy/src/Services/TenantProvisioningService.php';
require $root . 'pharmasure-core/src/AppLauncher.php';
require $root . 'pharmasure-core/src/Admin/WhiteLabel.php';
$cache['pharmasure_license_13'] = ['id'=>1,'tenant_id'=>13,'status'=>'active','entitlements'=>['inventory','pos'],'cache_expires_at'=>time()+60];
$manager = new PharmaSure\Core\LicenseManager(13);
check(!is_wp_error($manager->enforce_entitlement('pos')), 'licensed tenant retains POS entitlement');
check($manager->enforce_entitlement('claims')->code === 'entitlement_required', 'unlicensed module remains denied');
check($manager->enforce_entitlement('pos','malformed-token')->code === 'invalid_token_format', 'invalid offline token cannot reuse online licence');
check((new PharmaSure\Core\LicenseManager(14))->validate_license()->code === 'license_not_found', 'another tenant cannot reuse cached licence');
check(end($wpdb->queries)[1] === [14], 'licence lookup uses the requested tenant identity');
check(str_contains(end($wpdb->queries)[0],'wp_3_ps_licences'), 'licence query remains site scoped');
$provisioning = new PharmaSure\Tenancy\Services\TenantProvisioningService();
check($provisioning->create([])->code === 'platform_admin_required', 'pharmacy users cannot provision tenants');
check(count($wpdb->inserts) === 0, 'denied provisioning creates no tenant or licence records');
$issue = new ReflectionMethod($provisioning,'ensure_license');
$issue->invoke($provisioning,'wp_3_ps_',13,gmdate('Y-m-d H:i:s'));
$licences = array_values(array_filter($wpdb->inserts,fn($r)=>$r[0]==='wp_3_ps_licences'));
check(count($licences)===1 && $licences[0][1]['tenant_id']===13 && $licences[0][1]['status']==='trial', 'new pharmacy receives its own trial licence');
check(abs(strtotime($licences[0][1]['expires_at'])-time()-30*86400)<10, 'trial keeps its 30-day duration');
$entitlements = array_values(array_filter($wpdb->inserts,fn($r)=>$r[0]==='wp_3_ps_licence_entitlements'));
check(array_column(array_column($entitlements,1),'entitlement_key')===['inventory','multi_branch','pos','clinical','claims','reporting','accounts','offline'], 'onboarding keeps all eight trial modules');
$quotas = array_values(array_filter($wpdb->inserts,fn($r)=>$r[0]==='wp_3_ps_licence_quotas'));
check(count($quotas)===9, 'onboarding keeps all nine operational quotas');
$network_admin = true;
PharmaSure\Core\AppLauncher::redirect_staff_admin();
check(true, 'super administrator bypasses staff workspace redirect');
check(!PharmaSure\Core\Admin\WhiteLabel::is_tenant_user(), 'platform administrator keeps network administration controls');
echo "$checks checks passed. Full site creation/migrations require WordPress integration UAT.\n";
