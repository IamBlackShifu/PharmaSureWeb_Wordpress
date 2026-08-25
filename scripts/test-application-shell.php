<?php
/** Application shell regression test. Run with wp eval-file scripts/test-application-shell.php. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
use PharmaSure\Core\Admin\ApplicationShell;
$pass=0;$fail=0;$assert=static function($ok,$message)use(&$pass,&$fail){echo($ok?'PASS: ':'FAIL: ').$message."\n";$ok?++$pass:++$fail;};
wp_set_current_user(1);$_GET['page']='pharmasure-core';
$assert(class_exists(ApplicationShell::class),'shared application shell class autoloads');
$assert(ApplicationShell::is_pharmasure_screen(),'PharmaSure admin pages activate the shared shell');
$assert(str_contains(ApplicationShell::body_class('wp-admin'),'pharmasure-application'),'shell adds a scoped admin body class');
ApplicationShell::assets();
$assert(wp_style_is('pharmasure-application-shell','enqueued'),'accessible shell stylesheet is enqueued');
$assert(wp_script_is('pharmasure-application-shell','enqueued'),'responsive enhancement script is enqueued');
$css=file_get_contents(PHARMASURE_CORE_PATH.'assets/application-shell.css');$js=file_get_contents(PHARMASURE_CORE_PATH.'assets/application-shell.js');
$assert(str_contains($css,':focus-visible')&&str_contains($css,'--ps-shell-focus'),'visible keyboard focus styling is present');
$assert(str_contains($css,'prefers-reduced-motion'),'reduced-motion preference is respected');
$assert(str_contains($css,'@media print'),'operational print styles are present');
$assert(str_contains($js,'data-label')&&str_contains($js,'ps-responsive-table'),'tables gain responsive accessible labels');
$assert(str_contains($js,'ps-announcer'),'notices are announced through the live region');
$_GET['page']='users';$assert(!ApplicationShell::is_pharmasure_screen(),'unrelated WordPress admin pages are not restyled');
echo"Application shell tests: $pass passed, $fail failed.\n";if($fail){throw new RuntimeException('Application shell tests failed.');}
