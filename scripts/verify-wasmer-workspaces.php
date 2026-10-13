<?php
/** Production WordPress/controllers against live DB in a READ ONLY session. No test tenants or sales. */
ini_set('display_errors','0');
ob_start();
try {
 $app = dirname(__DIR__) . '/dist/wasmer-usability/app/';
 define('ABSPATH',$app);
 $platformMode=in_array('--platform',$argv,true);
 if($platformMode){define('WP_NETWORK_ADMIN',true);}
 $_SERVER['HTTP_HOST']='pharmasure.wasmer.app';
 $_SERVER['SERVER_NAME']='pharmasure.wasmer.app';
 $_SERVER['REQUEST_URI']=$platformMode?'/wp-admin/network/admin.php?page=pharmasure-admin':'/greenwoood-pharmacy/';
 $_SERVER['SERVER_PORT']=443; $_SERVER['HTTPS']='on'; $_SERVER['REQUEST_METHOD']='GET';
 $config=file_get_contents(dirname(__DIR__).'/deploy/wasmer/templates/wp-config.php');
 $config=substr($config,5,strpos($config,"require_once ABSPATH . 'wp-settings.php';")-5);
 eval($config);
 // Guard the connection immediately after core creates it, before plugins/hooks load.
 // Evaluate an instrumented bootstrap in memory; the release files remain unchanged.
 $bootstrap=file_get_contents(ABSPATH.'wp-settings.php');
 $guard= <<<'PHP'
require_once ABSPATH . WPINC . '/class-wpdb.php';
global $wpdb;
$wpdb = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
 public $read_errors = [];
 public function query($query) {
  $result=parent::query($query);
  if(false===$result && preg_match('/^\s*(SELECT|SHOW|DESCRIBE)\b/i',$query)){$this->read_errors[]=$this->last_error;}
  return $result;
 }
};
if(false===$wpdb->query('SET SESSION TRANSACTION READ ONLY')){throw new RuntimeException('read_only_session');}
require_wp_db();
PHP;
 $bootstrap=str_replace('require_wp_db();',$guard,$bootstrap);
 eval(substr($bootstrap,5));
 $readOnly=$wpdb->get_var('SELECT @@session.transaction_read_only');
 if((int)$readOnly!==1)throw new RuntimeException('read_only_guard');
 global $wpdb;
 if($platformMode){
  require_once ABSPATH.'wp-admin/includes/plugin.php';
  $admin=get_user_by('login',get_super_admins()[0]);if(!$admin)throw new RuntimeException('network_admin_account');
  wp_set_current_user($admin->ID);
  $reset=new ReflectionProperty(PharmaSure\Core\TenantContext::class,'instance');$reset->setAccessible(true);$reset->setValue(null,null);
  global $menu,$submenu,$_registered_pages,$_parent_pages,$_wp_real_parent_file;
  $menu=[];$submenu=[];$_registered_pages=[];$_parent_pages=[];$_wp_real_parent_file=[];
  do_action('network_admin_menu');
  $pages=['pharmasure-admin'=>[PharmaSure\Tenancy\Admin\TenantAdmin::class,'render_network_admin'],'pharmasure-tenants'=>[PharmaSure\Tenancy\Admin\TenantAdmin::class,'render_tenant_list'],'pharmasure-add-tenant'=>[PharmaSure\Tenancy\Admin\TenantAdmin::class,'render_add_tenant'],'pharmasure-licenses'=>[PharmaSure\Licensing\Admin\LicenseAdmin::class,'render_licenses']];
  $result=['mode'=>'read-only live network administration','super_admin'=>is_super_admin(),'site_id'=>get_current_blog_id(),'checks'=>[]];$failures=0;
  $directory=PharmaSure\Tenancy\Admin\TenantAdmin::platform_tenants();
  $result['tenant_scopes']=array_map(static fn($t)=>['site_id'=>(int)$t['site_id'],'tenant_id'=>(int)$t['id'],'slug'=>$t['slug'],'licence_status'=>$t['licence_status'],'expires_at'=>$t['licence']['expires_at']??null],$directory);
  $activeGreenwood=array_values(array_filter($directory,static fn($t)=>(int)$t['site_id']===3&&$t['slug']==='greenwoood-pharmacy'));
  $result['active_greenwood_licence_matches_workspace']=count($activeGreenwood)===1&&$activeGreenwood[0]['licence']['expires_at']==='2026-10-11 08:52:02'&&$activeGreenwood[0]['licence_status']==='grace';
  if(!$result['active_greenwood_licence_matches_workspace'])++$failures;
  $registered=array_column($submenu['pharmasure-admin']??[],2);
  $result['registered_pages']=$registered;
  foreach($pages as $slug=>$callback){
   $_GET['page']=$slug;$wpdb->read_errors=[];ob_start();call_user_func($callback);$html=ob_get_clean();
   $row=['page'=>$slug,'registered'=>in_array($slug,$registered,true),'has_platform_navigation'=>str_contains($html,'aria-label="Platform administration"'),'contains_network_links'=>str_contains($html,'/wp-admin/network/admin.php?page='),'read_errors'=>array_values(array_unique($wpdb->read_errors))];
   if($slug==='pharmasure-licenses'){$row['shows_plan_and_grace']=str_contains($html,'Enterprise trial')&&str_contains($html,'grace')&&str_contains($html,'2026-10-18 08:52:02')&&str_contains($html,'/greenwoood-pharmacy');}
   if(!$row['registered']||!$row['has_platform_navigation']||!$row['contains_network_links']||$row['read_errors']||($slug==='pharmasure-licenses'&&!$row['shows_plan_and_grace']))++$failures;
   $result['checks'][]=$row;
   if($slug==='pharmasure-licenses'){file_put_contents(dirname(__DIR__).'/artifacts/platform-licensing-preview.html','<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../wp-content/plugins/pharmasure-tenancy/assets/css/platform-admin.css"><body class="wp-admin"><div id="wpcontent" style="padding:20px">'.$html.'</div></body></html>');}
  }
  $_SERVER['REQUEST_URI']='/app/';$result['root_app_redirect']=PharmaSure\Core\AppLauncher::platform_entry_url();if(!str_contains($result['root_app_redirect']??'','/wp-admin/network/admin.php?page=pharmasure-admin'))++$failures;
  $result['super_admin_login_redirect']=PharmaSure\Core\Admin\WhiteLabel::platform_login_redirect('https://pharmasure.wasmer.app/app/','https://pharmasure.wasmer.app/app/',$admin);
  $tenantUrl='https://pharmasure.wasmer.app/greenwoood-pharmacy/app/';
  $result['explicit_tenant_login_preserved']=PharmaSure\Core\Admin\WhiteLabel::platform_login_redirect($tenantUrl,$tenantUrl,$admin)===$tenantUrl;
  switch_to_blog(3);$_SERVER['REQUEST_URI']='/greenwoood-pharmacy/app/';PharmaSure\Core\AppLauncher::bootstrap_context();
  $result['explicit_tenant_scope_preserved']=get_current_blog_id()===3&&PharmaSure\Core\AppLauncher::platform_entry_url()===null;restore_current_blog();
  if(!$result['explicit_tenant_login_preserved']||!$result['explicit_tenant_scope_preserved'])++$failures;
  $_SERVER['REQUEST_URI']='/app/';
  $owner=(int)$wpdb->get_var("SELECT user_id FROM {$wpdb->base_prefix}ps_tenant_memberships WHERE role='owner' AND is_active=1 LIMIT 1");wp_set_current_user($owner);
  $result['tenant_owner_has_platform_entry']=PharmaSure\Core\AppLauncher::platform_entry_url()!==null;if($result['tenant_owner_has_platform_entry'])++$failures;
  add_filter('wp_die_handler',static function(){return static function($message,$title='',$args=[]){throw new RuntimeException('platform_denied');};});
  try{PharmaSure\Licensing\Admin\LicenseAdmin::render_licenses();$result['tenant_owner_licensing_denied']=false;++$failures;}catch(RuntimeException $e){$result['tenant_owner_licensing_denied']=$e->getMessage()==='platform_denied';if(!$result['tenant_owner_licensing_denied'])++$failures;}
  $result['failures']=$failures;ob_end_clean();echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($failures?1:0);
 }
 $p=$wpdb->prefix.'ps_';
 $tenant=$wpdb->get_row("SELECT t.id,t.trading_name,m.site_id FROM {$p}tenants t JOIN {$p}tenant_site_mapping m ON m.tenant_id=t.id WHERE t.slug='greenwoood-pharmacy'",ARRAY_A);
 if(!$tenant)throw new RuntimeException('greenwood_mapping');
 $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$p}tenant_memberships WHERE tenant_id=%d AND role='owner' AND is_active=1 LIMIT 1",$tenant['id']));
 if(!$owner)throw new RuntimeException('greenwood_owner');
 wp_set_current_user($owner);
 $reset=new ReflectionProperty(PharmaSure\Core\TenantContext::class,'instance');$reset->setAccessible(true);$reset->setValue(null,null);
 $context=PharmaSure\Core\TenantContext::instance();
 $manager=new PharmaSure\Core\LicenseManager((int)$tenant['id']);
 $licence=$manager->validate_license();
 // The rate limiter persists counters, which a READ ONLY connection deliberately rejects.
 // Skip only that persistence filter in this local diagnostic; route role/licence/scope checks remain.
 remove_filter('rest_pre_dispatch',[PharmaSure\Core\Security\RateLimiter::class,'enforce'],10);
 $result=['mode'=>'live database; read-only session; existing Greenwood owner permissions; rate-counter writes excluded','site_id'=>get_current_blog_id(),'tenant_id'=>$context->get_tenant_id(),'branch_id'=>$context->get_branch_id(),'pharmacy'=>$tenant['trading_name'],'licence'=>$manager->summary(),'workspaces'=>[]];
 $configMethod=new ReflectionMethod(PharmaSure\Core\AppLauncher::class,'config');$configMethod->setAccessible(true);$workspaceConfig=$configMethod->invoke(null,$context);
 $result['api_url']=$workspaceConfig['apiUrl'];
 $failures=0;
 $result['boot_read_errors']=array_values(array_unique($wpdb->read_errors));
 $wpdb->read_errors=[];
 foreach(['/app/overview','/inventory/workspace','/pos/workspace','/clinical/workspace','/claims/workspace','/reports/workspace','/offline/workspace','/accounts/workspace','/app/notifications','/license'] as $route){
  $request=new WP_REST_Request('GET','/pharmasure/v1'.$route);
  if($route==='/inventory/workspace')$request->set_param('view','catalogue');
  $wpdb->read_errors=[];
  $response=rest_do_request($request);$data=$response->get_data();$status=$response->get_status();
  $row=['route'=>$route,'status'=>$status];
  if($status>=400){$row['code']=$data['code']??'error';$row['message']=$data['message']??'';++$failures;}
  else{$row['response_fields']=is_array($data)?array_keys($data):[];}
  if($wpdb->read_errors){$row['database_errors']=array_values(array_unique($wpdb->read_errors));++$failures;}
  $result['workspaces'][]=$row;
 }
 $result['licence_entitlements']=is_wp_error($licence)?[]:$licence['entitlements'];
 $result['provider_columns']=[];
 foreach(['prescriptions','claims'] as $table){$column=$wpdb->get_var("SHOW COLUMNS FROM {$p}{$table} LIKE 'ahfoz_provider_number'");$result['provider_columns'][$table]=!!$column;if(!$column)++$failures;}
 $activeBranch=$context->get_branch_id();
 $result['unauthorized_branch_selection_rejected']=!$context->set_branch(999999);
 $request=new WP_REST_Request('GET','/pharmasure/v1/pos/workspace');$request->set_param('branch_id',999999);$request->set_param('tenant_id',999999);
 $response=rest_do_request($request);
 $result['posted_scope_override_ignored']=$response->get_status()===200 && $context->get_branch_id()===$activeBranch && (int)$context->get_tenant_id()===(int)$tenant['id'];
 if(!$result['unauthorized_branch_selection_rejected']||!$result['posted_scope_override_ignored'])++$failures;
 $result['role_checks']=[];
 $staff=$wpdb->get_results($wpdb->prepare("SELECT MIN(user_id) AS user_id,role FROM {$p}tenant_memberships WHERE tenant_id=%d AND is_active=1 AND role<>'owner' GROUP BY role",$tenant['id']),ARRAY_A);
 $moduleRoutes=['overview'=>'/app/overview','pos'=>'/pos/workspace','inventory'=>'/inventory/workspace','clinical'=>'/clinical/workspace','claims'=>'/claims/workspace','reports'=>'/reports/workspace','offline'=>'/offline/workspace','account'=>'/accounts/workspace'];
 foreach($staff as $member){
  wp_set_current_user((int)$member['user_id']);$reset->setValue(null,null);$staffContext=PharmaSure\Core\TenantContext::instance();$permissions=PharmaSure\Core\AppLauncher::workspace_permissions();
  foreach($moduleRoutes as $module=>$route){
   $request=new WP_REST_Request('GET','/pharmasure/v1'.$route);if($module==='inventory')$request->set_param('view','catalogue');
   $response=rest_do_request($request);$expected=!empty($permissions[$module])?200:403;
   $result['role_checks'][]=['role'=>$member['role'],'route'=>$route,'status'=>$response->get_status(),'expected'=>$expected];
   if($response->get_status()!==$expected)++$failures;
  }
 }
 wp_set_current_user(0);$reset->setValue(null,null);
 $result['anonymous_denials']=[];
 foreach(['/pos/workspace','/clinical/workspace','/accounts/workspace','/license','/tenants'] as $route){$response=rest_do_request(new WP_REST_Request('GET','/pharmasure/v1'.$route));$result['anonymous_denials'][$route]=$response->get_status();if(!in_array($response->get_status(),[401,403],true))++$failures;}
 $result['failures']=$failures;
 ob_end_clean();echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
 exit($failures?1:0);
}catch(Throwable $error){ob_end_clean();echo json_encode(['verification'=>'failed','stage'=>$error->getMessage(),'type'=>get_class($error)])."\n";exit(1);}
