<?php
/** Run with wp eval-file on a disposable WordPress development database. */
if (!defined('ABSPATH')) { fwrite(STDERR,"Requires WordPress: wp eval-file scripts/test-smart-sig-integration.php\n"); exit(1); }
use PharmaSure\Clinical\Sig\Service;
use PharmaSure\Clinical\Services\ClinicalWorkspaceService;
global $wpdb;
\PharmaSure\Inventory\Installer::install();
\PharmaSure\POS\Installer::install();
\PharmaSure\Clinical\Installer::install();
$p=$wpdb->prefix.'ps_';$tenant=0;$test_user=0;$original_user=get_current_user_id();$pass=0;$fail=0;
$assert=static function($condition,$message)use(&$pass,&$fail){echo($condition?'PASS: ':'FAIL: ').$message."\n";$condition?++$pass:++$fail;};
try {
	foreach(['sig_definitions','sig_instructions','sig_templates']as$table){$assert($p.$table===$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table)),"{$table} migration creates table");}
	\PharmaSure\Clinical\Sig\Installer::install();$assert(get_option('pharmasure_sig_db_version')==='1','SIG migration is idempotent and site-local');
	$token='sig-test-'.wp_generate_uuid4();$now=current_time('mysql',true);
	$test_user=wp_create_user($token,wp_generate_password(40),$token.'@example.test');if(is_wp_error($test_user))throw new RuntimeException('Test user could not be created.');
	$user=new WP_User($test_user);$user->add_cap('pharmasure_review_prescriptions');wp_set_current_user($test_user);
	$wpdb->insert($p.'tenants',['name'=>'SIG integration test','trading_name'=>'SIG integration test','slug'=>$token,'primary_contact_email'=>$token.'@example.test','currency'=>'USD','status'=>'active']);$tenant=(int)$wpdb->insert_id;
	$wpdb->insert($p.'branches',['tenant_id'=>$tenant,'name'=>'SIG Test','code'=>'SIG','is_active'=>1]);$branch=(int)$wpdb->insert_id;
	$wpdb->insert($p.'drugs',['tenant_id'=>$tenant,'sku'=>$token,'name'=>'SIG Fixture Medicine','strength'=>'10mg','dosage_form'=>'tablet','unit_of_measure'=>'tablets','selling_price_minor'=>100,'requires_prescription'=>1,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);$drug=(int)$wpdb->insert_id;
	$wpdb->insert($p.'batches',['tenant_id'=>$tenant,'branch_id'=>$branch,'drug_id'=>$drug,'batch_number'=>'SIG-01','expiry_date'=>gmdate('Y-m-d',strtotime('+1 year')),'quantity_received'=>40,'quantity_available'=>40,'unit_cost_minor'=>50,'selling_price_minor'=>100,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
	$wpdb->insert($p.'stock_balances',['tenant_id'=>$tenant,'branch_id'=>$branch,'drug_id'=>$drug,'quantity_available'=>40,'updated_at'=>$now]);
	update_option('pharmasure_sig_settings_'.$tenant,['enabled'=>true,'personal_shortcuts'=>false],false);
	$sig=new Service();$clinical=new ClinicalWorkspaceService();$sig->seed($tenant,$test_user);
	$definition=['code'=>'TDS','category'=>'frequency','value'=>'daily_3','status'=>'active','clinical_approval'=>true,'approval_note'=>'Test terminology approval'];$sig->save_definition($tenant,$definition,$test_user);
	$patient=$clinical->create_patient($tenant,$branch,['first_name'=>'SIG','last_name'=>'Fixture'], $test_user);if(is_wp_error($patient))throw new RuntimeException($patient->get_error_message());
	$data=['patient_id'=>$patient['id'],'prescriber'=>'Dr Test','prescription_date'=>gmdate('Y-m-d'),'items'=>[['drug_id'=>$drug,'quantity'=>30,'sig'=>['original_sig'=>'2 TDS X 5/7','parse_sig'=>true,'original_prescription'=>'Take two tablets three times daily for five days.','structured'=>['unit'=>'tablet','route'=>'oral']]]]];
	$bad=$data;$bad['items'][0]['sig']['original_prescription']='';$invalid=$clinical->create_prescription($tenant,$branch,$bad,$test_user);
	$assert(is_wp_error($invalid)&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}prescriptions WHERE tenant_id=%d",$tenant))===0,'invalid SIG transaction rolls back prescription and item');
	$rx=$clinical->create_prescription($tenant,$branch,$data,$test_user);if(is_wp_error($rx))throw new RuntimeException($rx->get_error_message());
	$item=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}prescription_items WHERE tenant_id=%d AND prescription_id=%d",$tenant,$rx['id']));$saved=$sig->for_item($tenant,$branch,$item);
	$assert($saved['structured']['dose_exact']===['numerator'=>2,'denominator'=>1]&&$saved['calculation']['quantity']==='30.000','prescription line persists structured dose and calculated quantity');
	$assert((float)$wpdb->get_var($wpdb->prepare("SELECT quantity_available FROM {$p}stock_balances WHERE tenant_id=%d AND drug_id=%d",$tenant,$drug))===40.0,'SIG entry leaves inventory untouched');
	$clinical->submit($tenant,$branch,$rx['id'],$test_user);$review=['outcome'=>'approve','review_notes'=>'Original prescription, directions, dose and stock quantity verified.','allergies_checked'=>true,'interactions_checked'=>true,'dose_checked'=>true];
	$assert(is_wp_error($clinical->review($tenant,$branch,$rx['id'],$review,$test_user)),'approval requires explicit SIG comparison');
	$review['sig_verified']=true;$approved=$clinical->review($tenant,$branch,$rx['id'],$review,$test_user);$assert(!is_wp_error($approved)&&$approved['status']==='approved','pharmacist verification and approval commit together');
	$saved=$sig->for_item($tenant,$branch,$item);$assert((int)$saved['verified_by']===$test_user&&!empty($saved['verified_at']),'verification identity and UTC timestamp persist');
	$dispensed=$clinical->dispense($tenant,$branch,$rx['id'],['idempotency_key'=>wp_generate_uuid4(),'counselling_provided'=>true,'counselling_notes'=>'Fixture counselling documented.'],$test_user);$assert(!is_wp_error($dispensed),'verified SIG prescription uses existing dispensing service');
	$assert((float)$wpdb->get_var($wpdb->prepare("SELECT quantity_available FROM {$p}stock_balances WHERE tenant_id=%d AND drug_id=%d",$tenant,$drug))===10.0,'existing FEFO allocation deducts confirmed stock quantity exactly once');
	$sig->save_definition($tenant,array_replace($definition,['status'=>'disabled']),$test_user);$assert($sig->for_item($tenant,$branch,$item)['verified_directions']===$saved['verified_directions'],'disabling dictionary code preserves dispensed directions');
	if(class_exists('\PharmaSure\PrintModule\Services\PrintService')){
		$printer=new \PharmaSure\PrintModule\Services\PrintService();$job=$printer->create_job($tenant,'medication_label',$rx['id'],$test_user,'',$branch);
		$assert(!is_wp_error($job),'label job accepts verified SIG prescription');if(!is_wp_error($job)){$html=$printer->render_job($tenant,$job['id']);$assert(is_string($html)&&str_contains($html,'Take 2 tablets by mouth three times daily for 5 days.'),'label renders saved patient directions after dictionary changes');}
	}
}catch(Throwable$e){++$fail;echo 'FAIL: '.$e->getMessage()."\n";}
finally{
	wp_set_current_user($original_user);
	if($tenant){foreach(['print_jobs','sig_instructions','sig_templates','sig_definitions','dispensing_checks','controlled_dispense_register','sale_items','sales','stock_movements','stock_balances','batches','prescription_items','prescriptions','patients','drugs','document_sequences','audit_events','branches']as$table){if($p.$table===$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table)))$wpdb->delete($p.$table,['tenant_id'=>$tenant]);}$wpdb->delete($p.'tenants',['id'=>$tenant]);delete_option('pharmasure_sig_settings_'.$tenant);}
	if($test_user&&!is_wp_error($test_user)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($test_user);}
}
echo "{$pass} passed; {$fail} failed\n";if($fail)throw new RuntimeException('Smart SIG integration test failed.');
