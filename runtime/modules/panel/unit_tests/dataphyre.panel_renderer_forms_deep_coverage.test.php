<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/panel_test_probes.php';

use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelComponentRegistry;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TestFixtures\PanelCsrfProbe;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'panel'=>true,'mvc'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['panel','mvc']);
if(!class_exists(\Dataphyre\Csrf::class)){
	require_once $modulesRoot.'/core/Framework/CsrfToken.php';
	require_once $modulesRoot.'/core/Framework/Csrf.php';
}

/** @param array<string,mixed> $input @param array<string,mixed> $query @param array<string,mixed> $files */
function dp_panel_renderer_forms_request(string $method='POST',array $input=[],array $query=[],array $files=[],string $operation='create'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'coverage-forms',
		'operation'=>$operation,
		'input'=>$input,
		'query'=>$query,
		'files'=>$files,
		'user'=>['id'=>17,'name'=>'Forms Coverage'],
	]);
}

/** @return array<string,mixed> */
function dp_panel_renderer_forms_json(\Dataphyre\Panel\PanelPageResult $result): array {
	$payload=json_decode($result->content(),true);
	return is_array($payload) ? $payload : [];
}

/** @param array<string,mixed> $extra @return array<string,mixed> */
function dp_panel_renderer_forms_meta(string $type='text',array $extra=[]): array {
	return array_replace_recursive([
		'name'=>'coverage_field',
		'type'=>$type,
		'label'=>'Coverage field',
		'placeholder'=>'Enter a value',
		'required'=>true,
		'readonly'=>false,
		'options'=>[],
		'meta'=>[],
	],$extra);
}

