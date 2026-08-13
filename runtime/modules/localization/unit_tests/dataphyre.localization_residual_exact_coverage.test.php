<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Localization\LocaleCatalog;
use Dataphyre\Localization\LocaleDefinition;
use Dataphyre\Localization\LocaleDefinitionBatchResult;
use Dataphyre\Localization\LocaleDefinitionCatalog;
use Dataphyre\Localization\LocaleDefinitionMutation;
use Dataphyre\Localization\Localization;
use Dataphyre\Localization\LocalizationManager;
use Dataphyre\Localization\UnknownLocaleCatalog;
use Dataphyre\Localization\UnknownLocaleEntry;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true,'localization'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dpLocalizationResidualModules=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dpLocalizationResidualModules.'/core/kernel/autoloader.php';
if(!function_exists('tracelog')){ function tracelog(...$arguments): void {} }
require_once __DIR__.'/localization_coverage_helpers.php';
\dataphyre\autoloader::register($dpLocalizationResidualModules);
\dataphyre\autoloader::register_framework_modules(['localization']);

test('localization residual value objects expose source metadata mutations batches and catalog surfaces',static function(Context $t): void {
	$definition=LocaleDefinition::fromArray([
		'id'=>'17','lang'=>'fr-CA','theme'=>'dark','path'=>'account','type'=>'local','name'=>'welcome',
		'string'=>'Bienvenue','edit_time'=>'2026-07-11 09:00:00','source_branch'=>'feature/locales','source_commit'=>'abc123',
	]);
	$t->same('fr-CA',$definition->language());
	$t->same('dark',$definition->theme());
	$t->same('account',$definition->path());
	$t->same('Bienvenue',$definition->string());
	$t->same('2026-07-11 09:00:00',$definition->editTime());
	$t->same('feature/locales',$definition->sourceBranch());
	$t->same('abc123',$definition->sourceCommit());
	$t->same('abc123',$definition->jsonSerialize()['source_commit']);
	$t->same('feature/locales',$definition->jsonSerialize()['source_branch']);

	$branchOnly=LocaleDefinition::fromArray(['source_branch'=>'branch-only']);
	$t->same('branch-only',$branchOnly->jsonSerialize()['source_branch']);
	$t->isFalse(array_key_exists('source_commit',$branchOnly->jsonSerialize()));
	$commitOnly=LocaleDefinition::fromArray(['source_commit'=>'commit-only']);
	$t->same('commit-only',$commitOnly->jsonSerialize()['source_commit']);
	$t->isFalse(array_key_exists('source_branch',$commitOnly->jsonSerialize()));

	$mutation=LocaleDefinitionMutation::fromArray([
		'type'=>'local','lang'=>'en-CA','name'=>'title','value'=>'Title','theme'=>'light','path'=>'home',
	]);
	$t->same('en-CA',$mutation->language());
	$t->same('title',$mutation->name());
	$t->same('Title',$mutation->string());
	$t->same('light',$mutation->theme());
	$t->same('home',$mutation->path());

	$batch=new LocaleDefinitionBatchResult('import',false,3,0,3,true,2);
	$t->same('import',$batch->operation());
	$t->isTrue($batch->failed());
	$t->same(2,$batch->rebuildTargets());
	$t->isTrue($batch->noop());

	$catalog=LocaleDefinitionCatalog::fromArray([
		$definition->jsonSerialize(),
		'ignored',
	],['lang'=>'fr-CA'],4,2);
	$t->same(1,count($catalog->all()));
	$t->same($definition->name(),$catalog->all()[0]->name());
	$t->hasPathValues([
		'filters.lang'=>'fr-CA',
		'limit'=>4,
		'offset'=>2,
		'entries.0.name'=>$definition->name(),
	],$catalog->jsonSerialize());
	$emptyCatalog=LocaleDefinitionCatalog::fromArray([]);
	$t->same(null,$emptyCatalog->first());

	$localeCatalog=new LocaleCatalog('global','', 'fr-CA',['HELLO'=>'Bonjour','NULL_VALUE'=>null]);
	$t->same(['HELLO'=>'Bonjour','NULL_VALUE'=>null],$localeCatalog->all());
})->tag('localization','localization-residual-exact','deep-coverage')->group('framework-coverage');

