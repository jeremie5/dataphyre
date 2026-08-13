<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelLocalization;
use Dataphyre\Panel\PanelLocalizationScope;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_localization_stringable(string $value): Stringable {
	return new class($value) implements Stringable {
		public function __construct(private readonly string $value){}
		public function __toString(): string { return $this->value; }
	};
}

test('panel localization constructs clones mutates and exports deterministic catalogues',static function(Context $t): void {
	$localization=PanelLocalization::make([
		'locale'=>' fr_ca ',
		'fallbackLocale'=>' en_us ',
		'translations'=>[
			'en-US'=>[
				'panel'=>['title'=>'Panel :name','count'=>3,'enabled'=>true,'disabled'=>false,'none'=>null],
				'stringable'=>dp_panel_localization_stringable('Stringable value'),
				'unsupported'=>new stdClass(),
			],
			'fr'=>['panel.title'=>'Panneau {name}'],
			'fr-CA'=>['panel'=>['welcome'=>'Bienvenue {{ name }}']],
			'bad'=>'skip',
		],
		'meta'=>['source'=>'manifest','nested'=>['first'=>1]],
	],null,null,['build'=>'coverage','nested'=>['zero'=>0]]);
	$t->same('fr-CA',$localization->locale());
	$t->same('en-US',$localization->fallbackLocale());
	$t->same('manifest',$localization->meta()['source']);
	$t->same('coverage',$localization->meta()['build']);
	$t->same('true',$localization->translations('en_us')['panel.enabled']);
	$t->same('false',$localization->translations('en-US')['panel.disabled']);
	$t->same('',$localization->translations('en-US')['panel.none']);
	$t->same(false,array_key_exists('unsupported',$localization->translations('en-US')));
	$t->same([],$localization->translations('de'));

	$t->same($localization,PanelLocalization::from($localization));
	$localeClone=PanelLocalization::from($localization,'es_mx');
	$t->same(false,$localeClone===$localization);
	$t->same('es-MX',$localeClone->locale());
	$t->same('fr-CA',$localization->locale());
	$fallbackClone=PanelLocalization::from($localization,null,'de_de');
	$t->same('de-DE',$fallbackClone->fallbackLocale());
	$bothClone=PanelLocalization::from($localization,'pt_br','es_es');
	$t->same('pt-BR',$bothClone->locale());
	$t->same('es-ES',$bothClone->fallbackLocale());
	$t->same('it',PanelLocalization::from(['it'=>['hello'=>'Ciao']],'it')->locale());
	$t->same('en',PanelLocalization::from(null)->locale());

	$t->same($localization,$localization->locale(''));
	$t->same('en',$localization->locale());
	$t->same($localization,$localization->locale('ZH_hant_ca'));
	$t->same('zh-HANT-CA',$localization->locale());
	$t->same($localization,$localization->fallbackLocale(''));
	$t->same('zh-HANT-CA',$localization->fallbackLocale());
	$t->same($localization,$localization->fallbackLocale('EN_gb'));
	$t->same('en-GB',$localization->fallbackLocale());

	$t->same($localization,$localization->catalogue([
		'es'=>['hello'=>'Hola'],
		'de'=>['hello'=>'Hallo'],
		'bad'=>'skip',
	]));
	$t->same('Hola',$localization->translations('es')['hello']);
	$t->same(true,is_array($localization->catalogue()));
	$t->same($localization,$localization->add('', ['ignored'=>'Ignored']));
	$t->same($localization,$localization->add('es', ['unsupported'=>new stdClass()]));
	$t->same(false,array_key_exists('unsupported',$localization->translations('es')));
	$t->same($localization,$localization->add('es-MX',[
		'actions'=>['save'=>'Guardar :resource','delete'=>'Eliminar {resource}'],
		''=>'ignored',
	], 'panel'));
	$t->same('Guardar :resource',$localization->translations('es_mx')['panel.actions.save']);

	$t->same($localization,$localization->set('', 'key', 'ignored'));
	$t->same($localization,$localization->set('es', '', 'ignored'));
	$t->same($localization,$localization->set('es', 'enabled', true, 'flags'));
	$t->same($localization,$localization->set('es', 'disabled', false, 'flags'));
	$t->same($localization,$localization->set('es', 'none', null, 'flags'));
	$t->same($localization,$localization->set('es', 'count', 4, 'flags'));
	$t->same($localization,$localization->set('es', 'ratio', 1.5, 'flags'));
	$t->same($localization,$localization->set('es', 'object', dp_panel_localization_stringable('object'), 'flags'));
	$t->same('true',$localization->translations('es')['flags.enabled']);
	$t->same('false',$localization->translations('es')['flags.disabled']);
	$t->same('',$localization->translations('es')['flags.none']);
	$t->same('object',$localization->translations('es')['flags.object']);

	$t->same($localization,$localization->meta(['source'=>'override','nested'=>['second'=>2]]));
	$t->same('override',$localization->meta()['source']);
	$manifest=$localization->manifest(['surface'=>'test','nested'=>['third'=>3]]);
	$t->same('panel_localization',$manifest['type']);
	$t->same('test',$manifest['meta']['surface']);
	$t->same(3,$manifest['meta']['nested']['third']);
	$array=$localization->toArray();
	$t->same(count($array['catalogue']),$array['counts']['locales']);
	$t->same(array_sum(array_map('count',$array['catalogue'])),$array['counts']['translations']);
	$t->same($array,$localization->jsonSerialize());
	$t->same($array,json_decode((string)json_encode($localization),true));
});