test('panel renderer forms renders every primary control family',static function(Context $t): void {
	$t->same('User Profile',$t->nonPublic(PanelRenderer::class)->invoke('humanFieldLabel','user_profile'));
	foreach([
		'search'=>'search','email'=>'email','number'=>'number','integer'=>'number','float'=>'number','money'=>'number',
		'password'=>'password','date'=>'date','datetime'=>'datetime-local','datetime_local'=>'datetime-local','month'=>'month',
		'week'=>'week','time'=>'time','phone_international'=>'tel','url'=>'url','latitude'=>'number','color'=>'color',
		'range'=>'range','slider'=>'range','unknown'=>'text',
	] as $type=>$native){
		$t->same($native,$t->nonPublic(PanelRenderer::class)->invoke('inputType',$type));
	}

	PanelComponentRegistry::registerFieldType('coverage_custom',static fn(string $name,array $meta,mixed $value): string=>'<custom data-name="'.$name.'">'.$value.'</custom>');
	$t->contains('<custom',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','custom',dp_panel_renderer_forms_meta('coverage_custom'),'ok'));
	$t->contains('type="hidden"',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','secret',dp_panel_renderer_forms_meta('text'),'hidden',true));
	$t->contains('type="hidden"',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','secret',dp_panel_renderer_forms_meta('hidden'),'hidden'));

	$display=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','notice',dp_panel_renderer_forms_meta('display',[
		'placeholder'=>'Fallback',
		'meta'=>['display_content'=>'<p onclick="bad()">Safe <strong>content</strong><script>bad()</script></p>','html'=>true,'description'=>'Helpful'],
	]),'');
	$t->contains('dp-panel-display-field',$display);
	$t->same(false,str_contains($display,'script'));
	$t->contains('Fallback',$t->nonPublic(PanelRenderer::class)->invoke('displayOnlyControl',dp_panel_renderer_forms_meta('display',['placeholder'=>'Fallback']),''));

	$repeaterMeta=dp_panel_renderer_forms_meta('repeater',['meta'=>[
		'min_items'=>2,'max_items'=>4,'add_item_label'=>'Add person',
		'repeater_fields'=>[
			['name'=>'name','type'=>'text','label'=>'Name','required'=>true,'meta'=>[]],
			['name'=>'active','type'=>'toggle','label'=>'Active','meta'=>[]],
		],
	]]);
	$t->contains('data-dp-panel-repeater',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','people',$repeaterMeta,[['name'=>'Ada','active'=>1]]));

	$builderMeta=dp_panel_renderer_forms_meta('builder',['meta'=>[
		'add_block_label'=>'Add block',
		'builder_blocks'=>[
			['type'=>'hero','label'=>'Hero','fields'=>[['name'=>'title','type'=>'text','label'=>'Title','meta'=>[]]]],
			['type'=>'quote','label'=>'Quote','fields'=>[['name'=>'body','type'=>'textarea','label'=>'Body','meta'=>[]]]],
		],
	]]);
	$t->contains('data-dp-panel-builder',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','content',$builderMeta,[['_type'=>'hero','title'=>'Hello'],['_type'=>'missing']]));

	$groupMeta=dp_panel_renderer_forms_meta('fieldset',['meta'=>[
		'child_fields'=>[
			['name'=>'street','type'=>'text','label'=>'Street','meta'=>[]],
			['name'=>'zip','type'=>'text','label'=>'Zip','meta'=>[]],
		],
	]]);
	$t->contains('dp-panel-fieldset',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','address',$groupMeta,['street'=>'Main','zip'=>'H0H0H0']));

	$slider=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','volume',dp_panel_renderer_forms_meta('slider',[
		'readonly'=>true,'meta'=>['min'=>-10,'max'=>10,'step'=>2,'value_label'=>'Volume'],
	]),'');
	$t->contains('data-dp-panel-slider',$slider);
	$t->contains('value="-10"',$slider);
	$t->contains('type="hidden"',$slider);
	$t->contains('dp-panel-slider',$t->nonPublic(PanelRenderer::class)->invoke('sliderControl','***',dp_panel_renderer_forms_meta('slider',['meta'=>['value_display'=>false]]),5,''));

	foreach(['date_range','datetime_range','timerange'] as $rangeType){
		$html=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','period',dp_panel_renderer_forms_meta($rangeType,[
			'readonly'=>true,'meta'=>['min'=>'2026-01-01','max'=>'2026-12-31','step'=>1,'start_label'=>'From','end_label'=>'Until'],
		]),['start'=>'2026-01-01','end'=>'2026-02-01']);
		$t->contains('dp-panel-range-pair',$html);
	}
	$t->same(['start'=>'a','end'=>'b'],$t->nonPublic(PanelRenderer::class)->invoke('rangePairValues',['a','b']));
	$t->same(['start'=>'','end'=>''],$t->nonPublic(PanelRenderer::class)->invoke('rangePairValues',''));
	$t->same(['start'=>'a','end'=>'b'],$t->nonPublic(PanelRenderer::class)->invoke('rangePairValues','a to b'));

	$rating=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','rating',dp_panel_renderer_forms_meta('rating',[
		'readonly'=>true,'meta'=>['min'=>0,'max'=>20,'step'=>2],
	]),4);
	$t->contains('role="radiogroup"',$rating);
	$t->contains('checked',$rating);

	foreach(['toggle','boolean','checkbox'] as $booleanType){
		$html=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','active',dp_panel_renderer_forms_meta($booleanType,[
			'readonly'=>$booleanType==='toggle','meta'=>['on_label'=>'Yes','off_label'=>'No'],
		]),$booleanType!=='checkbox');
		$t->contains('dp-panel-switch',$html);
	}

	foreach(['textarea','markdown','html','code','rich_editor','rich_text'] as $editorType){
		$html=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','body',dp_panel_renderer_forms_meta($editorType,[
			'readonly'=>$editorType==='rich_text',
			'meta'=>['editor'=>$editorType,'preview'=>true,'preview_mode'=>$editorType,'rows'=>99,'code_language'=>'php'],
		]),"**Bold**\n<script>bad()</script><p>ok</p>");
		$t->contains('textarea',$html);
	}
	$t->contains('textarea',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','body',dp_panel_renderer_forms_meta('textarea',['meta'=>['preview'=>false]]),'plain'));

	$options=[
		'a'=>'Alpha',
		'b'=>['label'=>'Beta','disabled'=>true,'description'=>'Second'],
		'group'=>['label'=>'Group','options'=>['c'=>'Gamma','d'=>['label'=>'Delta','disabled'=>false]]],
	];
	$t->contains('type="radio"',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','choice',dp_panel_renderer_forms_meta('radio',['options'=>$options]),'a'));
	$t->contains('name="choices[]"',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','choices',dp_panel_renderer_forms_meta('checkbox_list',['options'=>$options]),['a','c']));
	$t->contains('dp-panel-choice-list-buttons',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','segment',dp_panel_renderer_forms_meta('toggle_buttons',['options'=>$options,'meta'=>['multiple'=>true]]),['d']));
	$t->contains('multiple',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','relations',dp_panel_renderer_forms_meta('belongs_to_many',[
		'options'=>$options,'readonly'=>true,'meta'=>['searchable'=>true,'relationship'=>['resource'=>'users','searchable'=>true]],
	]),['a','c']));

	$tags=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','tags',dp_panel_renderer_forms_meta('tags_input',['meta'=>[
		'tag_separator'=>'','min_tags'=>1,'max_tags'=>5,'suggestions'=>['one',['value'=>'two'],2],
	]]),['one','two']);
	$t->contains('data-dp-panel-tags',$tags);
	$kv=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','pairs',dp_panel_renderer_forms_meta('key_value',['meta'=>[
		'key_separator'=>'','pair_separator'=>'','min_pairs'=>1,'max_pairs'=>3,'rows'=>8,
	]]),['a'=>'1','b'=>['nested']]);
	$t->contains('data-dp-panel-key-value',$kv);

	$select=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','choice',dp_panel_renderer_forms_meta('select',[
		'options'=>$options,'readonly'=>true,'required'=>false,'meta'=>[
			'clearable'=>true,'empty_label'=>'None','searchable'=>true,'search_threshold'=>1,
			'relationship'=>['resource'=>'people','searchable'=>true,'search_endpoint'=>'/panel/people/options'],
		],
	]),'c');
	$t->contains('dp-panel-searchable-select',$select);
	$t->contains('type="hidden"',$select);

	$text=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','email',dp_panel_renderer_forms_meta('email',['meta'=>[
		'prepend_label'=>'@','append_label'=>'.com','prepend_icons'=>[['icon'=>'mail','label'=>'Mail'],[]],
		'append_icons'=>[['icon'=>'check','label'=>'']],
		'prepend_buttons'=>[['label'=>'Go','url'=>'/go','target'=>'_self','attributes'=>['aria-label'=>'Go','onclick'=>'bad']]],
		'append_buttons'=>[['label'=>'Copy','action'=>'copy','copy_normalized'=>true,'icon'=>'copy','value'=>'x','attributes'=>['data-ok'=>'1','aria-live'=>true,'aria-no'=>false,'data-array'=>[]]]],
		'clearable'=>true,'copyable'=>true,'character_counter'=>true,'character_counter_max'=>20,'character_counter_position'=>'prepend',
		'min'=>1,'max'=>9,'step'=>2,'min_length'=>2,'max_length'=>20,'pattern'=>'[a-z]+','input_mode'=>'email',
		'autocomplete'=>'email','title'=>'Email','suggestions'=>['a@example.com',['value'=>'b@example.com']],
	]]),'a@example.com');
	$t->contains('dp-panel-input-shell',$text);
	$t->contains('datalist',$text);
	$t->contains('data-dp-panel-field-button="copy"',$t->nonPublic(PanelRenderer::class)->invoke('textInputShell',dp_panel_renderer_forms_meta('text',['meta'=>[
		'copyable'=>true,'copy_label'=>'Copy exact','copy_normalized'=>true,
	]]),'<input>'));
	$t->contains('toggle_password',$t->nonPublic(PanelRenderer::class)->invoke('textInputShell',dp_panel_renderer_forms_meta('password',['meta'=>['revealable'=>true]]),'<input>'));
	$t->same('<input>',$t->nonPublic(PanelRenderer::class)->invoke('textInputShell',dp_panel_renderer_forms_meta('file'),'<input>'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('inputAdornmentGroupHtml','append','',[],[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('inputIconHtml','append',[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('characterCounterHtml',dp_panel_renderer_forms_meta(), 'append'));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('colorSwatchHtml',dp_panel_renderer_forms_meta('text')));
	$t->contains('dp-panel-input-color-swatch',$t->nonPublic(PanelRenderer::class)->invoke('colorSwatchHtml',dp_panel_renderer_forms_meta('color')));
	$t->contains('data-dp-panel-field-button',$t->nonPublic(PanelRenderer::class)->invoke('inputButtonHtml','append',['label'=>'Unsafe','url'=>'javascript:alert(1)','target'=>'bad','action'=>'clear']));
	$t->contains('rel="noopener noreferrer"',$t->nonPublic(PanelRenderer::class)->invoke('inputButtonHtml','append',['label'=>'Docs','url'=>'https://example.com','target'=>'_blank']));
});

test('panel renderer forms covers masks formatters editor previews choices and value helpers',static function(Context $t): void {
	foreach([
		['','', 'plain'],
		['markdown','**Bold** and `code`','plain'],
		['html','<p onclick="x">Hello<script>x</script></p>','plain'],
		['rich_text','<strong><p>Hello</p></strong><ul></ul><p> </p>','plain'],
		['code','<?php echo 1;','php'],
		['plain',"Hello\nworld",'plain'],
	] as $case){
		$t->contains('dp-panel-editor-preview',$t->nonPublic(PanelRenderer::class)->invoke('editorPreviewHtml',...$case));
	}
	$t->contains('aria-label=',$t->nonPublic(PanelRenderer::class)->invoke('textareaControl','body',dp_panel_renderer_forms_meta('html',['placeholder'=>'','meta'=>['editor'=>'html','preview'=>true]]),'value','','',''));
	foreach(['markdown'=>'Markdown','html'=>'HTML','code'=>'Code','rich_editor'=>'Rich text','other'=>'Text'] as $mode=>$label){
		$t->same($label,$t->nonPublic(PanelRenderer::class)->invoke('editorLabel',$mode));
	}
	$t->same(false,str_contains($t->nonPublic(PanelRenderer::class)->invoke('safeRichHtml','<script>x</script><p onclick="x">ok</p>'),'script'));

	$formatMeta=dp_panel_renderer_forms_meta('text',['meta'=>[
		'min'=>1,'max'=>9,'step'=>.5,'min_length'=>2,'max_length'=>20,'input_mode'=>'numeric','autocomplete'=>'off','title'=>'Formatted',
		'mask'=>'(999) 999-9999','format_rule'=>'decimal','format_options'=>['decimals'=>3],
		'format_event'=>'blur','placeholder_from_mask'=>true,'mask_title'=>true,'format_title'=>true,
		'normalize_on_submit'=>true,'normalization_rule'=>'trim','normalization_options'=>['upper'=>true],
		'suggestions'=>['123',['value'=>'456'],['label'=>'No value'],null],
	]]);
	$attrs=$t->nonPublic(PanelRenderer::class)->invoke('inputAttributeHtml',$formatMeta,'formatted');
	$t->contains('data-dp-panel-mask',$attrs);
	$t->contains('data-dp-panel-format',$attrs);
	$t->contains('data-dp-panel-submit-normalized',$attrs);
	$t->contains('list="dp-panel-list-',$attrs);
	$t->contains('<datalist',$t->nonPublic(PanelRenderer::class)->invoke('datalistHtml','formatted',$formatMeta));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('datalistHtml','',dp_panel_renderer_forms_meta()));

	foreach([
		['999-AAA','000-AAA'],['XX-99','XX-00'],['',''],
	] as [$mask,$expected]){
		$t->same($expected,$t->nonPublic(PanelRenderer::class)->invoke('maskPlaceholderFromPattern',$mask));
	}
	foreach(['phone','phone_us','phone_ca','postal_code','postal','postal_code_international','decimal','integer','money','currency','percent','percentage','credit_card','card','card_number','credit_card_expiry','card_expiry','card_cvc','cvc','cvv','iban','zip','postal_code_ca','zip_code_us','uuid','ulid','ipv4','ipv6','mac_address','hex_color','latitude','longitude','coordinates','phone_international','email','url','map_url','domain','timezone','locale','json','mime_type','semver','cron','language_code','country_code','subdivision_code','currency_code','ip_address','slug','unknown'] as $rule){
		$result=$t->nonPublic(PanelRenderer::class)->invoke('formatPlaceholderFromRule',$rule,['decimals'=>4,'currency'=>'CAD']);
		$t->same(true,is_string($result));
		$pattern=$t->nonPublic(PanelRenderer::class)->invoke('patternFromFormatRule',$rule);
		$t->same(true,is_string($pattern));
	}
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('placeholderAttributeHtml',dp_panel_renderer_forms_meta('text',['placeholder'=>'','meta'=>['mask'=>'999','mask_placeholder'=>false]])));
	$t->contains('Custom mask',$t->nonPublic(PanelRenderer::class)->invoke('placeholderAttributeHtml',dp_panel_renderer_forms_meta('text',['placeholder'=>'','meta'=>['mask'=>'999','mask_placeholder'=>'Custom mask']])));
	$t->contains('000',$t->nonPublic(PanelRenderer::class)->invoke('placeholderAttributeHtml',dp_panel_renderer_forms_meta('text',['placeholder'=>'','meta'=>['mask'=>'999']])));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('placeholderAttributeHtml',dp_panel_renderer_forms_meta('text',['placeholder'=>'','meta'=>['format_rule'=>'uuid','format_placeholder'=>false]])));
	$t->contains('Custom format',$t->nonPublic(PanelRenderer::class)->invoke('placeholderAttributeHtml',dp_panel_renderer_forms_meta('text',['placeholder'=>'','meta'=>['format_rule'=>'uuid','format_placeholder'=>'Custom format']])));
	$t->contains('international phone',$t->nonPublic(PanelRenderer::class)->invoke('formatTitleAttributeHtml',dp_panel_renderer_forms_meta('text',['placeholder'=>'','meta'=>['format_rule'=>'phone']])));
	foreach([0,1,3,20] as $decimals){
		$t->same(true,is_string($t->nonPublic(PanelRenderer::class)->invoke('decimalPlaceholder',$decimals)));
	}
	foreach(['999-AAA','XX?##','\\9A*',''] as $mask){
		$t->same(true,is_string($t->nonPublic(PanelRenderer::class)->invoke('patternFromMask',$mask)));
	}
	foreach(['-','.',']','\\','^'] as $literal){
		$t->same(true,is_string($t->nonPublic(PanelRenderer::class)->invoke('htmlPatternLiteral',$literal)));
	}

	foreach([
		dp_panel_renderer_forms_meta('text'),
		dp_panel_renderer_forms_meta('text',['meta'=>['mask'=>'999','mask_placeholder'=>'Code','mask_title'=>'Enter code']]),
		dp_panel_renderer_forms_meta('text',['meta'=>['format_rule'=>'uuid','format_placeholder'=>'UUID','format_pattern'=>'[a-f]+','format_title'=>'UUID title']]),
		dp_panel_renderer_forms_meta('text',['meta'=>['mask'=>'999','pattern'=>'[0-9]+','title'=>'Explicit']]),
	] as $meta){
		foreach(['placeholderAttributeHtml','maskLengthAttributeHtml','maskPatternAttributeHtml','maskTitleAttributeHtml','formatPatternAttributeHtml','formatTitleAttributeHtml','explicitFormatAttributeMarkers','submitNormalizationAttributeHtml','textAttributeHtml','choiceAttributeHtml'] as $method){
			$t->same(true,is_string($t->nonPublic(PanelRenderer::class)->invoke($method,$meta)));
		}
	}
	$textAttrs=$t->nonPublic(PanelRenderer::class)->invoke('textAttributeHtml',dp_panel_renderer_forms_meta('textarea',['placeholder'=>'','meta'=>[
		'autocomplete'=>'off','auto_resize'=>true,'format_rule'=>'uuid','format_options'=>['case'=>'lower'],'format_event'=>'input',
	]]));
	$t->contains('autocomplete="off"',$textAttrs);
	$t->contains('data-dp-panel-auto-resize',$textAttrs);
	$t->contains('data-dp-panel-format-options',$textAttrs);
	$t->contains('data-dp-panel-format-event',$textAttrs);
	$t->contains('data-dp-panel-submit-normalized="mask"',$t->nonPublic(PanelRenderer::class)->invoke('submitNormalizationAttributeHtml',dp_panel_renderer_forms_meta('text',['meta'=>['mask'=>'999','mask_submit_normalized'=>true]])));
	$t->contains('data-dp-panel-native="0"',$t->nonPublic(PanelRenderer::class)->invoke('choiceAttributeHtml',dp_panel_renderer_forms_meta('select',['meta'=>['native'=>false]])));
	$t->contains('data-dp-panel-autocomplete',$t->nonPublic(PanelRenderer::class)->invoke('inputAttributeHtml',dp_panel_renderer_forms_meta('autocomplete',['meta'=>['suggestions'=>[['value'=>'x']]]]),'auto'));

	$t->same(['a','b','3'],$t->nonPublic(PanelRenderer::class)->invoke('selectedValues',['a','b',3,null]));
	$t->same(['a','3'],$t->nonPublic(PanelRenderer::class)->invoke('selectedValues',['a',['nested'],new stdClass(),3,null]));
	$t->same(['a,b'],$t->nonPublic(PanelRenderer::class)->invoke('selectedValues','a,b'));
	$t->same(['a','b'],$t->nonPublic(PanelRenderer::class)->invoke('selectedValues','["a","b"]'));
	$t->contains('name="items[]"',$t->nonPublic(PanelRenderer::class)->invoke('hiddenListInputs','items',['a','b']));
	$t->same("a=1\nb=two",$t->nonPublic(PanelRenderer::class)->invoke('keyValueText',['a'=>1,'b'=>'two']));
	$t->same(['a'=>'Alpha','b'=>'Beta','c'=>'Gamma'],$t->nonPublic(PanelRenderer::class)->invoke('flatOptions',['a'=>'Alpha','group'=>['label'=>'G','options'=>['b'=>'Beta','c'=>'Gamma']]]));
	$t->same(3,count($t->nonPublic(PanelRenderer::class)->invoke('flatOptionMetas',['a'=>'Alpha','group'=>['label'=>'G','disabled'=>true,'options'=>['b'=>'Beta','c'=>['label'=>'Gamma']]]])));
	$t->contains('<optgroup',$t->nonPublic(PanelRenderer::class)->invoke('optionHtml',['group'=>['label'=>'G','options'=>['a'=>'Alpha']]],'a'));
	$t->same(2,$t->nonPublic(PanelRenderer::class)->invoke('optionCount',['a'=>'A','group'=>['options'=>['b'=>'B']]]));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('isOptionGroup',['label'=>'G','options'=>[]]));
	$t->same(false,$t->nonPublic(PanelRenderer::class)->invoke('isOptionGroup',['value'=>'a','label'=>'A']));
});

