<?php
/** Standalone session service tests using an in-memory WordPress database boundary. */
const ARRAY_A='ARRAY_A';
$actor=101;$caps=[];$events=[];
function get_current_user_id(){global$actor;return$actor;}
function current_user_can($cap){global$caps;return!empty($caps[$cap]);}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function current_time($kind,$gmt=false){return'2026-10-09 10:00:00';}
function do_action($name,$data){global$events;$events[]=$data;}
function is_wp_error($v){return$v instanceof WP_Error;}
class WP_Error{public function __construct(public$code,public$message,public$data=[]){} }
class SessionDB {
	public$prefix='wp_';public$users='wp_users';public$last_error='';public$insert_id=0;public$sessions=[];public$queries=[];private$snapshot=[];
	public$branches=[10=>1,11=>1,20=>2];public$tills=[1=>[1,10],2=>[1,10],3=>[1,11],4=>[2,20]];public$cash=[];public$refunds=[];
	public function prepare($sql,...$args){foreach($args as$arg)$sql=preg_replace('/%d/',(string)(int)$arg,$sql,1);return$sql;}
	public function query($sql){$this->queries[]=$sql;if($sql==='START TRANSACTION')$this->snapshot=$this->sessions;if($sql==='ROLLBACK')$this->sessions=$this->snapshot;return true;}
	private function match($row,$sql){preg_match_all('/(?:s\.)?(id|tenant_id|branch_id|till_id|cashier_id)=(\d+)/',$sql,$m,PREG_SET_ORDER);foreach($m as$v){if(isset($row[$v[1]])&&(int)$row[$v[1]]!==(int)$v[2])return false;}if(str_contains($sql,"status='open'")&&($row['status']??'')!=='open')return false;return true;}
	public function get_var($sql){$this->queries[]=$sql;
		if(str_contains($sql,'ps_branches')){foreach($this->branches as$id=>$tenant){if($this->match(['id'=>$id,'tenant_id'=>$tenant],$sql))return$id;}return null;}
		if(str_contains($sql,'ps_tills')){foreach($this->tills as$id=>$t){if($this->match(['id'=>$id,'tenant_id'=>$t[0],'branch_id'=>$t[1]],$sql))return$id;}return null;}
		if(str_contains($sql,'ps_till_sessions')){foreach($this->sessions as$r){if($this->match($r,$sql))return$r['id'];}return null;}
		preg_match('/till_session_id=(\d+)/',$sql,$m);$id=(int)($m[1]??0);
		if(str_contains($sql,'sale_payments'))return$this->cash[$id]??0;
		if(str_contains($sql,'refund_payments'))return$this->refunds[$id]??0;
		return 0;
	}
	public function get_row($sql,$mode=null){$this->queries[]=$sql;foreach($this->sessions as$r)if($this->match($r,$sql))return$r;return null;}
	public function get_results($sql,$mode=null){$this->queries[]=$sql;$rows=[];foreach($this->sessions as$r)if($this->match($r,$sql))$rows[]=array_merge(array_intersect_key($r,array_flip(['id','till_id','cashier_id','opened_at'])),['till_name'=>'Till '.$r['till_id'],'till_code'=>'T'.$r['till_id'],'cashier_name'=>'Cashier '.$r['cashier_id']]);return$rows;}
	public function insert($table,$r){$r['id']=++$this->insert_id;$this->sessions[$r['id']]=$r;return 1;}
	public function update($table,$values,$where){foreach($this->sessions as$id=>$r){$match=true;foreach($where as$k=>$v)if($r[$k]!=$v)$match=false;if($match){$this->sessions[$id]=array_merge($r,$values);return 1;}}return 0;}
}
$wpdb=new SessionDB();require_once __DIR__.'/../wp-content/plugins/pharmasure-pos/src/Services/PosService.php';
$service=new \PharmaSure\POS\Services\PosService();$pass=0;$fail=0;
$assert=static function($yes,$message)use(&$pass,&$fail){echo($yes?'PASS: ':'FAIL: ').$message."\n";$yes?++$pass:++$fail;};
$a=$service->open_session(1,10,1,101,1000);$assert(!is_wp_error($a),'cashier A opens their branch till');
$b=$service->open_session(1,10,2,102,500);$assert(!is_wp_error($b)&&$b['id']!==$a['id'],'cashier B opens a separate till concurrently in the same branch');
$duplicate=$service->open_session(1,10,1,103,0);$assert(is_wp_error($duplicate)&&$duplicate->code==='till_already_open','an occupied till cannot mix cashiers or floats');
$wpdb->tills[5]=[1,10];$duplicate=$service->open_session(1,10,5,101,0);$assert(is_wp_error($duplicate)&&$duplicate->code==='cashier_session_open','another login cannot create a second branch session for the same cashier');
$assert(count($wpdb->sessions)===2,'failed opens leave no extra sessions');
$branchLock=array_search('SELECT id FROM wp_ps_branches WHERE id=10 AND tenant_id=1 AND is_active=1 FOR UPDATE',$wpdb->queries,true);
$tillLock=array_search("SELECT id FROM wp_ps_tills WHERE id=1 AND tenant_id=1 AND branch_id=10 AND status='active' FOR UPDATE",$wpdb->queries,true);
$assert($branchLock!==false&&$tillLock!==false&&$branchLock<$tillLock,'branch row lock serializes simultaneous opens before till and cashier checks');
$list=$service->list_open_sessions(1,10,101);$assert(count($list)===2&&$list[0]['is_current_user']&&$list[0]['can_close']&&!$list[1]['can_close'],'cashier sees branch sessions but can close only their own');
$assert(!isset($list[1]['opening_float_minor']),'session discovery does not expose another cashier’s cash ledger');
$otherBranch=$service->open_session(1,11,3,101,0);$assert(!is_wp_error($otherBranch),'session ownership remains branch-specific');
$assert(count($service->list_open_sessions(1,10,101))===2,'other-branch session is not listed');
$assert(is_wp_error($service->list_open_sessions(2,10,101)),'cross-tenant branch discovery is rejected');
$forbidden=$service->close_session(1,10,$b['id'],101,500);$assert(is_wp_error($forbidden)&&$forbidden->code==='session_close_forbidden','cashier cannot close another cashier’s session');
$forged=$service->close_session(1,10,$b['id'],101,500,true,'Attempted override');$assert(is_wp_error($forged),'a forged manager flag cannot replace the actual capability');
$assert(is_wp_error($service->close_session(1,11,$a['id'],101,1000)),'closure cannot cross branch boundaries');
$assert(is_wp_error($service->close_session(2,10,$a['id'],101,1000)),'closure cannot cross tenant boundaries');
$wpdb->cash[$a['id']]=700;$wpdb->refunds[$a['id']]=200;
$closed=$service->close_session(1,10,$a['id'],101,1500,false,'Closing old login session');$assert(!is_wp_error($closed)&&$closed['expected_cash_minor']===1500&&$closed['variance_minor']===0,'same cashier can close from another login with accurate cash reconciliation');
$assert($wpdb->sessions[$b['id']]['status']==='open','closing one session leaves the concurrent cashier open');
$assert($wpdb->sessions[$a['id']]['closed_by']===101,'closing identity is recorded');
$assert(is_wp_error($service->close_session(1,10,$a['id'],101,1500)),'closed sessions cannot be closed twice');
$new=$service->open_session(1,10,1,101,100);$assert(!is_wp_error($new)&&$new['id']!==$a['id'],'cashier opens a fresh session after closing the old ledger');
$actor=900;$caps['pharmasure_manage_pos_sessions']=true;
$noReason=$service->close_session(1,10,$b['id'],900,500,true,'');$assert(is_wp_error($noReason)&&$noReason->code==='closing_reason_required','manager closure requires a reason');
$managerClose=$service->close_session(1,10,$b['id'],900,400,true,'Cashier shift ended; counted with supervisor.');$assert(!is_wp_error($managerClose)&&$managerClose['variance_minor']===-100&&$managerClose['variance_status']==='pending','manager closure preserves variance approval workflow');
$assert($wpdb->sessions[$b['id']]['cashier_id']===102&&$wpdb->sessions[$b['id']]['closed_by']===900,'manager closure keeps cashier attribution separate from closing actor');
$last=end($events);$assert($last['actor_id']===900&&$last['details']['session_cashier_id']===102&&$last['details']['manager_closure'],'audit records manager, cashier and closure rationale');
$assert(is_wp_error($service->open_session(1,10,4,101,0)),'foreign tenant till cannot be opened');
$assert(is_wp_error($service->open_session(1,10,1,101,-1)),'negative opening float is rejected');
$assert(is_wp_error($service->close_session(1,10,$new['id'],101,-1)),'negative cash count is rejected');
echo "\n{$pass} passed; {$fail} failed\n";exit($fail?1:0);
