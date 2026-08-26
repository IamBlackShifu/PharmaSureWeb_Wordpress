<?php
/** Verify automatic owner branch context and browser-session independence. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\TenantContext;

$pass=0;$fail=0;$assert=static function($ok,$message)use(&$pass,&$fail){echo($ok?'PASS: ':'FAIL: ').$message."\n";$ok?++$pass:++$fail;};
$reset=static function(){ $property=new ReflectionProperty(TenantContext::class,'instance');$property->setValue(null,null); };
$owner=get_user_by('email','owner@greenlife.demo');$original_user=get_current_user_id();$original_cookie=$_COOKIE[LOGGED_IN_COOKIE]??null;$legacy=null;$preferences=null;
try {
	if(!$owner){throw new RuntimeException('GreenLife owner fixture is unavailable.');}
	wp_set_current_user((int)$owner->ID);$legacy=get_user_meta($owner->ID,'pharmasure_active_branch_id',true);$preferences=get_user_meta($owner->ID,'pharmasure_active_branches',true);delete_user_meta($owner->ID,'pharmasure_active_branch_id');delete_user_meta($owner->ID,'pharmasure_active_branches');
	global $wpdb;$p=$wpdb->prefix.'ps_';$tenant=(int)$wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM {$p}tenant_memberships WHERE user_id=%d AND role='owner' AND is_active=1 LIMIT 1",$owner->ID));$branches=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}branches WHERE tenant_id=%d AND is_active=1 ORDER BY is_default DESC,name,id",$tenant)));$assert(count($branches)>=2,'GreenLife exposes two active branches for simultaneous-browser verification');
	$expires=time()+3600;$_COOKIE[LOGGED_IN_COOKIE]=$owner->user_login.'|'.$expires.'|browser-a-token|test-hmac';$reset();$browser_a=TenantContext::instance();$assert((int)$browser_a->get_branch_id()===$branches[0],'owner receives the authorized default branch without a prompt');$assert($browser_a->set_branch($branches[1]),'Browser A can select the second owner-authorized branch');
	$_COOKIE[LOGGED_IN_COOKIE]=$owner->user_login.'|'.$expires.'|browser-b-token|test-hmac';$reset();$browser_b=TenantContext::instance();$assert((int)$browser_b->get_branch_id()===$branches[0],'new Browser B starts independently in the default branch');
	$_COOKIE[LOGGED_IN_COOKIE]=$owner->user_login.'|'.$expires.'|browser-a-token|test-hmac';$reset();$browser_a_again=TenantContext::instance();$assert((int)$browser_a_again->get_branch_id()===$branches[1],'Browser A retains its second-branch selection independently');
	$_COOKIE[LOGGED_IN_COOKIE]=$owner->user_login.'|'.$expires.'|browser-b-token|test-hmac';$reset();$browser_b_again=TenantContext::instance();$assert((int)$browser_b_again->get_branch_id()===$branches[0],'Browser B remains on its own default-branch selection');
	$map=get_user_meta($owner->ID,'pharmasure_active_branches',true);$assert(is_array($map)&&count($map)===2,'branch preferences are stored as two session-and-tenant scoped entries');
}catch(Throwable $error){++$fail;echo'FAIL: unexpected exception: '.$error->getMessage()."\n";}
finally{if(null===$original_cookie){unset($_COOKIE[LOGGED_IN_COOKIE]);}else{$_COOKIE[LOGGED_IN_COOKIE]=$original_cookie;}if(''===$legacy){delete_user_meta($owner->ID,'pharmasure_active_branch_id');}else{update_user_meta($owner->ID,'pharmasure_active_branch_id',$legacy);}if(!is_array($preferences)||!$preferences){delete_user_meta($owner->ID,'pharmasure_active_branches');}else{update_user_meta($owner->ID,'pharmasure_active_branches',$preferences);}wp_set_current_user($original_user);$reset();}
echo"Branch session-context tests: {$pass} passed, {$fail} failed.\n";if($fail){throw new RuntimeException('Branch session-context tests failed.');}