test('panel renderer forms covers upload nested field and accessibility rendering',static function(Context $t): void {
	PanelCsrfProbe::reset($t)->token('coverage_upload','coverage-csrf-token');
	if(!class_exists('dataphyre\\core',false)){
		\Dataphyre\Test\define_test_symbols('namespace dataphyre; class core { public static function csrf(string $formName,mixed $token=null): string|bool { if($token!==null){ return false; } return \\Dataphyre\\Panel\\TestFixtures\\PanelCsrfProbe::tokenFor($formName); } }');
	}
	$native=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','document',dp_panel_renderer_forms_meta('file_upload',[
		'accepted_types'=>['image/*','.pdf','application/json'],'multiple'=>true,'readonly'=>true,
	]),[['name'=>'one.pdf'],['name'=>'two.pdf'],'three.pdf',[]]);
	$t->contains('type="file"',$native);
	$t->contains('name="document[]"',$native);
	$t->contains('one.pdf, two.pdf, three.pdf',$native);

	class_exists(\Dataphyre\Csrf::class);
	$uploadMeta=dp_panel_renderer_forms_meta('drag_drop_upload',[
		'accepted_types'=>['image/*','.PDF','application/json',''],
		'multiple'=>true,
		'max_file_size'=>10485760,
		'media_collection'=>'attachments',
		'meta'=>[
			'custom_uploader'=>true,
			'upload_endpoint'=>'/panel/uploads',
			'upload_delete_endpoint'=>'javascript:bad()',
			'upload_chunk_size'=>1,
			'upload_retries'=>99,
			'upload_concurrency'=>99,
			'upload_min_files'=>1,
			'upload_max_files'=>5,
			'upload_driver'=>'local',
			'storage_disk'=>'public',
			'storage_path'=>'forms',
			'visibility'=>'private',
			'upload_headers'=>['X-Coverage'=>'yes','bad header'=>'no','X-Array'=>[]],
			'upload_fields'=>['context'=>'forms',''=>'skip','array'=>[]],
			'upload_csrf_form'=>'coverage_upload',
			'upload_csrf_field'=>'token',
			'upload_csrf_header'=>'X-CSRF-Coverage',
			'upload_labels'=>['browse'=>'Choose files','status_empty'=>'Nothing queued',''=>'skip','bad'=>[]],
		],
	]);
	$uploader=PanelContext::run(['upload_csrf'=>true],static fn()=>$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','attachments',$uploadMeta,[['name'=>'one.pdf','path'=>'one.pdf']]));
	$t->contains('data-dp-panel-uploader="1"',$uploader);
	$t->contains('Choose files',$uploader);
	$t->contains('data-dp-panel-uploader-storage',$uploader);
	$t->contains('data-dp-panel-uploader-headers',$uploader);
	$t->contains('data-dp-panel-uploader-fields',$uploader);
	$t->contains('coverage-csrf-token',$uploader);
	$t->contains('data-dp-panel-uploader-endpoint',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','fallback-upload',dp_panel_renderer_forms_meta('drag_drop_upload',['meta'=>[]]),null));
	$t->contains('/panel/uploads',$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','same-delete',dp_panel_renderer_forms_meta('drag_drop_upload',['meta'=>['upload_endpoint'=>'/panel/uploads']]),null));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('acceptedFileTypesLabel','text/plain'));
	$t->contains('Image',$t->nonPublic(PanelRenderer::class)->invoke('acceptedFileTypesLabel',['image/*','.pdf','text/plain','','image/*']));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderInitialValue',null,false));
	$t->same('[]',$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderInitialValue','',true));
	$t->same('already-json',$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderInitialValue','already-json',false));
	$t->same('[1,2]',$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderInitialValue',[1,2],true));
	$t->same('7',$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderInitialValue',7,false));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('fileCurrentValueHtml',null));
	$t->contains('single.pdf',$t->nonPublic(PanelRenderer::class)->invoke('fileCurrentValueHtml',['name'=>'single.pdf']));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('fileCurrentValueHtml',[['name'=>[]],[]]));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderHeaders',['upload_headers'=>['X-A'=>1,'bad name'=>2,'X-B'=>false]])));
	$t->same(['a'=>'1'],$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderFields',['upload_fields'=>['a'=>1,''=>2,'bad'=>[]]]));
	$labels=$t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderLabels',['upload_labels'=>['browse'=>'Custom','bad'=>[]]]);
	$t->same('Custom',$labels['browse']);
	$t->same(true,count($t->nonPublic(PanelRenderer::class)->invoke('customFileUploaderClientLabels',$labels))>20);

	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('formEncodingAttr',[['type'=>'text']]));
	$t->same(' enctype="multipart/form-data"',$t->nonPublic(PanelRenderer::class)->invoke('formEncodingAttr',[['type'=>'file']]));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('formHasFileField',[
		'not-an-array',
		['type'=>'builder','builder_blocks'=>[['fields'=>[['type'=>'file']]]]],
	]));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('formHasFileField',[['type'=>'repeater','repeater_fields'=>[['type'=>'image']]]]));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('formHasFileField',[['type'=>'group','child_fields'=>[['type'=>'upload']]]]));
	$t->same(false,$t->nonPublic(PanelRenderer::class)->invoke('formHasFileField',[['type'=>'group','child_fields'=>[['type'=>'text']]]]));
	foreach(['file','file_upload','upload','drag_drop_upload','image'] as $type){
		$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('isFileFieldType',$type));
	}
	$t->same(false,$t->nonPublic(PanelRenderer::class)->invoke('isFileFieldType','text'));

	$t->contains('No repeater fields',$t->nonPublic(PanelRenderer::class)->invoke('repeaterControl','items',dp_panel_renderer_forms_meta('repeater'),'bad'));
	$t->contains('No builder blocks',$t->nonPublic(PanelRenderer::class)->invoke('builderControl','blocks',dp_panel_renderer_forms_meta('builder'),'bad'));
	$t->contains('No grouped fields',$t->nonPublic(PanelRenderer::class)->invoke('fieldGroupControl','group',dp_panel_renderer_forms_meta('group'),'bad'));
	$t->contains('data-dp-panel-repeater-next="1"',$t->nonPublic(PanelRenderer::class)->invoke('builderControl','blocks',dp_panel_renderer_forms_meta('builder',['meta'=>[
		'min_items'=>1,'builder_blocks'=>[['name'=>'text','fields'=>[]]],
	]]),[]));

	$children=[
		['name'=>'title','type'=>'text','label'=>'Title','help'=>'Help','default'=>'Default','meta'=>['hint'=>'Hint','hint_icon'=>'info']],
		['name'=>'display','type'=>'display','label'=>'Display','meta'=>['display_content'=>'Read only']],
		'bad',
		['name'=>'','type'=>'text'],
	];
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('childFieldMetas',['child_fields'=>$children])));
	$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('repeaterFieldMetas',['repeater_fields'=>$children])));
	$t->contains('disabled',$t->nonPublic(PanelRenderer::class)->invoke('repeaterRowHtml','items',$t->nonPublic(PanelRenderer::class)->invoke('repeaterFieldMetas',['repeater_fields'=>$children]),[],'__INDEX__',true));
	$blocks=$t->nonPublic(PanelRenderer::class)->invoke('builderBlockMetas',['builder_blocks'=>[
		'bad',
		['name'=>'','label'=>'Bad'],
		['name'=>'hero','label'=>'Hero','fields'=>$children],
	]]);
	$t->same(['hero'],array_keys($blocks));
	$t->contains('disabled',$t->nonPublic(PanelRenderer::class)->invoke('builderRowHtml','blocks',$blocks['hero'],[],'__INDEX__',true));
	$address=$t->nonPublic(PanelRenderer::class)->invoke('fieldGroupControl','address',dp_panel_renderer_forms_meta('address',[
		'label'=>'Address','meta'=>['child_fields'=>$children,'description'=>'Where','address_country'=>'CA'],
	]),['title'=>'Main']);
	$t->contains('data-dp-panel-address-country="CA"',$address);

	$t->same(['value'=>'x','label'=>'X','description'=>'Help','disabled'=>true],$t->nonPublic(PanelRenderer::class)->invoke('optionMeta','ignored',['value'=>'x','label'=>'X','help'=>'Help','disabled'=>true],false,false));
	$t->same('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('optionMeta',0,'Alpha',false,true)['value']);
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('isOptionGroup',['a'=>'A','b'=>'B']));
	$t->contains('disabled',$t->nonPublic(PanelRenderer::class)->invoke('optionHtml',['group'=>[
		'label'=>'Disabled','disabled'=>true,'description'=>'Unavailable','options'=>['x'=>'X'],
	]],'x'));
	$t->contains('data-description="Unavailable"',$t->nonPublic(PanelRenderer::class)->invoke('optionHtml',['group'=>[
		'label'=>'Disabled','disabled'=>true,'description'=>'Unavailable','options'=>['x'=>'X'],
	]],'x'));
	$t->contains('data-dp-panel-choice-inline',$t->nonPublic(PanelRenderer::class)->invoke('choiceControl','many',dp_panel_renderer_forms_meta('checkbox_list',[
		'options'=>['a'=>'A'],'readonly'=>true,'meta'=>['inline_choices'=>true],
	]),['a'],true));

	$field=Field::make('status')->label('Status')->optionsUsing(static fn(): array=>['draft'=>'Draft']);
	$t->same(['draft'=>'Draft'],$t->nonPublic(PanelRenderer::class)->invoke('fieldMeta',$field,null,dp_panel_renderer_forms_request(),'create')['options']);
	$dependencyMeta=dp_panel_renderer_forms_meta('toggle',[
		'name'=>'enabled','required'=>true,'help'=>'Required help','depends_on'=>['country'],
		'visible_when'=>['country'=>'CA'],'hidden_when'=>['archived'=>true],
		'required_when'=>['kind'=>'special'],'required_unless'=>['optional'=>true],
		'dynamic_options'=>true,'state_updates'=>true,'live'=>true,'reactive'=>true,'debounce_ms'=>9000,
		'meta'=>[
			'column_span'=>15,
			'accessibility'=>[
				'min_usable_width'=>20,'min_usable_width_unit'=>'ch','min_usable_chars'=>5,'min_touch_target'=>44,
				'max_adornment_ratio'=>9,'max_label_ratio'=>9,'contrast_policy'=>['min_ratio'=>7,'scope'=>'bad'],
			],
			'hint'=>'Toggle this','hint_icon'=>'info',
		],
	]);
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('fieldDependencyControlled',$dependencyMeta));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('fieldDependencyAttrs',[]));
	$depAttrs=$t->nonPublic(PanelRenderer::class)->invoke('fieldDependencyAttrs',$dependencyMeta);
	$t->contains('data-dp-panel-dynamic-options',$depAttrs);
	$t->contains('data-dp-panel-debounce-ms="5000"',$depAttrs);
	$fieldHtml=$t->nonPublic(PanelRenderer::class)->invoke('fieldHtml','enabled',$dependencyMeta,true,['First error','Second error'],true);
	$t->contains('dp-panel-field-hidden',$fieldHtml);
	$t->contains('dp-panel-field-span-12',$fieldHtml);
	$t->notContains('dp-panel-grid-item-auto',$fieldHtml);
	$t->contains('First error',$fieldHtml);
	$t->contains('data-dp-panel-a11y-policy',$fieldHtml);
	$automaticBoolean=$t->nonPublic(PanelRenderer::class)->invoke('fieldHtml','automatic',dp_panel_renderer_forms_meta('toggle'),false);
	$t->contains('<div class="dp-panel-field dp-panel-grid-item-auto dp-panel-field-boolean',$automaticBoolean);
	$t->contains('<label class="dp-panel-checkbox dp-panel-switch"',$automaticBoolean);
	$t->notContains('<label class="dp-panel-field',$automaticBoolean);
	$t->contains('type="hidden"',$t->nonPublic(PanelRenderer::class)->invoke('fieldHtml','secret',dp_panel_renderer_forms_meta('hidden'),'x'));
	$t->contains('dp-panel-field-display',$t->nonPublic(PanelRenderer::class)->invoke('fieldHtml','display',dp_panel_renderer_forms_meta('display'),'shown'));
	$t->contains('dp-panel-field-full',$t->nonPublic(PanelRenderer::class)->invoke('fieldHtml','wide',dp_panel_renderer_forms_meta('text',['meta'=>['column_span'=>'full']]),'shown'));
	$t->contains('data-dp-panel-a11y-disabled',$t->nonPublic(PanelRenderer::class)->invoke('fieldAccessibilityAttrs',dp_panel_renderer_forms_meta('text',['meta'=>['accessibility_inherit'=>false]])));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('fieldAccessibilityAttrs',dp_panel_renderer_forms_meta()));
	$t->contains('data-dp-panel-a11y-default',$t->nonPublic(PanelRenderer::class)->invoke('accessibilityDefaultAttrs',['accessibility'=>['min_usable_width'=>30]]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('accessibilityDefaultAttrs',[]));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationshipAttributeHtml',dp_panel_renderer_forms_meta('text')));
	$t->contains('related-resource="users"',$t->nonPublic(PanelRenderer::class)->invoke('relationshipAttributeHtml',dp_panel_renderer_forms_meta('relationship',['meta'=>[
		'related_resource'=>'users','title_attribute'=>'name','key_attribute'=>'id',
	]])));
	$t->same('<select></select>',$t->nonPublic(PanelRenderer::class)->invoke('searchableSelectShell','small',dp_panel_renderer_forms_meta('relationship',['meta'=>['searchable'=>true,'search_threshold'=>12]]),'<select></select>',false,2));
	$t->same('<select></select>',$t->nonPublic(PanelRenderer::class)->invoke('searchableSelectShell','plain',dp_panel_renderer_forms_meta('select'),'<select></select>'));
	$t->contains('searchable-select-multiple',$t->nonPublic(PanelRenderer::class)->invoke('searchableSelectShell','many',dp_panel_renderer_forms_meta('relationship',['meta'=>[
		'searchable'=>true,'force_search'=>true,'search_placeholder'=>'Find','no_results_text'=>'Nope',
	]]),'<select></select>',true,1));
	$t->contains('field-hint-icon',$t->nonPublic(PanelRenderer::class)->invoke('fieldLabelHtml',['meta'=>['hint'=>'Hint','hint_icon'=>'info']],'Label'));
});

