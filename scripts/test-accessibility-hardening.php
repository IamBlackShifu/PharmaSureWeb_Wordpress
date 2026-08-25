<?php
/** Accessibility and REST hardening regression test. Run with wp eval-file scripts/test-accessibility-hardening.php. */
if(!defined('ABSPATH')){exit(1);}$pass=0;$fail=0;$assert=static function($ok,$message)use(&$pass,&$fail){echo($ok?'PASS: ':'FAIL: ').$message."\n";$ok?++$pass:++$fail;};
$core=PHARMASURE_CORE_PATH;$shell=file_get_contents($core.'assets/application-shell.js');$css=file_get_contents($core.'assets/application-shell.css');$pos=file_get_contents(WP_PLUGIN_DIR.'/pharmasure-pos/assets/pos.js');$pos_page=file_get_contents(WP_PLUGIN_DIR.'/pharmasure-pos/src/Admin/PosAdmin.php');$report=file_get_contents(WP_PLUGIN_DIR.'/pharmasure-reporting/src/Admin/ReportingAdmin.php');$offline=file_get_contents(WP_PLUGIN_DIR.'/pharmasure-offline/src/Admin/OfflineAdmin.php');
$assert(str_contains($css,':focus-visible'),'keyboard focus is visibly styled');
$assert(str_contains($css,'min-width:24px')&&str_contains($css,'min-height:40px'),'interactive targets meet the WCAG minimum target size');
$assert(str_contains($css,'prefers-reduced-motion'),'reduced-motion preferences are honored');
$assert(str_contains($shell,'control.labels')&&str_contains($shell,"setAttribute('aria-label'"),'unlabelled legacy controls receive accessible fallback names');
$assert(str_contains($shell,"setAttribute('scope','col')"),'data-table column headers receive explicit scope');
$assert(!str_contains($pos,'innerHTML'),'POS does not interpolate API data into HTML');
$assert(str_contains($pos,'textContent')&&str_contains($pos,'replaceChildren'),'POS renders dynamic content through safe DOM APIs');
$assert(str_contains($pos,'aria-label')&&str_contains($pos,"addEventListener('click'"),'POS dynamic controls have names and native keyboard activation');
$assert(str_contains($pos_page,'aria-live="assertive"')&&str_contains($pos_page,'aria-busy="false"'),'POS exposes errors and asynchronous state');
$assert(str_contains($report,'<caption class="screen-reader-text">')&&str_contains($report,'scope="col"'),'reports expose table context and header relationships');
$assert(str_contains($offline,'aria-current="page"')&&str_contains($offline,'aria-label="Offline queue filters"'),'offline operations expose active navigation and filter purpose');
$assert(class_exists(\PharmaSure\Core\Security\RateLimiter::class),'central REST rate limiter autoloads');
$assert(has_filter('rest_pre_dispatch',array(\PharmaSure\Core\Security\RateLimiter::class,'enforce'))!==false,'rate limiting is registered before REST dispatch');
$wpdb_event_start=(int)$GLOBALS['wpdb']->get_var("SELECT COALESCE(MAX(id),0) FROM {$GLOBALS['wpdb']->prefix}ps_security_events");$request=new WP_REST_Request('POST','/pharmasure/v1/test');$_SERVER['CONTENT_LENGTH']=2097153;$blocked=\PharmaSure\Core\Security\RateLimiter::enforce(null,rest_get_server(),$request);unset($_SERVER['CONTENT_LENGTH']);
$assert(is_wp_error($blocked)&&413===$blocked->get_error_data()['status'],'oversized PharmaSure REST writes are rejected');
$response=\PharmaSure\Core\Security\RateLimiter::headers(new WP_REST_Response(array()),rest_get_server(),new WP_REST_Request('GET','/pharmasure/v1/test'));
$assert('no-store, private'===$response->get_headers()['Cache-Control']&&'nosniff'===$response->get_headers()['X-Content-Type-Options'],'REST responses receive no-store and MIME-sniffing protections');
$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("DELETE FROM {$GLOBALS['wpdb']->prefix}ps_security_events WHERE id>%d AND event_type='api_request_too_large'",$wpdb_event_start));
echo"Accessibility and hardening tests: $pass passed, $fail failed.\n";if($fail){throw new RuntimeException('Accessibility and hardening tests failed.');}
