<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Sanitation\SanitationManager;
use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'sanitation'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$dpSanitationDeepModulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dpSanitationDeepModulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dpSanitationDeepModulesRoot);
\dataphyre\autoloader::register_framework_modules(['core','sanitation']);
if(!class_exists(\dataphyre\sanitation::class,false)){
	require_once $dpSanitationDeepModulesRoot.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

final class DpSanitationDeepStringable implements Stringable {
	public function __construct(private string $value=' value ') {}
	public function __toString(): string { return $this->value; }
}

final class DpSanitationDeepItem {
	public function __construct(public mixed $id=null,public mixed $nested=null) {}
}

/** @param array<string,mixed> $overrides */
function dp_sanitation_deep_config(NonPublicAccess $private,array $overrides=[]): array {
	return array_replace($private->invoke('normalizeRule','default'),$overrides);
}

test('sanitation manager deep coverage normalizes associative rules tokens scoped options and cache eviction',static function(Context $t): void {
	$manager=new SanitationManager();
	$private=$t->nonPublic($manager);
	for($index=0;$index<130;$index++){
		$private->invoke('normalizeRule','custom_type_'.$index);
	}
	$t->instanceOf(SanitationManager::class,$manager);
	$t->same('email',$private->invoke('normalizeRule',['required','email'])['type']);
	$callback=static fn(): bool=>true;
	$rule=[
		'type'=>'int','required'=>1,'nullable'=>1,'trim'=>0,'squish'=>1,'lower'=>1,'upper'=>1,'escape_html'=>0,'sometimes'=>1,
		'present'=>1,'raw'=>1,'min'=>2,'min_length'=>3,'max'=>8,'max_length'=>9,'default'=>'seed','cast'=>null,
		'in'=>'a','not_in'=>['b'],'starts_with'=>'a','ends_with'=>['z'],'contains'=>'x','same'=>null,'different'=>'peer','regex'=>null,
		'accepted'=>1,'declined'=>1,'digits'=>2,'min_value'=>'bad','max_value'=>'4','min_items'=>1,'max_items'=>3,
		'distinct'=>'ignore_case','distinct_ignore_case'=>true,'when'=>['field'=>'mode','value'=>'on'],'unless'=>static fn(): bool=>false,
		'unique_by'=>'id,name','unique_by_ignore_case'=>'email',
		'required_if'=>['field'=>'mode','values'=>['on']],'required_unless'=>'mode,off','required_with'=>'a,b','required_with_all'=>['a','b'],
		'required_without'=>'a','required_without_all'=>['a','b'],'present_if'=>'mode,on','present_unless'=>['mode','off'],
		'present_with'=>'a','present_with_all'=>['a','b'],'present_without'=>'a','present_without_all'=>['a','b'],
		'exclude_if'=>'mode,off','exclude_unless'=>['mode','on'],'exclude_when_blank'=>true,
		'validate'=>$callback,'validator'=>[$callback],'field'=>'users.0.email','label'=>null,'messages'=>['required'=>'Local'],
	];
	$config=$private->invoke('normalizeRule',$rule,[
		'field_pattern'=>'users.*.email',
		'messages'=>['users.*.email'=>['email'=>'Scoped']],
		'labels'=>['users.*.email'=>'user email'],
	]);
	$t->same('integer',$config['type']);
	$t->same('user email',$config['label']);
	$t->same('Local',$config['messages']['required']);
	$t->isTrue($config['distinct']);
	$t->isTrue($config['distinct_ignore_case']);
	$t->isTrue($config['unique_by_ignore_case']);
	$t->same('integer',$config['cast']);

	$arrayDistinct=$private->invoke('normalizeRule',['distinct'=>['ignore_case'=>true],'unique_by_ignore_case'=>false,'field'=>null,'label'=>'label','messages'=>'bad']);
	$t->isTrue($arrayDistinct['distinct_ignore_case']);
	$t->isFalse($arrayDistinct['unique_by_ignore_case']);
	$t->same([],$private->invoke('normalizeRule',[])['callbacks']);
	foreach(['integer','float','boolean'] as $type){
		$t->same($type,$private->invoke('normalizeRule',['type'=>$type])['cast']);
	}

	$tokens=[
		'', 'min:1','max:2','digits:3','same:peer','different:peer','regex:/x/','in:','not_in:','starts_with:','ends_with:','contains:',
		'min_value:bad','max_value:bad','min_items:1','max_items:2','distinct:insensitive','unique_by:id,name','unique_by_ignore_case:id',
		'required_if:mode,on','required_unless:mode,off','required_with:a,b','required_with_all:a,b','required_without:a,b','required_without_all:a,b',
		'present_if:mode,on','present_unless:mode,off','present_with:a,b','present_with_all:a,b','present_without:a,b','present_without_all:a,b',
		'exclude_if:mode,off','exclude_unless:mode,on','unknown:value','required','sometimes','present','nullable','trim','no_trim','squish','lower','upper',
		'raw','accepted','declined','distinct','exclude_when_blank','postal',
	];
	foreach($tokens as $token){
		$private->invoke('normalizeRule',$token);
	}
	$t->isFalse($private->invoke('normalizeRule','no_trim')['trim']);
	$t->isFalse($private->invoke('normalizeRule','raw')['escape_html']);
})->tag('sanitation','manager','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sanitation manager deep coverage closes scalar lifecycle casts messages and nested schema mutation paths',static function(Context $t): void {
	$manager=new SanitationManager();
	$private=$t->nonPublic($manager);
	foreach([
		['text','default'],['html','basic_html'],['raw_html','unrestricted'],['arr','array'],['phone','phone_number'],['name','person_name'],
		['int','integer'],['bool','boolean'],['postal','postal_code'],['','default'],['custom','custom'],
	] as [$input,$expected]){
		$t->same($expected,$private->invoke('normalizeType',$input));
	}
	$t->same('text',$private->invoke('stringify','text'));
	$t->same('4',$private->invoke('stringify',4));
	$t->same('4.5',$private->invoke('stringify',4.5));
	$t->same('1',$private->invoke('stringify',true));
	$t->same('0',$private->invoke('stringify',false));
	$t->same(' value ',$private->invoke('stringify',new DpSanitationDeepStringable()));
	$t->same(null,$private->invoke('stringify',new stdClass()));

	foreach([
		[null,'integer',null],[false,'integer',false],['2','integer',2],['2.5','float',2.5],['true','boolean',true],['yes','boolean',false],['x',null,'x'],
	] as [$value,$cast,$expected]){
		$t->same($expected,$private->invoke('castValue',$value,['cast'=>$cast]));
	}
	foreach(['email','url','phone_number','person_name','numeric','integer','float','boolean','array','list','slug','username','postal_code','other'] as $type){
		$t->notEmpty($private->invoke('invalidTypeMessage',$type));
	}

	$t->pathEquals('failed',true,$manager->sanitizeDetailed(new stdClass(),'default'));
	$t->pathEquals('value',null,$manager->sanitizeDetailed(null,'nullable',['present'=>false]));
	$t->pathEquals('failed',true,$manager->sanitizeDetailed(null,'required',['present'=>false]));
	$t->pathEquals('value','',$manager->sanitizeDetailed(null,'default'));
	$t->pathEquals('failed',true,$manager->sanitizeDetailed(['one'],'array|min_items:2'));
	$t->pathEquals('value','value',$manager->sanitizeDetailed(new DpSanitationDeepStringable(),'default'));
	$t->pathEquals('value','fallback',$manager->sanitizeDetailed('   ',['default'=>'fallback','squish'=>true]));
	$t->pathEquals('value',null,$manager->sanitizeDetailed('   ','nullable|squish'));
	$t->pathEquals('failed',true,$manager->sanitizeDetailed('   ','required|squish'));
	$t->pathEquals('failed',true,$manager->sanitizeDetailed('bad','email'));
	$t->pathEquals('failed',true,$manager->sanitizeDetailed('x','min:2'));
	$t->pathEquals('failed',true,$manager->sanitizeDetailed('long','max:2'));
	$t->pathEquals('value','MIXED',$manager->sanitizeDetailed(' mixed ','trim|upper'));

	$result=$manager->schema([
		'nested'=>['remove'=>'','bad'=>'x'],
	],[
		'nested.remove'=>'default|exclude_when_blank',
		'nested.bad'=>'default|in:y',
	],['nested'=>['remove'=>'seed','bad'=>'seed']]);
	$t->isTrue($result->failed());
	$t->isFalse(array_key_exists('remove',$result->validated()['nested'] ?? []));
	$t->isFalse(array_key_exists('bad',$result->validated()['nested'] ?? []));
})->tag('sanitation','manager','deep-coverage')->group('framework-coverage');

test('sanitation manager deep coverage exercises path wildcard field scope and by-reference tree helpers',static function(Context $t): void {
	$manager=new SanitationManager();
	$private=$t->nonPublic($manager);
	$t->same(['present'=>true,'value'=>1],$private->invoke('pathValueSegments',[ 'a'=>1],['a']));
	$t->same(['present'=>false,'value'=>null],$private->invoke('pathValueSegments',[ 'a'=>1],['a','b']));
	$t->same(['present'=>true,'value'=>2],$private->invoke('pathValueSegments',[ 'a'=>['b'=>2]],['a','b']));

	$target=[];
	$call=$private->capture('setPathValue',target:$target,path:'top',value:1);
	$target=$call->argument('target');
	$call=$private->capture('setPathValue',target:$target,path:'nested.value',value:2);
	$target=$call->argument('target');
	$t->same(1,$target['top']);
	$t->same(2,$target['nested']['value']);
	$call=$private->capture('setPathValueSegments',target:$target,segments:['single'],value:3);
	$target=$call->argument('target');
	$target['replace']='scalar';
	$call=$private->capture('setPathValueSegments',target:$target,segments:['replace','inside'],value:4);
	$target=$call->argument('target');
	$call=$private->capture('setPathValueSegments',target:$target,segments:[],value:5);
	$target=$call->argument('target');
	$t->same(4,$target['replace']['inside']);

	$call=$private->capture('unsetPathValue',target:$target,path:'top');
	$target=$call->argument('target');
	$call=$private->capture('unsetPathValue',target:$target,path:'nested.value');
	$target=$call->argument('target');
	$call=$private->capture('unsetPathValueSegments',target:$target,segments:['single']);
	$target=$call->argument('target');
	$call=$private->capture('unsetPathValueSegments',target:$target,segments:['missing','child']);
	$target=$call->argument('target');
	$target['deep']=['child'=>['leaf'=>1]];
	$call=$private->capture('unsetPathValueSegments',target:$target,segments:['deep','child','leaf']);
	$target=$call->argument('target');
	$t->isFalse(isset($target['top'],$target['single'],$target['deep']['child']['leaf']));

	$t->same([],$private->invoke('wildcardPathMatches',[ 'items'=>'bad'],'items.*.name'));
	$t->same([],$private->invoke('wildcardPathMatches',[ 'items'=>[['other'=>1]]],'items.*.name'));
	$t->same('exact',$private->invoke('fieldScopedOptionValue',[ 'items.0.name'=>'exact'],'items.0.name','items.*.name'));
	$t->same('pattern',$private->invoke('fieldScopedOptionValue',[ 'items.*.name'=>'pattern'],'items.0.name','items.*.name'));
	$t->same('wild',$private->invoke('fieldScopedOptionValue',[ 2=>'skip','items.*.name'=>'wild'],'items.1.name',null));
	$t->same(null,$private->invoke('fieldScopedOptionValue',[ 'items.*.name'=>'wild'],'other.1.name',null));
	$t->isFalse($private->invoke('fieldPatternMatches','items.*','items.0.name'));
	$t->isFalse($private->invoke('fieldPatternMatches','items.*.name','other.0.name'));
	$t->isTrue($private->invoke('fieldPatternMatches','items.*.name','items.0.name'));
	$t->same('items.2.code',$private->invoke('resolveComparisonField','code',['field'=>'items.2.name','wildcard_values'=>[]]));
	$t->same('items.3.code',$private->invoke('resolveComparisonField','items.*.code',['field'=>'items.2.name','wildcard_values'=>['3']]));
	$t->same('plain',$private->invoke('applyWildcardValues','plain',['1']));
	$t->same('items.1.2',$private->invoke('applyWildcardValues','items.*.*',['1','2']));

	$t->same(['present'=>true,'value'=>['x'=>1]],$private->invoke('relativePathValue',[ 'x'=>1],''));
	$t->same(['present'=>true,'value'=>2],$private->invoke('relativePathValue',[ 'a'=>['b'=>2]],'a.b'));
	$t->same(['present'=>true,'value'=>3],$private->invoke('relativePathValue',new DpSanitationDeepItem(3),'id'));
	$t->same(['present'=>false,'value'=>null],$private->invoke('relativePathValue',new stdClass(),'missing'));
	$t->isTrue($private->invoke('isListArray',[]));
	$t->isFalse($private->invoke('isListArray',[1=>'x']));
})->tag('sanitation','manager','deep-coverage')->group('framework-coverage');

test('sanitation manager deep coverage normalizes distinct conditional and comparison value families',static function(Context $t): void {
	$manager=new SanitationManager();
	$private=$t->nonPublic($manager);
	foreach([
		['Text',true], [1,false], [1.5,false], [true,false], [null,false], [new DpSanitationDeepStringable('Text'),true],
		[[1,'A'],true], [['b'=>'B','a'=>'A'],true], [new stdClass(),false],
	] as [$value,$ignoreCase]){
		$t->notEmpty($private->invoke('normalizeDistinctValue',$value,$ignoreCase));
	}
	$t->isTrue($private->invoke('collectionHasDuplicateBy',[['id'=>null],['id'=>''],['id'=>'A'],['id'=>'A']],['id'],false));
	$t->isFalse($private->invoke('collectionHasDuplicateBy',[new DpSanitationDeepItem('A'),new DpSanitationDeepItem('B')],['id'],false));
	$t->same(['id','name'],$private->invoke('normalizeUniqueByFields',' id, name, '));
	$t->same(['id'],$private->invoke('normalizeUniqueByFields',[' id ','']));
	$t->same(['2'],$private->invoke('normalizeUniqueByFields',2));

	foreach([
		['mode,on,off',['field'=>'mode','values'=>['on','off']]],
		[['field'=>'mode','values'=>'on,off'],['field'=>'mode','values'=>['on','off']]],
		[['field'=>' '],null],[[],null],[false,null],
	] as [$input,$expected]){
		$t->equals($expected,$private->invoke('normalizeConditionalExclusion',$input));
	}
	$callback=static fn(): bool=>true;
	$t->same(null,$private->invoke('normalizeSchemaCondition',null));
	$t->isTrue($callback===$private->invoke('normalizeSchemaCondition',$callback));
	$t->isTrue($callback===$private->invoke('normalizeSchemaCondition',['callback'=>$callback]));
	$t->same(null,$private->invoke('normalizeSchemaCondition',['field'=>' ']));
	$t->same(null,$private->invoke('normalizeSchemaCondition',['field'=>'mode']));
	$t->same(null,$private->invoke('normalizeSchemaCondition',[]));
	$condition=$private->invoke('normalizeSchemaCondition',['field'=>'mode','values'=>['on'],'present'=>true,'filled'=>true,'blank'=>false]);
	$t->same('mode',$condition['field']);
	$t->notEmpty($private->invoke('normalizeSchemaCondition','mode,on'));
	$t->same(['a','b'],$private->invoke('normalizeFieldList',' a,b, '));
	$t->same(['a'],$private->invoke('normalizeFieldList',[' a ','']));
	$t->same(['2'],$private->invoke('normalizeFieldList',2));
	$t->same([],$private->invoke('normalizeConditionalExclusionValues',''));
	$t->same(['a','b'],$private->invoke('normalizeConditionalExclusionValues','a,b'));
	$t->same([2],$private->invoke('normalizeConditionalExclusionValues',2));

	$config=dp_sanitation_deep_config($private,['field'=>'target','trim'=>true,'squish'=>false,'wildcard_values'=>[]]);
	$t->isTrue($private->invoke('comparisonEquivalent',['a'=>1],['a'=>1]));
	foreach([[null,'null'],[true,'1'],[4,'4'],[4.5,'4.5'],[' x ','x'],[new DpSanitationDeepStringable(' x '),'x'],[[],null]] as [$input,$expected]){
		$t->same($expected,$private->invoke('comparisonScalarValue',$input));
	}
	$t->isTrue($private->invoke('comparisonFieldFilled','present',['present'=>'x'],[],$config));
	$t->isFalse($private->invoke('comparisonFieldFilled','missing',[],[],$config));
	$t->isTrue($private->invoke('comparisonFieldPresent','present',[],['present'=>null],$config));
	$t->isTrue($private->invoke('comparisonFieldMissingOrBlank','blank',[],['blank'=>' '],$config));
	$t->isTrue($private->invoke('comparisonFieldMissing','missing',[],[],$config));
	$t->same(['present'=>true,'value'=>'context'],$private->invoke('comparisonFieldState','field',['field'=>'context'],['field'=>'input'],$config));
	$t->same(['present'=>true,'value'=>'input'],$private->invoke('comparisonFieldState','field',[],['field'=>'input'],$config));
	$t->same('value',$private->invoke('humanizeField',' '));
	for($index=0;$index<130;$index++){
		$private->invoke('humanizeField','field_'.$index);
	}
	$t->same('Peer label',$private->invoke('otherFieldLabel','peer',array_replace($config,['labels'=>['peer'=>'Peer label']])));

	foreach([[null,true],[' ',true],[new DpSanitationDeepStringable(' '),true],[[],true],[0,false]] as [$value,$expected]){
		$t->same($expected,$private->invoke('isBlankValue',$value,['trim'=>true,'squish'=>true]));
	}
	$t->isTrue($private->invoke('isBlankForExclusion','x',$config,false));
	foreach([[null,false],[' ',false],[[],false],['x',true],[[1],true],[0,true]] as [$value,$expected]){
		$t->same($expected,$private->invoke('hasComparableUniqueValue',$value));
	}
	$t->isTrue($private->invoke('isCacheableTree',[1,'x',null,['nested'=>true]]));
	$t->isFalse($private->invoke('isCacheableTree',[['nested'=>new stdClass()]]));
	foreach([[true,true],[false,false],['false',false],['off',false],['no',false],['0',false],['yes',true],[1,true],[0,false]] as [$value,$expected]){
		$t->same($expected,$private->invoke('truthyRuleValue',$value));
	}
})->tag('sanitation','manager','deep-coverage')->group('framework-coverage');

test('sanitation manager deep coverage evaluates conditional required presence and schema decision branches',static function(Context $t): void {
	$manager=new SanitationManager();
	$private=$t->nonPublic($manager);
	$input=['mode'=>'on','filled'=>'yes','filled2'=>'yes','blank'=>' ','present'=>null];
	$config=dp_sanitation_deep_config($private,[
		'required_if'=>['field'=>'mode','values'=>['on']],
		'required_unless'=>['field'=>'mode','values'=>['off']],
		'required_with'=>['filled'],'required_with_all'=>['filled','filled2'],
		'required_without'=>['blank'],'required_without_all'=>['blank','missing'],
		'present_if'=>['field'=>'mode','values'=>['on']],
		'present_unless'=>['field'=>'mode','values'=>['off']],
		'present_with'=>['present'],'present_with_all'=>['present','filled'],
		'present_without'=>['missing'],'present_without_all'=>['missing','other_missing'],
		'field'=>'target','wildcard_values'=>[],
	]);
	$required=$private->invoke('applyConditionalRequired',$config,['context'=>[],'input'=>$input]);
	$t->isTrue($required['required']);
	$present=$private->invoke('applyConditionalPresence',$config,['context'=>[],'input'=>$input]);
	$t->isTrue($present['must_present']);

	foreach([
		['anyComparisonFieldFilled',['filled'],true],['anyComparisonFieldFilled',['missing'],false],
		['anyComparisonFieldPresent',['present'],true],['anyComparisonFieldPresent',['missing'],false],
		['allComparisonFieldsPresent',[],false],['allComparisonFieldsPresent',['present','filled'],true],['allComparisonFieldsPresent',['present','missing'],false],
		['allComparisonFieldsFilled',[],false],['allComparisonFieldsFilled',['filled','filled2'],true],['allComparisonFieldsFilled',['filled','blank'],false],
		['anyComparisonFieldMissingOrBlank',['blank'],true],['anyComparisonFieldMissingOrBlank',['filled'],false],
		['anyComparisonFieldMissing',['missing'],true],['anyComparisonFieldMissing',['filled'],false],
		['allComparisonFieldsMissing',[],false],['allComparisonFieldsMissing',['missing','other_missing'],true],['allComparisonFieldsMissing',['missing','filled'],false],
		['allComparisonFieldsMissingOrBlank',[],false],['allComparisonFieldsMissingOrBlank',['missing','blank'],true],['allComparisonFieldsMissingOrBlank',['missing','filled'],false],
	] as [$method,$fields,$expected]){
		$t->same($expected,$private->invoke($method,$fields,[],$input,$config));
	}

	$t->isFalse($private->invoke('shouldProcessSchemaRule',array_replace($config,['sometimes'=>true]),false,$input,[]));
	$t->isFalse($private->invoke('shouldProcessSchemaRule',array_replace($config,['when'=>['field'=>'mode','values'=>['off']]]),true,$input,[]));
	$t->isFalse($private->invoke('shouldProcessSchemaRule',array_replace($config,['unless'=>['field'=>'mode','values'=>['on']]]),true,$input,[]));
	$t->isTrue($private->invoke('shouldProcessSchemaRule',array_replace($config,['when'=>null,'unless'=>null]),true,$input,[]));

	$t->isTrue($private->invoke('schemaConditionMatches',static fn(): bool=>true,[],$input,$config));
	$t->isFalse($private->invoke('schemaConditionMatches',['field'=>''],[],$input,$config));
	foreach([
		[['field'=>'missing','present'=>true],false],
		[['field'=>'filled','filled'=>false],false],
		[['field'=>'filled','blank'=>true],false],
		[['field'=>'mode','values'=>['off']],false],
		[['field'=>'mode','present'=>true,'filled'=>true,'blank'=>false,'values'=>['on']],true],
	] as [$condition,$expected]){
		$t->same($expected,$private->invoke('schemaConditionMatches',$condition,[],$input,$config));
	}
})->tag('sanitation','manager','deep-coverage')->group('framework-coverage');

test('sanitation manager deep coverage validates constraint edge shapes and wildcard duplicate removal',static function(Context $t): void {
	$manager=new SanitationManager();
	$private=$t->nonPublic($manager);
	$base=dp_sanitation_deep_config($private,['field'=>'items','field_pattern'=>'items','label'=>'items']);
	$cases=[
		['x',['min_items'=>1]],['x',['max_items'=>1]],
		[['bad'=>'shape'],['unique_by'=>['id']]],
		[[['id'=>'A'],['id'=>'A']],['unique_by'=>['id']]],
		['12',['digits'=>3]],['x',['min_value'=>1]],['x',['max_value'=>1]],['no',['accepted'=>true]],['yes',['declined'=>true]],
		['x',['in'=>['y']]],['x',['not_in'=>['x']]],['y',['same'=>'peer']],['x',['different'=>'peer']],['x',['regex'=>'/[invalid/']],
		['x',['starts_with'=>['','y']]],['x',['ends_with'=>['','y']]],['x',['contains'=>['y']]],
		['x',['callbacks'=>['invalid',static fn()=>true,static fn()=>null,static fn()=>false]]],
	];
	foreach($cases as [$value,$overrides]){
		$config=array_replace($base,$overrides);
		$t->notEmpty($private->invoke('validateConstraints',$value,$config,['peer'=>'x'],['peer'=>'x']));
	}
	$stringCallback=array_replace($base,['callbacks'=>[static fn(): string=>'Custom']]);
	$t->same('Custom',$private->invoke('validateConstraints','x',$stringCallback,[],[]));
	$t->same(null,$private->invoke('validateConstraints','x',array_replace($base,['callbacks'=>[static fn()=>true,static fn()=>null]]),[],[]));
	$t->same(null,$private->invoke('validateConstraints','prefix-suffix',array_replace($base,['starts_with'=>['prefix'],'ends_with'=>['suffix']]),[],[]));

	$targets=[
		['field'=>'items.0.code'],['field'=>'items.1.code'],['field'=>'items.2.code'],['field'=>'skip.error'],['field'=>'skip.missing'],
	];
	$configs=[];
	foreach(['items.0.code','items.1.code','items.2.code'] as $field){
		$configs[$field]=array_replace($base,['field'=>$field,'field_pattern'=>'items.*.code','distinct'=>true,'distinct_ignore_case'=>true]);
	}
	$configs['skip.error']=array_replace($base,['field'=>'skip.error','field_pattern'=>'skip.error','distinct'=>false]);
	$data=['items'=>[['code'=>'A'],['code'=>'a'],['code'=>'B']]];
	$errors=['skip.error'=>'existing'];
	$call=$private->capture('applyDistinctConstraints',targets:$targets,configs:$configs,data:$data,errors:$errors);
	$data=$call->argument('data');
	$errors=$call->argument('errors');
	$t->hasKey('items.1.code',$errors);
	$t->isFalse(array_key_exists('code',$data['items'][1]));
})->tag('sanitation','manager','deep-coverage')->group('framework-coverage');