test('panel renderer forms covers reactive field endpoints and condition semantics',static function(Context $t): void {
	$resource=Resource::make('coverage-forms')->fields([
		Field::make('country')->label('Country')->required()->options(['CA'=>'Canada','US'=>'United States']),
		Field::make('province')->label('Province')->optionsUsing(static function(mixed $record,?PanelRequest $request): array {
			return $request?->input('country')==='US' ? ['NY'=>'New York'] : ['QC'=>'Quebec','ON'=>'Ontario'];
		})->dependsOn('country')->requiredWhen('country','CA')->stateUsing(static fn(): array=>[
			'value'=>'QC',
			'options'=>['QC'=>'Québec'],
			'visible'=>true,
			'required'=>true,
			'readonly'=>true,
			'help'=>'Server help',
			'placeholder'=>'Server placeholder',
			'errors'=>['Computed problem',''],
			'force_value'=>true,
			'propagate'=>true,
		]),
		Field::make('note')->label('Note')->requiredUnless('country','US')->validateUsing(static fn(mixed $value): string=>$value==='' ? 'Note required' : ''),
	]);
	$missing=PanelRenderer::fieldOptions($resource,dp_panel_renderer_forms_request('GET',[],['__panel_field'=>'missing']));
	$t->same(404,$missing->status());
	$optionResult=PanelRenderer::fieldOptions($resource,dp_panel_renderer_forms_request('POST',[
		'__panel_field'=>'province','country'=>'CA','province'=>'QC',
	],[],[], 'store'),['province'=>'ON']);
	$t->same(200,$optionResult->status());
	$optionPayload=dp_panel_renderer_forms_json($optionResult);
	$t->same('province',$optionPayload['field']);
	$t->same('create',$optionPayload['operation']);
	$t->same(true,$optionPayload['required']);
	$t->contains('Quebec',$optionPayload['options_html']);

	$state=PanelRenderer::fieldState($resource,dp_panel_renderer_forms_request('POST',[
		'__panel_validate'=>'field','__panel_validate_field'=>'note','country'=>'CA','province'=>'','note'=>'',
	],[],[], 'create'));
	$statePayload=dp_panel_renderer_forms_json($state);
	$t->same(200,$state->status());
	$t->same(true,$statePayload['validated'],'field state validated');
	$t->same(['note'],$statePayload['validated_fields']);
	$t->same(false,$statePayload['valid']);
	$t->same(true,$statePayload['fields']['province']['force_value'],'province force value');
	$t->same(true,$statePayload['fields']['province']['propagate'],'province propagate');
	$t->same('Server help',$statePayload['fields']['province']['help']);
	$t->same(true,$statePayload['fields']['province']['readonly'],'province readonly');
	$t->same(['Computed problem'],$statePayload['fields']['province']['errors']);

	$all=PanelRenderer::fieldState($resource,dp_panel_renderer_forms_request('POST',[
		'__panel_validate'=>'all','country'=>'US','province'=>'NY','note'=>'ok',
	],[],[], 'update'));
	$allPayload=dp_panel_renderer_forms_json($all);
	$t->same('edit',$allPayload['operation']);
	$t->same(false,$allPayload['valid'],'all field state includes computed error');
	$t->same(3,count($allPayload['validated_fields']));

	$lifecycle=Resource::make('lifecycle')->fields([Field::make('name')->required()])
		->beforeValidateUsing(static fn()=>\Dataphyre\Panel\PanelLifecycleResult::halt('Stopped',[],422));
	$lifecycleState=PanelRenderer::fieldState($lifecycle,dp_panel_renderer_forms_request('POST',[
		'__panel_validate'=>'true','name'=>'',
	]));
	$lifecyclePayload=dp_panel_renderer_forms_json($lifecycleState);
	$t->same(false,$lifecyclePayload['validated']);
	$t->same('Stopped',$lifecyclePayload['lifecycle']['message']);

	$afterLifecycle=Resource::make('after-lifecycle')->fields([Field::make('name')])
		->afterValidateUsing(static fn()=>\Dataphyre\Panel\PanelLifecycleResult::notify('After validation'));
	$afterState=PanelRenderer::fieldState($afterLifecycle,dp_panel_renderer_forms_request('POST',[
		'__panel_validate'=>'1','name'=>'ok',
	]));
	$afterPayload=dp_panel_renderer_forms_json($afterState);
	$t->same(false,$afterPayload['validated']);
	$t->same('After validation',$afterPayload['lifecycle']['message']);

	$plain=PanelRenderer::fieldState($resource,dp_panel_renderer_forms_request('GET',[],[]));
	$plainPayload=dp_panel_renderer_forms_json($plain);
	$t->same(false,$plainPayload['validated']);
	$t->same(null,$plainPayload['valid']);
	$blankPayload=dp_panel_renderer_forms_json(PanelRenderer::fieldState(
		Resource::make('blank-field')->field(Field::make('')),
		dp_panel_renderer_forms_request('GET')
	));
	$t->same([],$blankPayload['fields']);

	$conditionRequest=dp_panel_renderer_forms_request('POST',['status'=>'open','active'=>'yes','kind'=>'staff']);
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('fieldRequiredForState',['required'=>true],$conditionRequest));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('fieldRequiredForState',['required_when'=>['status'=>'open']],$conditionRequest));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('fieldRequiredForState',['required_unless'=>['kind'=>'guest']],$conditionRequest));
	$t->same(false,$t->nonPublic(PanelRenderer::class)->invoke('fieldRequiredForState',['required_when'=>['status'=>'closed']],$conditionRequest));
	$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('formConditionsMatch',['status'=>['open','pending'],'active'=>true],$conditionRequest,false));
	$t->same(false,$t->nonPublic(PanelRenderer::class)->invoke('formConditionsMatch',['status'=>'open'],$conditionRequest,true));
	foreach([
		['x',['x','y'],true],['yes',true,true],['no',false,true],['x','x',true],['x','y',false],
	] as [$actual,$expected,$match]){
		$t->same($match,$t->nonPublic(PanelRenderer::class)->invoke('formConditionMatches',$actual,$expected));
	}

	$t->same('bulk_update',$t->nonPublic(PanelRenderer::class)->invoke('reactiveForm',$resource->bulkField(Field::make('status')),dp_panel_renderer_forms_request('POST',[],[],[],'bulk_update'),)[1]);
	$action=\Dataphyre\Panel\Action::make('approve')->field(Field::make('reason'));
	$actionResource=$resource->action($action);
	$t->same('action',$t->nonPublic(PanelRenderer::class)->invoke('reactiveForm',$actionResource,PanelRequest::fromArray(['operation'=>'action','action'=>'approve']),)[1]);
	$t->same('action',$t->nonPublic(PanelRenderer::class)->invoke('reactiveForm',$actionResource,PanelRequest::fromArray(['operation'=>'action','action'=>'missing']),)[1]);
});

