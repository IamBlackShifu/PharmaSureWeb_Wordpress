<?php
/** Service/access contract tests with an in-memory WordPress boundary; no live DB required. */
namespace PharmaSure\Core {
	final class TenantContext {
		public static function instance() { return new self(); }
		public function get_tenant_id() { return 13; }
		public function get_branch_id() { return 7; }
		public function set_branch($branch) { return $branch === 7; }
	}
	final class LicenseManager { public function __construct($tenant) {} public function enforce_entitlement($module) { return ['quotas'=>[]]; } }
}
namespace {
	const ARRAY_A = 'ARRAY_A';
	$caps = []; $options = []; $audit = []; $user_meta = []; $routes = [];
	function current_user_can($cap) { global $caps; return !empty($caps[$cap]); }
	function get_current_user_id() { return 9; }
	function get_option($key,$default=false) { global $options; return $options[$key]??$default; }
	function update_option($key,$value,$autoload=false) { global $options; $options[$key]=$value; return true; }
	function get_user_meta($user,$key,$single=false) { global $user_meta; return $user_meta[$user][$key]??''; }
	function update_user_meta($user,$key,$value) { global $user_meta; $user_meta[$user][$key]=$value; return true; }
	function sanitize_text_field($v) { return trim(strip_tags((string)$v)); }
	function sanitize_textarea_field($v) { return trim(strip_tags((string)$v)); }
	function sanitize_key($v) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',(string)$v)); }
	function absint($v) { return abs((int)$v); }
	function current_time($format,$gmt=false) { return '2026-10-09 10:00:00'; }
	function wp_json_encode($v) { return json_encode($v); }
	function apply_filters($name,$value,...$args) { return $value; }
	function do_action($name,$value) { global $audit; $audit[]=$value; }
	function rest_ensure_response($value) { return $value; }
	function is_wp_error($v) { return $v instanceof WP_Error; }
	function register_rest_route($ns,$path,$args) { global $routes; $routes[$path]=$args; }
	class MemoryRole { public array $capabilities=[]; public function add_cap($cap){$this->capabilities[$cap]=true;} }
	$roles=['administrator'=>new MemoryRole()];
	function get_role($name){global $roles;return $roles[$name]??null;}
	function add_role($name,$label,$caps){global $roles;$roles[$name]=new MemoryRole();$roles[$name]->capabilities=$caps;return $roles[$name];}
	class WP_Error { public function __construct(public $code,public $message,public $data=[]){} }
	class Request {
		public function __construct(private array $data=[]){}
		public function get_json_params(){return $this->data;}
		public function get_param($name){return $this->data[$name]??null;}
		public function get_header($name){return '';}
	}
	class MemoryDB {
		public string $prefix='wp_'; public int $insert_id=0; public array $tables=[];
		public array $products=[1=>['id'=>1,'tenant_id'=>13,'dosage_form'=>'tablet','unit_of_measure'=>'tablets','pack_size'=>'10'],2=>['id'=>2,'tenant_id'=>13,'dosage_form'=>'suspension','unit_of_measure'=>'bottle','pack_size'=>'100']];
		public function prepare($sql,...$args){foreach($args as $arg){$sql=preg_replace('/%[ds]/',is_numeric($arg)?(string)$arg:"'".str_replace("'","''",$arg)."'",$sql,1);}return $sql;}
		private function tenant($sql){preg_match('/(?:d\.|s\.)?tenant_id=(\d+)/',$sql,$m);return(int)($m[1]??0);}
		public function get_results($sql,$mode=null){
			$tenant=$this->tenant($sql);
			if(str_contains($sql,'sig_definitions')){ $rows=array_values(array_filter($this->tables['wp_ps_sig_definitions']??[],fn($r)=>$r['tenant_id']===$tenant)); $latest=[];foreach($rows as $r){if(!isset($latest[$r['code']])||$r['version']>$latest[$r['code']]['version'])$latest[$r['code']]=$r;}return array_values($latest); }
			if(str_contains($sql,'sig_instructions'))return array_map(fn($r)=>array_merge($r,['drug_id'=>1]),array_values(array_filter($this->tables['wp_ps_sig_instructions']??[],fn($r)=>$r['tenant_id']===$tenant)));
			return [];
		}
		public function get_var($sql){if(str_contains($sql,'MAX(version)')){preg_match("/code='([^']+)'/",$sql,$m);$versions=array_column(array_filter($this->tables['wp_ps_sig_definitions']??[],fn($r)=>$r['tenant_id']===$this->tenant($sql)&&$r['code']===($m[1]??'')),'version');return $versions?max($versions):0;}return 0;}
		public function get_row($sql,$mode=null){
			if(str_contains($sql,'ps_drugs')){preg_match('/id=(\d+)/',$sql,$m);$p=$this->products[(int)($m[1]??0)]??null;return $p&&$p['tenant_id']===$this->tenant($sql)?$p:null;}
			if(str_contains($sql,'sig_instructions')){preg_match('/branch_id=(\d+)/',$sql,$b);preg_match('/prescription_item_id=(\d+)/',$sql,$i);foreach($this->tables['wp_ps_sig_instructions']??[] as $r){if($r['tenant_id']===$this->tenant($sql)&&$r['branch_id']===(int)$b[1]&&$r['prescription_item_id']===(int)$i[1])return $r;}}
			return null;
		}
		public function insert($table,$row){$row['id']=++$this->insert_id;$this->tables[$table][]=$row;return 1;}
		public function update($table,$values,$where){foreach($this->tables[$table] as &$r){$match=true;foreach($where as $k=>$v){if($r[$k]!=$v)$match=false;}if($match)$r=array_merge($r,$values);}return 1;}
	}
	$wpdb=new MemoryDB();
	require_once __DIR__.'/../wp-content/plugins/pharmasure-clinical/src/Sig/Engine.php';
	require_once __DIR__.'/../wp-content/plugins/pharmasure-clinical/src/Sig/Service.php';
	require_once __DIR__.'/../wp-content/plugins/pharmasure-clinical/src/Rest/ClinicalAccess.php';
	require_once __DIR__.'/../wp-content/plugins/pharmasure-clinical/src/Sig/Controller.php';
	$pass=0;$fail=0;
	$assert=static function($condition,$message)use(&$pass,&$fail){echo($condition?'PASS: ':'FAIL: ').$message."\n";$condition?++$pass:++$fail;};
	$reject=static function($fn,$message)use($assert){try{$fn();$assert(false,$message);}catch(InvalidArgumentException $e){$assert(true,$message);}};
	$s=new \PharmaSure\Clinical\Sig\Service();$controller=new \PharmaSure\Clinical\Sig\Controller();
	$base=['structured'=>['dose'=>'2','unit'=>'tablet','route'=>'oral','frequency'=>'daily_3','duration_days'=>'5']];
	$reject(fn()=>$s->preview(13,1,$base),'SIG is disabled by default');
	$options['pharmasure_sig_settings_13']=['enabled'=>true,'personal_shortcuts'=>false];
	$r=$s->preview(13,1,$base);$assert($r['quantity']==='30.000'&&!$r['manual_reason'],'stock units align with structured tablet quantity');
	$reject(fn()=>$s->preview(99,1,$base),'cross-tenant drug is rejected');
	$liquid=$s->preview(13,2,['structured'=>['dose'=>'5','unit'=>'mL','route'=>'oral','frequency'=>'daily_2','duration_days'=>'7']]);$assert($liquid['quantity']==='70.000'&&str_contains($liquid['manual_reason'],'stock units'),'bottle conversion is never inferred from pack size');
	$s->seed(13,9);$s->seed(13,9);$assert(count($s->definitions(13))===16,'draft seeding is idempotent');
	$reject(fn()=>$s->preview(13,1,['parse_sig'=>true,'original_sig'=>'2 TDS X 5/7','structured'=>['route'=>'oral','unit'=>'tablet']]),'seeded candidates cannot be used before clinical approval');
	$definition=['code'=>'TDS','category'=>'frequency','value'=>'daily_3','aliases'=>['TID'],'status'=>'active','clinical_approval'=>true,'approval_note'=>'Pharmacist-reviewed local terminology.'];
	$reject(fn()=>$s->save_definition(13,$definition,9),'activation requires pharmacist permission');
	$caps['pharmasure_review_prescriptions']=true;$id=$s->save_definition(13,$definition,9);$assert($id['version']===2,'approval creates an immutable definition version');
	$parsed=$s->preview(13,1,['parse_sig'=>true,'original_sig'=>'2 TID X 5/7','structured'=>['route'=>'oral','unit'=>'tablet']]);$assert($parsed['quantity']==='30.000','approved aliases are parsed server-side');
	$reject(fn()=>$s->save_definition(13,array_replace($definition,['code'=>'OTHER']),9),'active alias collisions are rejected');
	$data=['original_sig'=>'2 TID X 5/7','original_prescription'=>'Take two tablets three times daily for five days.','verified_directions'=>$parsed['directions']];
	$reject(fn()=>$s->save_instruction(13,7,4,array_replace($data,['verified_directions'=>'Take one tablet.']),$parsed,9),'edited directions require reconciliation');
	$reject(fn()=>$s->save_instruction(13,7,4,array_replace($data,['original_prescription'=>'']),$parsed,9),'original prescription directions are mandatory');
	$reject(fn()=>$s->save_instruction(13,7,4,$data,$liquid,9),'manual quantity conversion requires a verification reason');
	$s->save_instruction(13,7,4,$data,$parsed,9);$saved=$s->for_item(13,7,4);$assert($saved['structured']['dose_exact']===['numerator'=>2,'denominator'=>1],'exact structured dosage is stored alongside original and patient text');
	$assert($s->for_item(13,8,4)===null&&$s->for_item(99,7,4)===null,'instruction reads enforce tenant and branch scopes');
	$caps['pharmasure_review_prescriptions']=false;$reject(fn()=>$s->verify(13,7,1,9),'verification requires pharmacist permission');
	$caps['pharmasure_review_prescriptions']=true;$reject(fn()=>$s->verify(13,7,1,10),'verification identity cannot be supplied for another user');
	$s->save_definition(13,array_replace($definition,['status'=>'disabled']),9);
	$reject(fn()=>$s->preview(13,1,['structured'=>$saved['structured']]),'disabled referenced versions cannot be reused by templates');
	$assert($s->for_item(13,7,4)['verified_directions']===$saved['verified_directions'],'terminology changes preserve historical directions');
	$caps=[];$assert(is_wp_error($controller->manage()),'configuration routes deny unauthorized roles');
	$caps['pharmasure_manage_sig']=true;$assert($controller->manage()===true,'configuration capability permits management within tenant entitlement');
	$enable=$controller->settings(new Request(['enabled'=>true,'clinical_acceptance'=>true,'acceptance_note'=>'accepted']));$assert(is_wp_error($enable),'configuration role alone cannot attest clinical acceptance');
	$caps['pharmasure_review_prescriptions']=true;$enable=$controller->settings(new Request(['enabled'=>true,'clinical_acceptance'=>true,'acceptance_note'=>'Clinical UAT reference SIG-1','tenant_id'=>99]));$assert(!is_wp_error($enable)&&!isset($options['pharmasure_sig_settings_99']),'settings derive tenant scope server-side');
	\PharmaSure\Clinical\Sig\Controller::register_routes();$assert(count($routes)===10,'all SIG API routes register explicit permission callbacks');
	$assert(count(array_filter($audit,fn($e)=>str_starts_with($e['action'],'clinical.sig_')))>0,'definition and configuration mutations emit existing audit events');
	require_once __DIR__.'/../wp-content/plugins/pharmasure-core/src/Plugin.php';
	\PharmaSure\Core\Plugin::register_capabilities();
	$caps=$roles['pharmasure_pharmacist']->capabilities;
	$assert($controller->manage()===true && current_user_can('pharmasure_review_prescriptions'),'registered pharmacist can open SIG management and perform clinical review');
	$approved=$controller->definitions(new Request(array_replace($definition,['code'=>'FREQ3','aliases'=>[]])));
	$assert(!is_wp_error($approved),'registered pharmacist can activate an explicitly reviewed terminology version');
	$reject(fn()=>$s->save_definition(13,array_replace($definition,['code'=>'FREQ4','aliases'=>[],'clinical_approval'=>false]),9),'pharmacist role does not bypass explicit clinical attestation');
	foreach(['pharmasure_cashier','pharmasure_inventory_clerk','pharmasure_auditor'] as $role){$caps=$roles[$role]->capabilities;$assert(is_wp_error($controller->manage()),$role.' cannot manage or approve SIG terminology');}
	echo "\n{$pass} passed; {$fail} failed\n";exit($fail?1:0);
}
