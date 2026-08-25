<?php
/** Runtime schema readiness checks. Run with wp eval-file scripts/test-runtime-schema.php. */
if(!defined('ABSPATH')){exit(1);}global $wpdb;$p=$wpdb->prefix.'ps_';$pass=0;$fail=0;$assert=static function($ok,$message)use(&$pass,&$fail){echo($ok?'PASS: ':'FAIL: ').$message."\n";$ok?++$pass:++$fail;};
$assert(is_multisite(),'WordPress multisite is enabled');
foreach(array('pharmasure-core','pharmasure-pos','pharmasure-claims','pharmasure-reporting','pharmasure-integrations','pharmasure-offline')as$plugin){$file=$plugin.'/'.$plugin.'.php';$assert(is_plugin_active_for_network($file),$plugin.' is network active');}
$assert($p.'rate_limits'===$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.'rate_limits')),'rate-limit table exists');
$columns=$wpdb->get_col("DESCRIBE {$p}sales",0);foreach(array('till_session_id','receipt_number','idempotency_key','subtotal_amount_minor','discount_amount_minor','tax_amount_minor','cashier_id')as$column){$assert(in_array($column,$columns,true),'sales column '.$column.' exists');}
$indexes=$wpdb->get_col("SHOW INDEX FROM {$p}sales",2);foreach(array('prescription_sale','tenant_idempotency','branch_receipt','tenant_branch','tenant_patient','session_sales')as$index){$assert(in_array($index,$indexes,true),'sales index '.$index.' exists');}
$assert('7'===(string)get_site_option('pharmasure_pos_db_version'),'POS schema version 7 is recorded');
$migration=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$p}migrations WHERE migration_name=%s",'2026_08_24_000000_create_rate_limits'),ARRAY_A);$assert('completed'===($migration['status']??''),'rate-limit migration is recorded as completed');
echo"Runtime schema tests: $pass passed, $fail failed.\n";if($fail){throw new RuntimeException('Runtime schema tests failed.');}