test('panel renderer forms covers sections responsive grids show values and entries',static function(Context $t): void {
	$t->contains('No fields are defined',$t->nonPublic(PanelRenderer::class)->invoke('formSectionsHtml',[]));
	$t->contains('No visible fields',$t->nonPublic(PanelRenderer::class)->invoke('showSectionsHtml',[]));
	$sections=[
		'Details'=>['<label>Detail</label>'],
		'Contact'=>['<label>Contact</label>'],
		'Billing'=>['<label>Billing</label>'],
		'Confirm'=>['<label>Confirm</label>'],
	];
	$sectionMeta=$t->nonPublic(PanelRenderer::class)->invoke('sectionMetaByName',['bad',
		['name'=>'details','label'=>'Details','description'=>'Main details','columns'=>2,'layout'=>'wide','collapsible'=>true,'collapsed'=>true,'meta'=>['grid_columns'=>['base'=>1,'md'=>2],'accessibility'=>['min_touch_target'=>44]]],
		['name'=>'contact','label'=>'Contact','tab'=>'General'],
		['name'=>'billing','label'=>'Billing','tab'=>'Finance'],
		['name'=>'confirm','label'=>'Confirm','step'=>'Review'],
		['name'=>'','label'=>''],
	]);
	$formHtml=$t->nonPublic(PanelRenderer::class)->invoke('formSectionsHtml',$sections,['default'=>1,'medium'=>2,'wide'=>4],$sectionMeta);
	$t->contains('dp-panel-tabs',$formHtml);
	$t->contains('dp-panel-steps',$formHtml);
	$t->contains('<details',$formHtml);
	$showHtml=$t->nonPublic(PanelRenderer::class)->invoke('showSectionsHtml',$sections,3,$sectionMeta);
	$t->contains('dp-panel-show',$showHtml);
	$t->contains('dp-panel-tabs',$showHtml);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('tabsHtml',[],$sectionMeta,1));
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('stepsHtml',[],$sectionMeta,1));
	$t->contains('data-dp-panel-step-prev',$t->nonPublic(PanelRenderer::class)->invoke('stepsHtml',[
		'One'=>['Details'=>['a']], 'Two'=>['Contact'=>['b']],
	],$sectionMeta,2,false));
	$t->same(false,str_contains($t->nonPublic(PanelRenderer::class)->invoke('stepsHtml',[
		'One'=>['Details'=>['a']], 'Two'=>['Contact'=>['b']],
	],$sectionMeta,2,true),'data-dp-panel-step-prev'));
	$t->contains('dp-panel-show',$t->nonPublic(PanelRenderer::class)->invoke('sectionBlockHtml','Only',['x'],[],1,true,true));
	$t->contains('dp-panel-form-grid',$t->nonPublic(PanelRenderer::class)->invoke('sectionBlockHtml','Only',['x'],[],1,false,true));
	$t->contains('<p>Description</p>',$t->nonPublic(PanelRenderer::class)->invoke('sectionHeadingHtml','Fallback',['label'=>'Heading','description'=>'Description']));
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('sectionMeta',$sectionMeta,'missing'));
	$t->same(4,$t->nonPublic(PanelRenderer::class)->invoke('formColumnsDefinition',['columns'=>4]));
	$t->same(['base'=>1,'md'=>3],$t->nonPublic(PanelRenderer::class)->invoke('formColumnsDefinition',['columns'=>4,'meta'=>['grid_columns'=>['base'=>1,'md'=>3]]]));
	$t->same(['sm'=>2],$t->nonPublic(PanelRenderer::class)->invoke('sectionGridColumns',['grid_columns'=>['sm'=>2]],1));
	$t->same(['md'=>3],$t->nonPublic(PanelRenderer::class)->invoke('sectionGridColumns',['meta'=>['grid_columns'=>['md'=>3]]],1));
	$t->same(['default'=>12],$t->nonPublic(PanelRenderer::class)->invoke('normalizeGridColumns',99));
	$t->same(['default'=>1,'sm'=>2,'md'=>12,'2xl'=>4],$t->nonPublic(PanelRenderer::class)->invoke('normalizeGridColumns',['base'=>0,'small'=>2,'medium'=>99,'wide'=>4,'bad'=>2]));
	$t->same(['default'=>1],$t->nonPublic(PanelRenderer::class)->invoke('normalizeGridColumns',['bad'=>2]));
	$gridColumnsStyle=$t->nonPublic(PanelRenderer::class)->invoke('gridColumnsStyle',['default'=>1,'sm'=>6,'md'=>9,'xl'=>12]);
	$t->contains('--dp-grid-cols-md:9',$gridColumnsStyle);
	$t->contains('--dp-grid-auto-span:1',$gridColumnsStyle);
	$t->contains('--dp-grid-auto-span-sm:2',$gridColumnsStyle);
	$t->contains('--dp-grid-auto-span-md:3',$gridColumnsStyle);
	$t->contains('--dp-grid-auto-span-xl:3',$gridColumnsStyle);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('gridItemStyle',[]));
	$gridStyle=$t->nonPublic(PanelRenderer::class)->invoke('gridItemStyle',['column_span'=>['base'=>'full','md'=>3],'column_start'=>['base'=>2,'md'=>4],'row_span'=>['lg'=>2]]);
	$t->contains('--dp-grid-column:2 / -1',$gridStyle);
	$t->contains('--dp-grid-column-md:4 / span 3',$gridStyle);
	$t->contains('--dp-grid-row-lg:auto / span 2',$gridStyle);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('gridValueMap',null));
	$t->same(['default'=>3],$t->nonPublic(PanelRenderer::class)->invoke('gridValueMap',3));
	$t->same(['default'=>'full','md'=>2],$t->nonPublic(PanelRenderer::class)->invoke('gridValueMap',['initial'=>'full','medium'=>2,'bad'=>4]));
	$t->same('1 / -1',$t->nonPublic(PanelRenderer::class)->invoke('gridColumnValue','full',null));
	$t->same('2 / -1',$t->nonPublic(PanelRenderer::class)->invoke('gridColumnValue','full',2));
	$t->same('auto / span 3',$t->nonPublic(PanelRenderer::class)->invoke('gridColumnValue',3,null));
	$t->same('4 / span 3',$t->nonPublic(PanelRenderer::class)->invoke('gridColumnValue',3,4));
	foreach([''=>'default','base'=>'default','small'=>'sm','medium'=>'md','large'=>'lg','xl'=>'xl','xxl'=>'2xl','bad'=>''] as $raw=>$normalized){
		$t->same($normalized,$t->nonPublic(PanelRenderer::class)->invoke('gridBreakpoint',$raw));
	}
	$t->same(99,$t->nonPublic(PanelRenderer::class)->invoke('gridBreakpointOrder','bad'));

	$plain=Field::make('plain');
	$displaying=Field::make('custom')->displayUsing(static fn(): string=>'Formatted');
	$t->same('Formatted',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$displaying,dp_panel_renderer_forms_meta('text'),'raw'));
	$t->same('Empty',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,dp_panel_renderer_forms_meta('text',['meta'=>['empty'=>'Empty']]),null));
	$t->same('Yes',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,dp_panel_renderer_forms_meta('toggle'),true));
	$t->same('single.pdf',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,dp_panel_renderer_forms_meta('file'),['name'=>'single.pdf']));
	$t->same('one.pdf, two.pdf',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,dp_panel_renderer_forms_meta('file'),[['name'=>'one.pdf'],'two.pdf',[]]));
	$t->same('Uploaded file',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,dp_panel_renderer_forms_meta('file'),[[]]));
	$t->same('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,dp_panel_renderer_forms_meta('select',['options'=>['a'=>'Alpha']]),'a'));
	$repeaterMeta=dp_panel_renderer_forms_meta('repeater',['meta'=>['repeater_fields'=>[['name'=>'name','label'=>'Name']],'empty'=>'None']]);
	$t->contains('#1 Name: Ada',$t->nonPublic(PanelRenderer::class)->invoke('displayFieldValue',$plain,$repeaterMeta,[['name'=>'Ada'],'bad',[]]));
	$t->same('None',$t->nonPublic(PanelRenderer::class)->invoke('repeaterDisplayValue',[],$repeaterMeta));

	foreach([
		['Badge','open',dp_panel_renderer_forms_meta('badge',['meta'=>['prefix'=>'[','suffix'=>']','tone'=>'bad','tones'=>['open'=>'success']]]),'dp-panel-badge-success'],
		['Yes',true,dp_panel_renderer_forms_meta('toggle'),'dp-panel-badge-success'],
		['Mail','test@example.com',dp_panel_renderer_forms_meta('email'),'mailto:'],
		['Bad','not-email',dp_panel_renderer_forms_meta('email'),'<strong>Bad</strong>'],
		['Web','https://example.com',dp_panel_renderer_forms_meta('url'),'href="https://example.com"'],
		['Bad','javascript:bad',dp_panel_renderer_forms_meta('url'),'<strong>Bad</strong>'],
		['Image','/image.png',dp_panel_renderer_forms_meta('image'),'<img'],
		['Bad','javascript:bad',dp_panel_renderer_forms_meta('image'),'<strong>Bad</strong>'],
		["One\n\nTwo",[],dp_panel_renderer_forms_meta('repeater'),'<ul'],
		['<em>Raw</em>','x',dp_panel_renderer_forms_meta('text',['meta'=>['html'=>true]]),'<em>Raw</em>'],
	] as [$display,$raw,$meta,$needle]){
		$t->contains($needle,$t->nonPublic(PanelRenderer::class)->invoke('entryValueHtml',$display,$raw,$meta));
	}
	$t->same('neutral',$t->nonPublic(PanelRenderer::class)->invoke('entryTone','x',['tone'=>'unsafe']));
	$t->same('warning',$t->nonPublic(PanelRenderer::class)->invoke('entryTone','x',['tones'=>['x'=>'warning']]));
	$t->same('UI',$t->nonPublic(PanelRenderer::class)->invoke('entryIconText','user-info','Fallback'));
	$t->same('FL',$t->nonPublic(PanelRenderer::class)->invoke('entryIconText','','Fallback Label'));
	$t->same('Alpha',$t->nonPublic(PanelRenderer::class)->invoke('optionLabel',['a'=>'Alpha'],'a'));
	$t->same('Beta',$t->nonPublic(PanelRenderer::class)->invoke('optionLabel',[['value'=>'b','label'=>'Beta']],'b'));
	$t->same('Gamma',$t->nonPublic(PanelRenderer::class)->invoke('optionLabel',['group'=>['options'=>['c'=>'Gamma']]],'c'));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('optionLabel',['a'=>'Alpha'],'missing'));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('optionLabel',['group'=>['options'=>['a'=>'Alpha']]],'missing'));
	$t->same('Beta',$t->nonPublic(PanelRenderer::class)->invoke('optionLabel',['Alpha','Beta'],'1'));

	$showMeta=dp_panel_renderer_forms_meta('text',['label'=>'Title','meta'=>[
		'column_span'=>'full','column_start'=>2,'row_span'=>2,'copyable'=>true,'icon'=>'info','description'=>'Description',
	]]);
	$copyableShow=$t->nonPublic(PanelRenderer::class)->invoke('showFieldHtml',$plain,$showMeta,'Value');
	$t->contains('dp-panel-show-field-copyable',$copyableShow);
	$t->contains('</header><button type="button" class="dp-panel-entry-copy"',$copyableShow);
	$t->contains('dp-panel-field-span-3',$t->nonPublic(PanelRenderer::class)->invoke('showFieldHtml',$plain,dp_panel_renderer_forms_meta('text',['meta'=>['column_span'=>3]]),'Value'));
	$automaticShow=$t->nonPublic(PanelRenderer::class)->invoke('showFieldHtml',$plain,dp_panel_renderer_forms_meta('text'),'');
	$t->contains('dp-panel-grid-item-auto',$automaticShow);
	$t->same(false,str_contains($automaticShow,'data-dp-panel-copy-entry'));
	$copyableEntry=$t->nonPublic(PanelRenderer::class)->invoke('showEntryHtml',[
		'name'=>'entry','label'=>'Entry','display'=>'Value','raw'=>'Value','copyable'=>true,
		'meta'=>['column_span'=>99,'icon'=>'entry','description'=>'Description'],
		'field'=>dp_panel_renderer_forms_meta('text'),
	]);
	$t->contains('dp-panel-field-span-12',$copyableEntry);
	$t->contains('</header><button type="button" class="dp-panel-entry-copy"',$copyableEntry);
	$t->contains('dp-panel-field-full',$t->nonPublic(PanelRenderer::class)->invoke('showEntryHtml',[
		'label'=>'Full','display'=>'','copyable'=>true,'meta'=>['column_span'=>'full'],'field'=>dp_panel_renderer_forms_meta('text'),
	]));
	$t->contains('dp-panel-grid-item-auto',$t->nonPublic(PanelRenderer::class)->invoke('showEntryHtml',[
		'label'=>'Automatic','display'=>'Value','field'=>dp_panel_renderer_forms_meta('text'),
	]));
});

