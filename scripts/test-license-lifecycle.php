<?php
/** Regression coverage for the expired-trial/grace failure observed on Greenwood. */
const ARRAY_A='ARRAY_A';const PHARMASURE_TABLE_PREFIX='ps_';
class WP_Error {public function __construct(private $code,private $message='',private $data=[]){ } public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}public function get_error_data(){return $this->data;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return $x;}
function do_action(...$args){}
$cache=[];
function wp_cache_get($key){return $GLOBALS['cache'][$key]??false;}
function wp_cache_set($key,$value,...$args){$GLOBALS['cache'][$key]=$value;}
function wp_cache_delete($key){unset($GLOBALS['cache'][$key]);}
class LicenceDatabase {
 public $prefix='wp_3_', $row=null, $queries=[];
 public function prepare($sql,...$args){$this->queries[]=$sql;return $sql;}
 public function get_row($sql,$format){
  if(!$this->row||!in_array($this->row['status'],['active','trial','grace'],true))return null;
  // Honor the former SQL cutoff: a mock must not hide the original grace-period regression.
  if(str_contains($sql,'l.expires_at > NOW()')&&!empty($this->row['expires_at'])&&strtotime($this->row['expires_at'].' UTC')<=time())return null;
  return $this->row;
 }
 public function get_col($sql){return ['inventory','pos','clinical','claims','reporting','accounts','offline'];}
 public function get_results($sql){return [(object)['quota_key'=>'max_branches','limit_value'=>10]];}
}
$wpdb=new LicenceDatabase();require dirname(__DIR__).'/wp-content/plugins/pharmasure-core/src/LicenseManager.php';
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);++$checks;echo "PASS: $message\n";}
function fixture($expires,$days=7,$status='trial'){
 global $wpdb,$cache;$cache=[];$wpdb->row=['id'=>1,'tenant_id'=>1,'status'=>$status,'expires_at'=>$expires,'grace_period_days'=>$days,'licence_key'=>'must-not-be-public'];return new PharmaSure\Core\LicenseManager(1);
}
$manager=fixture(gmdate('Y-m-d H:i:s',time()-86400));
$license=$manager->validate_license();check(!is_wp_error($license)&&$license['status']==='grace','expired trial within seven days remains licensed');
foreach(['inventory','pos','clinical','claims','reporting','accounts','offline'] as $feature){check(!is_wp_error($manager->enforce_entitlement($feature)),"grace permits entitled $feature workspace");}
check($manager->enforce_entitlement('unlicensed')->get_error_code()==='entitlement_required','grace does not unlock features outside the plan');
$summary=$manager->summary();check($summary['grace_until']===gmdate('Y-m-d H:i:s',strtotime($wpdb->row['expires_at'].' UTC')+7*86400),'public notice uses the exact recorded grace boundary');
check(!isset($summary['licence_key'])&&!isset($summary['entitlements']),'public licence summary excludes licence keys and plan details');
$manager=fixture(gmdate('Y-m-d H:i:s',time()-8*86400));
$error=$manager->validate_license();check(is_wp_error($error)&&$error->get_error_code()==='license_expired'&&$error->get_error_data()['status']===403,'after grace, expired licence fails closed with actionable 403');
$manager=fixture(gmdate('Y-m-d H:i:s',time()+86400));check($manager->validate_license()['status']==='trial','unexpired trial retains trial status');
$manager=fixture(null,7,'active');check($manager->validate_license()['status']==='active','non-expiring active licence remains available');
$manager=fixture(gmdate('Y-m-d H:i:s',time()-1),0);check(is_wp_error($manager->validate_license()),'zero-day grace expires at the recorded expiry');
$manager=fixture(gmdate('Y-m-d H:i:s',time()-86400));$license=$manager->validate_license();
$cache['pharmasure_license_1']['expires_at']=gmdate('Y-m-d H:i:s',time()-8*86400);$wpdb->row['expires_at']=$cache['pharmasure_license_1']['expires_at'];
check(is_wp_error($manager->validate_license()),'an hour-long cache cannot outlive the grace boundary');
foreach(['suspended','revoked'] as $status){$manager=fixture(gmdate('Y-m-d H:i:s',time()+86400),7,$status);check(is_wp_error($manager->validate_license()),"$status licence cannot regain access through grace");}
check(!is_wp_error(fixture(gmdate('Y-m-d H:i:s',time()-86400),7,'grace')->validate_license()),'persisted grace status remains valid within its deadline');
echo "$checks licence lifecycle checks passed.\n";