test('panel localization resolves locale chains scopes aliases defaults and interpolation',static function(Context $t): void {
	$localization=PanelLocalization::make([
		'locale'=>'fr-CA',
		'fallback_locale'=>'en-US',
		'catalogue'=>[
			'fr-CA'=>['exact'=>'Exact','empty'=>''],
			'fr'=>['base'=>'Base','shared'=>'Français'],
			'en-US'=>[
				'fallback'=>'Fallback',
				'panel'=>['save'=>'Save :resource','complex'=>'{count} {{item}} {{ name }} :data :zero'],
			],
			'en'=>['fallback_base'=>'Fallback base','shared'=>'English'],
		],
	]);
	$t->same(true,$localization->has('exact'));
	$t->same(true,$localization->has('base'));
	$t->same(true,$localization->has('fallback'));
	$t->same(true,$localization->has('fallback_base'));
	$t->same(false,$localization->has('missing'));
	$t->same(false,$localization->has(''));
	$t->same(true,$localization->has('save',null,'panel'));
	$t->same('Exact',$localization->translate('exact'));
	$t->same('Base',$localization->translate('base'));
	$t->same('Fallback',$localization->translate('fallback'));
	$t->same('Fallback base',$localization->translate('fallback_base'));
	$t->same('Français',$localization->translate('shared'));
	$t->same('English',$localization->translate('shared',[],'de-DE'));
	$t->same('Save orders',$localization->translate('save',['resource'=>'orders'],null,null,'panel'));
	$t->same('Default Alice',$localization->translate('missing',['name'=>'Alice'],null,'Default :name'));
	$t->same('Stringable default',$localization->translate('missing',[],null,dp_panel_localization_stringable('Stringable default')));
	$t->same('missing.key',$localization->translate('missing:key'));
	$t->same('Blank default',$localization->translate('',[],null,'Blank default'));
	$t->same('',$localization->translate('',[],null,null));
	$complex=$localization->translate('complex',[
		'count'=>2,
		'item'=>'items',
		'name'=>dp_panel_localization_stringable('Ada'),
		'data'=>['id'=>1],
		'zero'=>0,
		''=>'ignored',
	],null,null,'panel');
	$t->same('2 items Ada {"id":1} 0',$complex);
	$t->same('Save products',$localization->trans('save',['resource'=>'products'],null,null,'panel'));
	$t->same('Save customers',$localization->t('save',['resource'=>'customers'],null,null,'panel'));

	$scope=$localization->scope('panel');
	$t->same(true,$scope instanceof PanelLocalizationScope);
	$t->same('Save invoices',$scope->translate('save',['resource'=>'invoices']));
	$t->same('Save quotes',$scope->trans('save',['resource'=>'quotes']));
	$t->same('Save users',$scope->t('save',['resource'=>'users']));
	$t->same(true,$scope->has('save'));
	$t->same(false,$scope->has('missing'));
	$scopeArray=$scope->toArray();
	$t->same('panel_localization_scope',$scopeArray['type']);
	$t->same('panel',$scopeArray['scope']);
	$t->same($scopeArray,$scope->jsonSerialize());
});