test('panel renderer forms covers attachment outcomes effects truthiness and notifications',static function(Context $t): void {
	$session=$t->globalMap('_SESSION');
	foreach([[true,'1'],[false,'0'],[null,''],[12,'12'],[['a'=>1],'{"a":1}'],[fopen('php://memory','r'),'']] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(PanelRenderer::class)->invoke('stringValue',$value));
	}
	foreach([['unknown','unknown'],[0,'0 B'],[1024,'1.0 KB'],[10*1024,'10 KB'],[1024**4,'1.0 TB'],[-5,'0 B']] as [$bytes,$expected]){
		$t->same($expected,$t->nonPublic(PanelRenderer::class)->invoke('formatBytes',$bytes));
	}
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('uploadedAttachmentFile',dp_panel_renderer_forms_request('POST')));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('uploadedAttachmentFile',dp_panel_renderer_forms_request('POST',[],[],[
		'attachment'=>['name'=>'','error'=>UPLOAD_ERR_OK],
	])));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('uploadedAttachmentFile',dp_panel_renderer_forms_request('POST',[],[],[
		'attachment'=>['name'=>'bad.txt','error'=>UPLOAD_ERR_NO_FILE],
	])));
	$file=['name'=>'ok.txt','type'=>'text/plain','size'=>5,'tmp_name'=>'C:\\tmp\\nested\\file.tmp','error'=>UPLOAD_ERR_OK];
	$t->same($file,$t->nonPublic(PanelRenderer::class)->invoke('uploadedAttachmentFile',dp_panel_renderer_forms_request('POST',[],[],['attachment'=>$file])));
	$summary=$t->nonPublic(PanelRenderer::class)->invoke('attachmentFileSummary',$file);
	$t->same('file.tmp',$summary['tmp_name']);
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('attachmentFileSummary',[])['tmp_name']);
	foreach(['boolean','bool','checkbox','toggle'] as $type){
		$t->same(true,$t->nonPublic(PanelRenderer::class)->invoke('isBooleanType',$type));
	}
	$t->same(false,$t->nonPublic(PanelRenderer::class)->invoke('isBooleanType','text'),'text is not boolean');
	foreach([[true,true],[false,false],[1,true],[0,false],[1.5,true],[0.0,false],['YES',true],['off',false],[null,false],[[],true]] as [$value,$truthy]){
		$t->same($truthy,$t->nonPublic(PanelRenderer::class)->invoke('truthy',$value),'truthy '.json_encode($value));
	}

	$notification=PanelNotification::warning('Watch out','Warning');
	$notificationOutcome=$t->nonPublic(PanelRenderer::class)->invoke('outcome',$notification,'Fallback');
	$t->same('Watch out',$notificationOutcome['message']);
	$t->same(1,count($notificationOutcome['notifications']));
	$stringOutcome=$t->nonPublic(PanelRenderer::class)->invoke('outcome',' Saved ','Fallback');
	$t->same('Saved',$stringOutcome['message']);
	$arrayOutcome=$t->nonPublic(PanelRenderer::class)->invoke('outcome',['message'=>'Done','notification'=>'One','notifications'=>[
		PanelNotification::info('Two'),['message'=>'Three','type'=>'success'],12,
	],'redirect'=>'javascript:bad','status'=>999,'effects'=>[
		'close_modal'=>true,'refresh'=>'table, record table','events'=>[
			'saved',['name'=>'updated','detail'=>['id'=>1]],['event'=>'loaded','data'=>['ok'=>true]],['name'=>''],12,
		],
	]],'Fallback');
	$t->same('Done',$arrayOutcome['message']);
	$t->same(null,$arrayOutcome['redirect']);
	$t->same(399,$arrayOutcome['status']);
	$t->same(3,count($arrayOutcome['notifications']),'array outcome notifications');
	$t->same(['table','record'],$arrayOutcome['effects']['refresh']);
	$t->same(3,count($arrayOutcome['effects']['events']),'array outcome events');
	$redirectOutcome=$t->nonPublic(PanelRenderer::class)->invoke('outcome',['redirect_to'=>'/panel/back','status'=>200,'action_effects'=>['event'=>'done']],'Fallback');
	$t->same('/panel/back',$redirectOutcome['redirect']);
	$t->same(300,$redirectOutcome['status']);
	$scalarOutcome=$t->nonPublic(PanelRenderer::class)->invoke('outcome',12,'Fallback');
	$t->same(12,$scalarOutcome['result']);
	$t->same('Fallback',$scalarOutcome['message']);

	$metaEffects=$t->nonPublic(PanelRenderer::class)->invoke('actionEffects',['meta'=>['effects'=>['refresh'=>['table']]]],['effects'=>[
		'close_modal'=>false,'refresh'=>['record','table'],'event'=>['name'=>'saved'],
	]]);
	$t->same(false,$metaEffects['close_modal'],'merged close modal false');
	$t->same(['table','record'],$metaEffects['refresh']);
	$t->same('saved',$metaEffects['events'][0]['name']);
	$directEffects=$t->nonPublic(PanelRenderer::class)->invoke('actionEffects',['effects'=>['close_modal'=>true]],[]);
	$t->same(true,$directEffects['close_modal']);
	$t->same(
		['modal_navigation'=>'close','close_modal'=>true],
		$t->nonPublic(PanelRenderer::class)->invoke('actionEffects',['effects'=>['modal_navigation'=>'back']],['effects'=>['close_modal'=>true]]),
		'newer legacy close overrides metadata back without contradictory effects'
	);
	$t->same(
		['modal_navigation'=>'stay','close_modal'=>false],
		$t->nonPublic(PanelRenderer::class)->invoke('actionEffects',['effects'=>['modal_navigation'=>'close']],['effects'=>['close_modal'=>false]]),
		'newer legacy stay overrides metadata close without contradictory effects'
	);
	$t->same(
		['modal_navigation'=>'back','close_modal'=>false],
		$t->nonPublic(PanelRenderer::class)->invoke('normalizeActionEffects',['close_modal'=>true,'modal_navigation'=>'back']),
		'explicit modal navigation is authoritative over its legacy mirror'
	);
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('normalizeActionEffects','bad'));
	$t->same(['a','b:c','d_e','d-e'],$t->nonPublic(PanelRenderer::class)->invoke('normalizeActionEffectTargets',[' A ','B:C','d e','d-e','',[]]));
	$t->same(['a','b'],$t->nonPublic(PanelRenderer::class)->invoke('normalizeActionEffectTargets','A, B A'));

	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('notificationArray',12));
	$t->same(null,$t->nonPublic(PanelRenderer::class)->invoke('notificationArray',''));
	$t->same('Object',$t->nonPublic(PanelRenderer::class)->invoke('notificationArray',PanelNotification::success('Object'))['message']);
	$t->same('String',$t->nonPublic(PanelRenderer::class)->invoke('notificationArray','String')['message']);
	$t->same('Array',$t->nonPublic(PanelRenderer::class)->invoke('notificationArray',['message'=>'Array','type'=>'danger'])['message']);
	$list=$t->nonPublic(PanelRenderer::class)->invoke('notificationList',[
		PanelNotification::success('Good'),'',12,['title'=>'Title only','message'=>''],['message'=>'Array','type'=>'error'],
	]);
	$t->same(2,count($list),'notification list');
	$html=$t->nonPublic(PanelRenderer::class)->invoke('notificationsHtml',[
		['message'=>'Error','type'=>'error','title'=>'Oops','action_label'=>'Retry','action_url'=>'/retry'],
		['message'=>'Neutral','type'=>'unsafe'],
		['message'=>'Unsafe URL','type'=>'success','action_label'=>'Bad','action_url'=>'javascript:bad'],
	]);
	$t->contains('dp-panel-notice-error',$html);
	$t->contains('href="/retry"',$html);
	$t->contains('dp-panel-notice-info',$html);
	$t->same(false,str_contains($html,'javascript:'),'unsafe notification URL removed');

	$t->nonPublic(PanelRenderer::class)->invoke('flashNotifications',[]);
	if(PHP_SESSION_ACTIVE===session_status()){
		session_write_close();
	}
	$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('consumeFlashNotifications'));
	if(PHP_SESSION_ACTIVE!==session_status()){
		@session_start();
	}
	if(PHP_SESSION_ACTIVE===session_status()){
		$session->put('dataphyre_panel_flash_notifications','invalid');
		$t->nonPublic(PanelRenderer::class)->invoke('flashNotifications',[
			PanelNotification::success('One'),'Two',['message'=>'Three'],12,
		]);
		$flashed=$t->nonPublic(PanelRenderer::class)->invoke('consumeFlashNotifications');
		$t->same(3,count($flashed),'flashed notification count');
		$session->put('dataphyre_panel_flash_notifications',[['message'=>'One'],'bad',['message'=>'Two']]);
		$t->same(2,count($t->nonPublic(PanelRenderer::class)->invoke('consumeFlashNotifications')));
		$many=array_fill(0,25,'Many');
		$t->nonPublic(PanelRenderer::class)->invoke('flashNotifications',$many);
		$t->same(20,count($t->nonPublic(PanelRenderer::class)->invoke('consumeFlashNotifications')));
	}
	else{
		$t->same([],$t->nonPublic(PanelRenderer::class)->invoke('consumeFlashNotifications'));
	}
});
