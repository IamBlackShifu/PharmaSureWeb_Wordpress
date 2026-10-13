<?php
/** Standalone deterministic SIG tests: php scripts/test-smart-sig.php */
require_once __DIR__ . '/../wp-content/plugins/pharmasure-clinical/src/Sig/Engine.php';
use PharmaSure\Clinical\Sig\Engine;
$engine = new Engine(); $pass = 0; $fail = 0;
$assert = static function( $condition, $message ) use (&$pass, &$fail) { echo ($condition ? 'PASS: ' : 'FAIL: ') . $message . "\n"; $condition ? ++$pass : ++$fail; };
$reject = static function( callable $fn, string $message ) use ($assert) { try { $fn(); $assert(false, $message); } catch (InvalidArgumentException $e) { $assert(true, $message); } };
$defs = [];
foreach (['BD' => ['daily_2',['BID']], 'TDS' => ['daily_3',['TID']], 'OD' => ['daily_1',[]], 'PRN' => ['prn',[]], 'STAT' => ['single',[]]] as $code => $v) { $defs[] = ['id'=>count($defs)+1,'version'=>1,'code'=>$code,'category'=>'frequency','value'=>$v[0],'aliases'=>$v[1],'status'=>'active','approval_status'=>'approved','ambiguous'=>$code==='OD']; }
$context = ['unit'=>'tablet','route'=>'oral','dosage_form'=>'tablet'];
$evaluate = static function($text, $ctx = null) use ($engine,$context,$defs) { $ctx = $ctx ?? $context; return $engine->evaluate($engine->parse($text,$ctx,$defs),Engine::profile($ctx['dosage_form'])); };
foreach (['2 TDS X 5/7'=>'30.000','1 BD X 5/7'=>'10.000','2 tds x 7/7'=>'42.000','1 BID X 5/7'=>'10.000','1/2 BD X 5/7'=>'5.000','1½ BD X 5/7'=>'15.000','0.1 TDS X 5/7'=>'1.500'] as $text=>$expected) { $r=$evaluate($text); $assert($r['quantity']===$expected,$text.' calculates '.$expected); }
$assert($evaluate('2 TDS X 5/7')['directions']==='Take 2 tablets by mouth three times daily for 5 days.','patient directions are clear and contextual');
foreach (['5ML BD X 7/7'=>'70.000','10ML TDS X 5/7'=>'150.000'] as $text=>$expected) { $assert($evaluate($text,['unit'=>'mL','route'=>'oral','dosage_form'=>'suspension'])['quantity']===$expected,'liquid calculation '.$text); }
$assert(Engine::number('0.125')===['numerator'=>1,'denominator'=>8],'decimal dose retains exact rational representation');
$assert(Engine::number('1 1/2')===['numerator'=>3,'denominator'=>2],'mixed fraction retains exact representation');
$assert(Engine::number('½')===['numerator'=>1,'denominator'=>2],'Unicode half is supported');
$assert($evaluate('1 STAT')['quantity']==='1.000','single-dose quantity needs no treatment duration');
$assert($evaluate('1 PRN X 5/7')['quantity']===null,'PRN does not infer expected consumption');
$assert($evaluate('1 BD')['quantity']===null,'missing duration requires manual quantity');
$assert($evaluate('1/3 BD X 5/7')['quantity']===null,'unrepresentable stock precision is never silently rounded');
$reject(fn()=>$evaluate('1 OD X 5/7'),'ambiguous OD is rejected');
$reject(fn()=>$evaluate('1 BD TDS X 5/7'),'conflicting frequency is rejected');
$reject(fn()=>$evaluate('1 XYZ X 5/7'),'unknown code is rejected');
$reject(fn()=>$evaluate('1 BD X 5/52'),'unsupported duration notation is rejected');
$reject(fn()=>$evaluate('1 BD X 0/7'),'zero duration is rejected');
$reject(fn()=>$evaluate('0 BD X 5/7'),'zero dose is rejected');
$reject(fn()=>$evaluate('1/0 BD X 5/7'),'zero denominator is rejected');
$reject(fn()=>$evaluate('1mg BD X 5/7'),'mass dose cannot be converted without validated strength data');
$reject(fn()=>$evaluate('1 BD NOW X 5/7'),'unconsumed tokens are rejected');
$reject(fn()=>$engine->parse('1 BD',$context,array_map(static function($d){$d['status']='draft';return $d;},$defs)),'draft codes are excluded from production parsing');
$reject(fn()=>$engine->parse('1 BD',$context,array_map(static function($d){$d['approval_status']='pending';return $d;},$defs)),'unapproved active code is excluded');
$duplicate=$defs; $duplicate[]=$defs[0]; $reject(fn()=>$engine->parse('1 BD',$context,$duplicate),'overlapping definitions require clarification');
$restricted=$defs; $restricted[0]['routes']=['ophthalmic']; $reject(fn()=>$engine->parse('1 BD',$context,$restricted),'route restrictions are enforced');
$restricted=$defs; $restricted[0]['forms']=['capsule']; $reject(fn()=>$engine->parse('1 BD',$context,$restricted),'form restrictions are enforced');
$base=['dose'=>'1','unit'=>'tablet','route'=>'oral','frequency'=>'daily_2','duration_days'=>'5'];
$reject(fn()=>$engine->evaluate(array_replace($base,['route'=>'ophthalmic']),Engine::profile('tablet')),'tablet ophthalmic route is rejected');
$reject(fn()=>$engine->evaluate($base,Engine::profile('unknown')),'unknown medication form requires configured compatibility');
$reject(fn()=>$engine->evaluate(array_replace($base,['unit'=>'drop']),Engine::profile('tablet')),'incompatible dosage unit is rejected');
$reject(fn()=>$engine->evaluate(array_replace($base,['frequency'=>'bedtime','timing'=>'in the morning']),Engine::profile('tablet')),'conflicting bedtime and morning instructions rejected');
$reject(fn()=>$engine->evaluate(['dose'=>'1','unit'=>'drop','route'=>'ophthalmic','frequency'=>'daily_2'],Engine::profile('eye drops')),'eye drops require specified eye');
$eye=$engine->evaluate(['dose'=>'1','unit'=>'drop','route'=>'ophthalmic','site'=>'left eye','frequency'=>'daily_2','duration_days'=>'5'],Engine::profile('eye drops'));
$assert($eye['quantity']===null && str_contains($eye['directions'],'left eye'),'eye-drop bottles require product-specific manual conversion');
$inhaler=$engine->evaluate(['dose'=>'2','unit'=>'puff','route'=>'inhalation','frequency'=>'daily_2','duration_days'=>'5'],Engine::profile('inhaler'));
$assert($inhaler['quantity']===null,'inhaler packaging is not guessed');
foreach (['weekly','alternate','custom'] as $frequency) { $r=$engine->evaluate(array_replace($base,['frequency'=>$frequency,'additional'=>'Follow the written administration schedule.']),Engine::profile('tablet'));$assert($r['quantity']===null,$frequency.' schedule requires manual quantity verification'); }
$r=$engine->evaluate(array_replace($base,['frequency'=>'prn','max_administrations_per_day'=>'3']),Engine::profile('tablet'));
$assert($r['quantity']===null && $r['maximum_theoretical_quantity']==='15.000','PRN maximum remains separate from dispensing quantity');
$assert(str_contains($r['directions'],'up to 3 times daily'),'explicit PRN maximum appears in patient directions');
$reject(fn()=>$engine->evaluate(array_replace($base,['max_administrations_per_day'=>'3']),Engine::profile('tablet')),'PRN maximum conflicts with scheduled frequency');
$reject(fn()=>$engine->evaluate(array_replace($base,['site'=>'left eye']),Engine::profile('tablet')),'administration site cannot contradict the route');
$reject(fn()=>$engine->evaluate(array_replace($base,['frequency'=>'prn','max_administrations_per_day'=>'3.5']),Engine::profile('tablet')),'invalid PRN maximum is rejected');
$assert(Engine::profile('Capsules')['units']===['capsule'],'plural catalogue form is supported');
echo "\n{$pass} passed; {$fail} failed\n"; exit($fail ? 1 : 0);