test('panel localization private normalization flattening and string conversion cover edge inputs',static function(Context $t): void {
	$t->same(['fr-CA','fr','en-US','en'],$t->nonPublic(PanelLocalization::make([], 'fr-CA','en-US'))->invoke('candidateLocales',null));
	$t->same(['de-DE','de','en-US','en'],$t->nonPublic(PanelLocalization::make([], 'fr-CA','en-US'))->invoke('candidateLocales','de_DE'));
	foreach([
		[null,''],['',''],['  fr_ca  ','fr-CA'],['ZH__hant!!_ca','zh-HANT-CA'],['--',''],['en-@-US','en-US'],
	] as [$raw,$expected]){
		$t->same($expected,$t->nonPublic(PanelLocalization::class)->invoke('normalizeLocale',$raw));
	}
	$t->same('fr',$t->nonPublic(PanelLocalization::class)->invoke('baseLocale','fr-CA'));
	$t->same('en',$t->nonPublic(PanelLocalization::class)->invoke('baseLocale','en'));
	$t->same('',$t->nonPublic(PanelLocalization::class)->invoke('baseLocale',null));
	foreach([
		[['key','scope'],'scope.key'],[[' key:part ',' scope:part '],'scope.part.key.part'],[['key',''],'key'],[['','scope'],'scope'],[['',''],''],
	] as [$arguments,$expected]){
		$t->same($expected,$t->nonPublic(PanelLocalization::class)->invoke('scopedKey',...$arguments));
	}
	$flat=$t->nonPublic(PanelLocalization::class)->invoke('flattenTranslations',[
		'group'=>[
			'name'=>'Name',
			'nested'=>['value'=>2],
			''=>'ignored',
		],
		'boolean'=>false,
		'none'=>null,
		'object'=>dp_panel_localization_stringable('Object'),
		'unsupported'=>new stdClass(),
		''=>'ignored',
	],'root');
	$t->same([
		'root'=>'ignored',
		'root.boolean'=>'false',
		'root.group'=>'ignored',
		'root.group.name'=>'Name',
		'root.group.nested.value'=>'2',
		'root.none'=>'',
		'root.object'=>'Object',
	],$flat);
	$t->same([],$t->nonPublic(PanelLocalization::class)->invoke('flattenTranslations',[]));
	$t->same([],$t->nonPublic(PanelLocalization::class)->invoke('flattenTranslations',[ ''=>'ignored' ],''));
	$t->same('',$t->nonPublic(PanelLocalization::class)->invoke('interpolate','', ['name'=>'Ada']));
	$t->same('Static',$t->nonPublic(PanelLocalization::class)->invoke('interpolate','Static',[]));
	$t->same('Hi Ada',$t->nonPublic(PanelLocalization::class)->invoke('interpolate','Hi :name',[' name '=>'Ada',''=>'ignored']));
	foreach([[null,''],[true,'true'],[false,'false'],[3,'3'],[1.25,'1.25'],['text','text'],[dp_panel_localization_stringable('value'),'value']] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(PanelLocalization::class)->invoke('stringValue',$value));
	}
});
