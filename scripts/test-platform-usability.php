<?php
/** Isolated behavior tests; uses actual services with an in-memory WordPress/database boundary. */
namespace PharmaSure\Core {
 final class TenantContext { public static function instance(){return new self();} public function get_tenant_id(){return 13;} public function get_branch_id(){return 7;} public function get_correlation_id(){return 'test';} }
}
namespace PharmaSure\Inventory\Services { final class StockAllocator { public function consume_fefo(...$args){return [['cost_amount_minor'=>200]];} } }
namespace PharmaSure\Clinical\Sig { final class Service {public function for_item(...$args){return null;} } }
namespace {
const ARRAY_A='ARRAY_A';
$caps=[];$options=[];$user_meta=[];$audit=[];
function current_user_can($cap){global $caps;return !empty($caps[$cap]);}
function get_current_user_id(){return 9;}
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function update_option($key,$value,$autoload=false){global $options;$options[$key]=$value;return true;}
function get_user_meta($user,$key,$single=true){global $user_meta;return $user_meta[$user][$key]??'';}
function update_user_meta($user,$key,$value){global $user_meta;$user_meta[$user][$key]=$value;return true;}
function sanitize_key($value){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',(string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function sanitize_email($value){return filter_var($value,FILTER_VALIDATE_EMAIL)?:'';}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000000001';}
function sanitize_textarea_field($value){return sanitize_text_field($value);}
function current_time($format,$gmt=false){return gmdate('Y-m-d H:i:s');}
function rest_ensure_response($value){return $value;}
function is_wp_error($value){return $value instanceof WP_Error;}
function do_action($name,$value){global $audit;$audit[]=$value;}
function wp_json_encode($value){return json_encode($value);}
function absint($value){return abs((int)$value);}
class WP_REST_Request {public function __construct(private array $data=[]){} public function get_param($key){return $this->data[$key]??null;}}
class WP_Error {public function __construct(public $code,public $message,public $data=[]){} public function get_error_code(){return $this->code;}}
class TestQuery {public function __construct(public string $sql,public array $args=[]){} }
class MemoryDB {
 public string $prefix='wp_',$last_error=''; public int $insert_id=100;
 public array $inserts=[],$updates=[],$queries=[];public string $mode='';public ?array $batch=null,$sale=null,$claim=null,$rx=null;public bool $open=true,$has_claim=false;public int $payment_id=0;
 public function prepare($sql,...$args){return new TestQuery($sql,count($args)===1&&is_array($args[0])?$args[0]:$args);}
 private function sql($query){return $query instanceof TestQuery?$query->sql:$query;}
 public function query($query){$this->queries[]=$query;return 1;}
 public function insert($table,$row){$this->insert_id++;$this->inserts[]=[$table,$row];if($this->mode==='clinical' && str_ends_with($table,'sales'))$this->sale=$row+['id'=>$this->insert_id];if(str_ends_with($table,'sale_payments'))$this->payment_id=$this->insert_id;return 1;}
 public function update($table,$row,$where){$this->updates[]=[$table,$row,$where];if(str_ends_with($table,'sales')&&$this->sale)$this->sale=array_replace($this->sale,$row);if(str_ends_with($table,'prescriptions')&&$this->rx)$this->rx=array_replace($this->rx,$row);if(str_ends_with($table,'claims')&&$this->claim)$this->claim=array_replace($this->claim,$row);return 1;}
 public function get_var($query){$sql=$this->sql($query);$this->queries[]=$query;
  if(str_starts_with($sql,'SHOW TABLES LIKE'))return $query->args[0];
  if(str_contains($sql,'SELECT currency'))return 'USD';
  if(str_contains($sql,'sale_payments'))return $this->payment_id;
  if(str_contains($sql,'SELECT id FROM wp_ps_claims'))return $this->has_claim?22:null;
  if(str_contains($sql,'COUNT('))return 2;
  return 1;
 }
 public function get_row($query,$format=null){$sql=$this->sql($query);$this->queries[]=$query;
  if(str_contains($sql,'till_sessions'))return $this->open?['id'=>5]:null;
  if(str_contains($sql,'FROM wp_ps_sales')){
   if($this->mode==='clinical'&&str_contains($sql,'idempotency_key'))return $this->sale;

   if($query->args[0]!==40||$query->args[1]!==13||$query->args[2]!==7)return null;
   return $this->sale;
  }
  if(str_contains($sql,'FROM wp_ps_prescriptions')&&$this->mode==='clinical')return $this->rx;
  if(str_contains($sql,'SELECT t.trading_name'))return ['trading_name'=>'Test','branch_name'=>'Main','currency'=>'USD'];
  if(str_contains($sql,'FROM wp_ps_drugs')&&$this->mode==='clinical')return ['id'=>2,'name'=>'Medicine','strength'=>'10mg','selling_price_minor'=>250,'is_controlled'=>0];
  if(str_contains($sql,'FROM wp_ps_claims'))return $this->claim;
  if(str_contains($sql,'FROM wp_ps_batches')&&$this->mode==='dispose')return $this->batch;
  return null;
 }
 public function get_results($query,$format=null){$sql=$this->sql($query);$this->queries[]=$query;if($this->mode==='clinical'&&str_contains($sql,'FROM wp_ps_prescription_items'))return [['id'=>21,'drug_id'=>2,'drug_name'=>'Medicine','quantity'=>2,'unit_price_minor'=>250,'is_controlled'=>0]];if($this->mode==='notifications'&&str_contains($sql,'prescription_id IS NOT NULL'))return [['id'=>40]];return [];}
}
$wpdb=new MemoryDB();
require_once __DIR__.'/../wp-content/plugins/pharmasure-core/src/AppLauncher.php';
require_once __DIR__.'/../wp-content/plugins/pharmasure-inventory/src/Services/InventoryService.php';
require_once __DIR__.'/../wp-content/plugins/pharmasure-pos/src/Services/PosService.php';
require_once __DIR__.'/../wp-content/plugins/pharmasure-clinical/src/Services/ClinicalWorkspaceService.php';
require_once __DIR__.'/../wp-content/plugins/pharmasure-claims/src/Services/ClaimsService.php';
$pass=0;$fail=0;$assert=function($ok,$message)use(&$pass,&$fail){echo ($ok?'PASS: ':'FAIL: ').$message."\n";$ok?$pass++:$fail++;};
use PharmaSure\Core\AppLauncher;
$caps=['pharmasure_view_inventory'=>true,'pharmasure_access_pos'=>true];$visible=AppLauncher::workspace_permissions();
$assert($visible['pos']&&!$visible['overview']&&!$visible['clinical']&&!$visible['account'],'cashier sees selling without clinical, administration or stock overview');
$caps=['pharmasure_view_inventory'=>true,'pharmasure_view_prescriptions'=>true,'pharmasure_review_prescriptions'=>true];$visible=AppLauncher::workspace_permissions();
$assert($visible['clinical']&&!$visible['pos']&&!$visible['account'],'pharmacist sees clinical work without cashier or user administration');
$assert(!is_wp_error(AppLauncher::save_text_size(new WP_REST_Request(['text_size'=>'extra-large'])))&&$user_meta[9]['pharmasure_text_size']==='extra-large','text size saves against the current user');
$assert(is_wp_error(AppLauncher::save_text_size(new WP_REST_Request(['text_size'=>'huge']))),'unsupported text sizes are rejected');
$assert(is_wp_error(AppLauncher::save_markup(new WP_REST_Request(['markup_percent'=>25]))),'ordinary staff cannot set pharmacy markup');
$caps['pharmasure_manage_tenant']=true;
$assert(!is_wp_error(AppLauncher::save_markup(new WP_REST_Request(['markup_percent'=>25,'tenant_id'=>99])))&&$options['pharmasure_receiving_markup_13']===25.0&&!isset($options['pharmasure_receiving_markup_99']),'markup uses authenticated pharmacy scope');
$assert(is_wp_error(AppLauncher::save_markup(new WP_REST_Request(['markup_percent'=>-1]))),'negative markup is rejected');
$assert(!is_wp_error(AppLauncher::save_provider(new WP_REST_Request(['ahfoz_provider_number'=>'AH-001']))),'AHFoZ identity saves in pharmacy settings');
$wpdb->mode='notifications';$wpdb->queries=[];$caps=['pharmasure_view_inventory'=>true,'pharmasure_access_pos'=>true];$notices=AppLauncher::notifications();
$assert(count($notices['items'])===1&&$notices['items'][0]['route']==='pos','cashier receives payment notices only');
$assert(!array_filter($wpdb->queries,fn($q)=>$q instanceof TestQuery&&str_contains($q->sql,'COUNT(*) FROM wp_ps_ba')),'cashier notifications never query stock management alerts');
$wpdb=new MemoryDB();$inventory=new \PharmaSure\Inventory\Services\InventoryService();
$line=['drug_id'=>2,'batch_number'=>'B1','quantity'=>3,'unit_cost_minor'=>199,'expiry_date'=>gmdate('Y-m-d',strtotime('+1 year'))];
$result=$inventory->receive_stock(13,7,['supplier_id'=>1,'items'=>[$line]],'test');
$rows=array_values(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'stock_receipt_lines')));
$assert(!is_wp_error($result)&&$rows[0][1]['selling_price_minor']===249,'receiving computes and rounds markup in minor currency units');
$assert((bool)array_filter($wpdb->updates,fn($x)=>str_ends_with($x[0],'drugs')&&$x[1]['selling_price_minor']===249),'receiving updates the catalogue price used by POS');
$wpdb->inserts=[];$line['selling_price_minor']=175;$result=$inventory->receive_stock(13,7,['supplier_id'=>1,'markup_percent'=>50,'items'=>[$line]],'test');
$rows=array_values(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'stock_receipt_lines')));$assert($rows[0][1]['selling_price_minor']===175,'explicit selling price overrides automatic markup');
$assert(is_wp_error($inventory->receive_stock(13,7,['supplier_id'=>1,'markup_percent'=>-3,'items'=>[$line]],'test')),'invalid request markup cannot bypass validation');
$wpdb->mode='dispose';$wpdb->batch=['id'=>3,'drug_id'=>2,'quantity_available'=>5,'unit_cost_minor'=>100,'status'=>'expired','expiry_date'=>'2020-01-01'];$wpdb->queries=[];$wpdb->inserts=[];
$result=$inventory->change_batch_status(13,7,3,['status'=>'disposed','reason'=>'Expired','disposal_method'=>'authorized_waste_contractor','disposal_reference'=>'DOC-12'],'test');
$assert(!is_wp_error($result)&&$result['available_delta']===0.0,'disposal of removed expired stock does not deduct saleable balance twice');
$rows=array_values(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'batch_dispositions')));$assert($rows[0][1]['quantity_affected']===5.0&&str_contains($rows[0][1]['reason'],'DOC-12'),'physical disposal quantity and handling reference are retained');
$assert((bool)array_filter($wpdb->updates,fn($x)=>str_ends_with($x[0],'batches')&&($x[1]['quantity_available']??null)===0),'disposed batch has zero remaining physical stock');
$assert(is_wp_error($inventory->change_batch_status(13,7,3,['status'=>'disposed','reason'=>'Expired'],'test')),'disposal requires method and handling evidence');
$wpdb->batch['status']='active';$wpdb->batch['expiry_date']=gmdate('Y-m-d',strtotime('+1 year'));
$assert(is_wp_error($inventory->change_batch_status(13,7,3,['status'=>'disposed','reason'=>'Expired','disposal_method'=>'other','disposal_reference'=>'D1'],'test')),'unexpired active stock cannot be labelled as expired disposal');
$wpdb=new MemoryDB();$wpdb->sale=['id'=>40,'prescription_id'=>11,'total_amount_minor'=>500,'status'=>'completed','till_session_id'=>null,'receipt_number'=>'D000001'];$pos=new \PharmaSure\POS\Services\PosService();
$result=$pos->charge_prescription(13,7,40,5,9,['payments'=>[['method'=>'cash','amount_minor'=>500]]]);
$assert(!is_wp_error($result)&&$wpdb->sale['till_session_id']===5,'cashier payment attaches the existing dispensing to their till');
$assert(count(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'sale_payments')))===1&&!array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'sale_items')),'charging writes payment without another sale or stock allocation');
$result=$pos->charge_prescription(13,7,40,5,10,['payments'=>[['method'=>'cash','amount_minor'=>500]]]);$assert(is_wp_error($result),'second cashier pickup cannot charge the same prescription twice');
$wpdb->payment_id=0;$wpdb->sale['till_session_id']=null;
$assert(is_wp_error($pos->charge_prescription(99,7,40,5,9,['payments'=>[['method'=>'cash','amount_minor'=>500]]])),'cross-pharmacy prescription payment is denied');
$assert(is_wp_error($pos->charge_prescription(13,7,40,5,9,['payments'=>[['method'=>'cash','amount_minor'=>499]]])),'cashier cannot change the recorded prescription total');
$wpdb->has_claim=true;$assert(is_wp_error($pos->charge_prescription(13,7,40,5,9,['payments'=>[['method'=>'cash','amount_minor'=>500]]])),'medical-aid claims cannot also be charged in full as cash prescriptions');
$wpdb->has_claim=false;$wpdb->open=false;$assert(is_wp_error($pos->charge_prescription(13,7,40,5,9,['payments'=>[['method'=>'cash','amount_minor'=>500]]])),'charging requires an open cashier till');
$wpdb=new MemoryDB();$wpdb->claim=['id'=>2,'status'=>'draft','ahfoz_provider_number'=>null];$claim_service=new \PharmaSure\Claims\Services\ClaimsService();
$assert(is_wp_error($claim_service->validate_claim(13,7,2,9)),'claim validation requires AHFoZ provider identity');
$assert(!is_wp_error($claim_service->set_provider_number(13,7,2,'AH-001',9))&&$wpdb->claim['ahfoz_provider_number']==='AH-001','draft claim provider snapshot can be corrected');
$wpdb->claim['status']='submitted';$assert(is_wp_error($claim_service->set_provider_number(13,7,2,'AH-002',9)),'submitted claim provider snapshots cannot be silently overwritten');
$clinical=new \PharmaSure\Clinical\Services\ClinicalWorkspaceService();
$assert(is_wp_error($clinical->dispense(13,7,11,['idempotency_key'=>'test'],9)),'verbal confirmation remains required for dispensing');
$wpdb=new MemoryDB();$wpdb->mode='clinical';$clinical=new \PharmaSure\Clinical\Services\ClinicalWorkspaceService();
$patient=$clinical->create_patient(13,7,['first_name'=>'Rudo','last_name'=>'Ncube'],9);
$patients=array_values(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'patients')));$assert(!is_wp_error($patient)&&$patients[0][1]['date_of_birth']===null&&$patients[0][1]['phone']===null,'names-only patient registration does not invent demographic data');
$rx=$clinical->create_prescription(13,7,['patient_id'=>1,'prescriber'=>'Dr Test','prescription_date'=>gmdate('Y-m-d'),'items'=>[['drug_id'=>2,'quantity'=>2,'dose'=>'1 tablet','frequency'=>'twice daily']]],9);
$orders=array_values(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'prescriptions')));$assert(!is_wp_error($rx)&&$orders[0][1]['ahfoz_provider_number']==='AH-001','prescription captures pharmacy provider identity');
$review=$clinical->create_prescription(13,7,['patient_id'=>1,'prescriber'=>'Dr Test','prescription_date'=>gmdate('Y-m-d'),'submit_for_review'=>true,'items'=>[['drug_id'=>2,'quantity'=>2,'dose'=>'1 tablet','frequency'=>'twice daily']]],9);$assert(!is_wp_error($review)&&$review['status']==='pending_review','intake can submit directly for pharmacist review without approving itself');
$wpdb->rx=['id'=>11,'patient_id'=>1,'status'=>'approved'];$wpdb->sale=null;
$dispense=$clinical->dispense(13,7,11,['idempotency_key'=>'verbal-1','counselling_provided'=>true],9);
$checks=array_values(array_filter($wpdb->inserts,fn($x)=>str_ends_with($x[0],'dispensing_checks')));$assert(!is_wp_error($dispense)&&$checks[0][1]['counselling_provided']===1&&str_contains($checks[0][1]['counselling_notes'],'verbally'),'verbal confirmation permits dispensing without written notes and retains evidence');
$inserts=count($wpdb->inserts);$replay=$clinical->dispense(13,7,11,['idempotency_key'=>'verbal-1','counselling_provided'=>true],9);$assert(!is_wp_error($replay)&&$replay['idempotent_replay']&&isset($replay['sale_id'])&&count($wpdb->inserts)===$inserts,'dispensing retry returns full handoff context without duplicate mutations');
$assert(is_wp_error($clinical->dispense(13,7,12,['idempotency_key'=>'verbal-1','counselling_provided'=>true],9)),'dispensing key cannot be reused for a different prescription');
$assert(is_wp_error($clinical->cancel(13,7,11,['reason'=>'Cancelled'],9)),'dispensed prescription cannot be cancelled as an undispensed order');
$wpdb->rx['status']='approved';$cancel=$clinical->cancel(13,7,11,['reason'=>'Patient declined','counselling_provided'=>true],9);$assert(!is_wp_error($cancel)&&$wpdb->rx['status']==='cancelled'&&$wpdb->rx['counselling_notes']==='Counselling given verbally.','cancellation retains optional verbal counselling evidence');
echo "\n{$pass} passed; {$fail} failed\n";exit($fail?1:0);
}