test('localization residual unknown entry and catalog surfaces normalize lookup iteration serialization and empty first',static function(Context $t): void {
	$entry=UnknownLocaleEntry::fromArray(' missing.key ',[
		'theme'=>'dark','path'=>'account','scope'=>'theme','string'=>'Missing','detection_lang'=>'en',
	]);
	$t->same('dark',$entry->theme());
	$t->same('account',$entry->path());
	$t->same('theme',$entry->scope());
	$t->same('Missing',$entry->string());
	$t->same('en',$entry->detectionLanguage());
	$t->isTrue($entry->isTheme());

	$catalog=UnknownLocaleCatalog::fromArray([
		' missing.key '=>['theme'=>'dark','path'=>'account','scope'=>'theme','string'=>'Missing','detection_lang'=>'en'],
		'ignored'=>'not-an-array',
	]);
	$t->same(['MISSING.KEY'],$catalog->names());
	$t->same(1,count($catalog->all()));
	$t->same('MISSING.KEY',$catalog->get(' missing.key ')->name());
	$t->same(1,iterator_count($catalog->getIterator()));
	$t->same('theme',$catalog->jsonSerialize()['MISSING.KEY']['scope']);
	$empty=new UnknownLocaleCatalog();
	$t->same(null,$empty->first());
})->tag('localization','localization-residual-exact','deep-coverage')->group('framework-coverage');

test('localization residual manager and facade cover metadata forwards failed clearing raw language fallback and scoped context clones',static function(Context $t): void {
	LocalizationManager::flush();
	$manager=LocalizationManager::instance();
	$t->same(['light'=>'Light','dark'=>'Dark'],$manager->availableThemes());
	$t->hasKey('fr-CA',Localization::availableLanguages());
	$t->same(['light'=>'Light','dark'=>'Dark'],Localization::availableThemes());
	$t->same(null,Localization::translateOrNull('global:absent'));
	$t->same('MISSING.KEY',Localization::unknownLocale('missing.key')->name());
	$t->isTrue(Localization::hasUnknownLocale('missing.key'));

	\dataphyre\localization::$clearResult=false;
	$failedOne=Localization::clearUnknown('missing.key');
	$t->isTrue($failedOne->failed());
	$t->same('clear_unknown_locale_failed',$failedOne->status());
	$failedAll=Localization::clearUnknown();
	$t->isTrue($failedAll->failed());
	$t->same('clear_unknown_locales_failed',$failedAll->status());
	\dataphyre\localization::$clearResult=true;

	$definitions=Localization::definitions(['language'=>'en-CA'],2,1);
	$t->same(['language'=>'en-CA'],$definitions->filters());
	$t->same(2,$definitions->limit());
	$t->same(1,$definitions->offset());
	$t->same('hello',Localization::definition('global','en-CA','hello')->name());

	$context=$manager->context()->language('en-CA')->theme('light')->page('home');
	$t->same('Hello Context',$context->globalString('hello',null,['name'=>'Context']));
	$t->same('Light title',$context->themeString('title'));
	$t->same('Welcome Context',$context->local('welcome',null,['name'=>'Context']));

	$previousState=\dataphyre\localization::$state;
	\dataphyre\localization::$state['available_languages']=null;
	\dataphyre\localization::$state['user_language']=null;
	\dataphyre\localization::$state['default_language']=null;
	$t->same('en-CA',$t->nonPublic($manager)->invoke('resolveLanguage'));
	\dataphyre\localization::$state=$previousState;
})->tag('localization','localization-residual-exact','deep-coverage')->group('framework-coverage');
