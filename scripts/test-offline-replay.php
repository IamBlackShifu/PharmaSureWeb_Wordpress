<?php
/** Authoritative offline replay test. Run with wp eval-file scripts/test-offline-replay.php. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
require_once ABSPATH . 'wp-admin/includes/user.php';
\PharmaSure\Inventory\Installer::install(); \PharmaSure\Offline\Installer::install(); \PharmaSure\Core\Plugin::register_capabilities();
global $wpdb; $p=$wpdb->prefix.'ps_'; $ids=array(); $pass=0; $fail=0;
$assert=static function($ok,$msg)use(&$pass,&$fail){echo($ok?'PASS: ':'FAIL: ').$msg."\n";$ok?++$pass:++$fail;};
try {
	$token='replay-'.wp_generate_uuid4();
	$ids['user']=wp_insert_user(array('user_login'=>$token,'user_pass'=>wp_generate_password(24),'user_email'=>"$token@example.test",'role'=>'administrator'));
	if(is_wp_error($ids['user'])){throw new RuntimeException($ids['user']->get_error_message());}
	$wpdb->insert($p.'tenants',array('name'=>'Replay Test','slug'=>$token,'primary_contact_email'=>"$token@example.test",'status'=>'active')); $ids['tenant']=(int)$wpdb->insert_id;
	$plan=(int)$wpdb->get_var("SELECT id FROM {$p}plans WHERE is_active=1 ORDER BY id LIMIT 1");$wpdb->insert($p.'licences',array('tenant_id'=>$ids['tenant'],'plan_id'=>$plan,'status'=>'active','licence_key'=>'REPLAY-'.wp_generate_password(12,false,false),'activated_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',strtotime('+1 day'))));$ids['licence']=(int)$wpdb->insert_id;$wpdb->insert($p.'licence_entitlements',array('licence_id'=>$ids['licence'],'entitlement_key'=>'offline','is_active'=>1));$wpdb->insert($p.'licence_quotas',array('licence_id'=>$ids['licence'],'quota_key'=>'offline_devices','limit_value'=>2));$wpdb->insert($p.'licence_quotas',array('licence_id'=>$ids['licence'],'quota_key'=>'offline_mutations_monthly','limit_value'=>10));
	$wpdb->insert($p.'branches',array('tenant_id'=>$ids['tenant'],'name'=>'Main','code'=>'MAIN','is_active'=>1)); $ids['branch']=(int)$wpdb->insert_id;
	$wpdb->insert($p.'tenant_memberships',array('user_id'=>$ids['user'],'tenant_id'=>$ids['tenant'],'role'=>'admin','is_admin'=>1,'is_active'=>1)); $ids['membership']=(int)$wpdb->insert_id;
	$now=current_time('mysql',true);
	$wpdb->insert($p.'suppliers',array('tenant_id'=>$ids['tenant'],'name'=>'Replay Supplier','status'=>'active','created_at'=>$now,'updated_at'=>$now)); $ids['supplier']=(int)$wpdb->insert_id;
	$wpdb->insert($p.'drugs',array('tenant_id'=>$ids['tenant'],'sku'=>'OFF-1','name'=>'Offline Medicine','status'=>'active','created_at'=>$now,'updated_at'=>$now)); $ids['drug']=(int)$wpdb->insert_id;
	$devices=new \PharmaSure\Offline\Services\DeviceService(); $created=$devices->register($ids['tenant'],$ids['branch'],$ids['user'],array('device_name'=>'Replay Device')); $ids['device']=(int)($created['id']??0);
	$assert($ids['device']>0,'replay device is registered');
	$device=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}offline_devices WHERE id=%d",$ids['device']),ARRAY_A);
	$payload=array('supplier_id'=>$ids['supplier'],'received_date'=>gmdate('Y-m-d'),'purchase_reference'=>'OFFLINE-GRN-1','items'=>array(array('drug_id'=>$ids['drug'],'batch_number'=>'OFF-B1','quantity'=>3,'unit_cost_minor'=>100,'selling_price_minor'=>150,'expiry_date'=>gmdate('Y-m-d',strtotime('+1 year')))));
	$replayer=new \PharmaSure\Offline\Services\ReplayService(); $snapshot=$replayer->snapshot($device,'stock.receive',$payload);
	$assert(!empty($snapshot['base_version']),'device receives an authoritative base version');
	$mutations=new \PharmaSure\Offline\Services\MutationService(); $queued=$mutations->receive($device,array('client_mutation_id'=>'receive-1','mutation_type'=>'stock.receive','base_version'=>$snapshot['base_version'],'payload'=>$payload)); $ids['mutation']=(int)($queued['id']??0);
	$applied=$replayer->replay($ids['mutation']);
	$assert('applied'===($applied['status']??'')&&'stock_receipt'===($applied['server_reference_type']??''),'stock receipt replays through authoritative inventory service');
	$assert(3.0===(float)$wpdb->get_var($wpdb->prepare("SELECT quantity_available FROM {$p}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d",$ids['tenant'],$ids['branch'],$ids['drug'])),'authoritative replay posts stock exactly once');
	$assert(is_wp_error($replayer->replay($ids['mutation'])),'applied mutation cannot replay again');
	$payload['purchase_reference']='OFFLINE-GRN-2'; $snapshot2=$replayer->snapshot($device,'stock.receive',$payload); $queued2=$mutations->receive($device,array('client_mutation_id'=>'receive-2','mutation_type'=>'stock.receive','base_version'=>$snapshot2['base_version'],'payload'=>$payload));
	$wpdb->update($p.'drugs',array('updated_at'=>gmdate('Y-m-d H:i:s',time()+1)),array('id'=>$ids['drug'])); $conflict=$replayer->replay((int)$queued2['id']);
	$assert('base_version_changed'===($conflict['conflict_code']??''),'changed server state produces a conflict');
	$assert(is_wp_error($replayer->snapshot($device,'payment.capture',array('amount_minor'=>100))),'offline payment remains provider-gated');
	$wpdb->update($p.'tenant_memberships',array('is_active'=>0),array('id'=>$ids['membership'])); $snapshot3=$replayer->snapshot($device,'stock.receive',$payload); $queued3=$mutations->receive($device,array('client_mutation_id'=>'receive-3','mutation_type'=>'stock.receive','base_version'=>$snapshot3['base_version'],'payload'=>$payload)); $denied=$replayer->replay((int)$queued3['id']);
	$assert('device_or_membership_inactive'===($denied['conflict_code']??''),'replay revalidates membership');
} catch(Throwable $e){++$fail;echo'FAIL: unexpected exception: '.$e->getMessage()."\n";}
finally {if(!empty($ids['tenant'])){$wpdb->query($wpdb->prepare("DELETE l FROM {$p}stock_receipt_lines l JOIN {$p}stock_receipts r ON r.id=l.receipt_id WHERE r.tenant_id=%d",$ids['tenant']));foreach(array('audit_events','offline_mutations','offline_devices','stock_movements','stock_balances','batches','stock_receipts','suppliers','drugs','tenant_memberships','branches')as$t){$wpdb->delete($p.$t,array('tenant_id'=>$ids['tenant']));}if(!empty($ids['device'])){$wpdb->delete($p.'offline_nonces',array('device_id'=>$ids['device']));}if(!empty($ids['licence'])){$wpdb->delete($p.'licence_quotas',array('licence_id'=>$ids['licence']));$wpdb->delete($p.'licence_entitlements',array('licence_id'=>$ids['licence']));$wpdb->delete($p.'licences',array('id'=>$ids['licence'],'tenant_id'=>$ids['tenant']));}$wpdb->delete($p.'tenants',array('id'=>$ids['tenant']));}if(!empty($ids['user'])&&!is_wp_error($ids['user'])){wp_delete_user($ids['user']);}}
echo"Offline replay tests: $pass passed, $fail failed.\n";if($fail){throw new RuntimeException('Offline replay tests failed.');}
