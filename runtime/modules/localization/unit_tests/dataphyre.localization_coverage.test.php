<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Localization\LocaleCatalog;
use Dataphyre\Localization\LocaleDefinition;
use Dataphyre\Localization\LocaleDefinitionBatchResult;
use Dataphyre\Localization\LocaleDefinitionCatalog;
use Dataphyre\Localization\LocaleDefinitionMutation;
use Dataphyre\Localization\Localization;
use Dataphyre\Localization\LocalizationContext;
use Dataphyre\Localization\LocalizationMaintenanceResult;
use Dataphyre\Localization\LocalizationManager;
use Dataphyre\Localization\LocalizationRebuildSelection;
use Dataphyre\Localization\LocalizationState;
use Dataphyre\Localization\UnknownLocaleCatalog;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'localization'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_localization_cov_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_localization_cov_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('tracelog')){ function tracelog(...$args): void {} }
require_once __DIR__.'/localization_coverage_helpers.php';
\dataphyre\autoloader::register($dp_localization_cov_modules_root);
\dataphyre\autoloader::register_framework_modules(['localization']);

suite('Localization catalog and maintenance lifecycle')
	->contract('localization.catalog-lifecycle', 1)
	->layer('integration')
	->risk('high')
	->watches('module:localization')
	->through('locale-catalog', 'lookup', 'interpolation', 'definition-mutation', 'rebuild', 'maintenance')
	->isolation('case')
	->tag('localization', 'catalog-lifecycle')
	->group('framework-coverage');

test('localization manager covers state catalogs scoped lookup interpolation pluralization and restoration', static function(Context $t): void {
	LocalizationManager::flush();
	$manager=LocalizationManager::instance();
	$t->isTrue($manager===LocalizationManager::instance());
	$state=$manager->state();
	$t->instanceOf(LocalizationState::class, $state);
	$t->same('en-CA', $state->defaultLanguage());
	$t->same('en-CA', $state->userLanguage());
	$t->same('light', $state->userTheme());
	$t->same(['en-CA'=>'English', 'fr-CA'=>'Français'], $state->availableLanguages());
	$t->same(['light'=>'Light', 'dark'=>'Dark'], $state->availableThemes());
	$t->same(['app'=>'Dataphyre'], $state->customParameters());
	$t->isTrue($state->databaseBacked());
	$t->same(['kind'=>'coverage'], $state->source());
	$t->same('en-CA', $manager->defaultLanguage());
	$t->same('en-CA', $manager->userLanguage());
	$t->same('light', $manager->userTheme());
	$t->same('home', $manager->activePage());
	$t->same('account', $manager->activePage('/account/'));
	$t->same('en-CA', $manager->validateLanguage('invalid'));
	$t->same('Hello Ada from Dataphyre', $manager->parameters('Hello :name from :app', ['name'=>'Ada']));

	$global=$manager->locales('global', '', 'en-CA');
	$t->instanceOf(LocaleCatalog::class, $global);
	$t->same('global', $global->scope());
	$t->same('', $global->path());
	$t->same('en-CA', $global->language());
	$t->same(3, $global->count());
	$t->isTrue($global->has('HELLO'));
	$t->same('Hello :name', $global->get('HELLO'));
	$t->same('fallback', $global->get('missing', 'fallback'));
	$t->same(3, iterator_count($global->getIterator()));
	$t->isTrue($manager->has('global:hello'));
	$t->isTrue($manager->has('theme:title', null, null, 'light'));
	$t->isTrue($manager->has('local:welcome', null, 'home', 'light'));
	$t->isFalse($manager->has('global:absent'));
	$t->isTrue($manager->missing('global:absent'));
	$t->same('Hello Ada', $manager->translate('global:hello', null, ['name'=>'Ada']));
	$t->same('Welcome Grace', $manager->translate('local:welcome', null, ['name'=>'Grace'], null, 'home', 'light'));
	$t->same('Fallback Lin', $manager->translate('global:absent', 'Fallback :name', ['name'=>'Lin']));
	$t->same(null, $manager->translateOrNull('global:absent'));
	$t->same('Hello Ada', $manager->translateOrNull('global:hello', ['name'=>'Ada']));
	$t->same('none', $manager->choice(0, 'global:hello', 'global:many', 'global:zero', ['name'=>'none']));
	$t->same('Hello one', $manager->choice(-1, 'global:hello', 'global:many', null, ['name'=>'one']));
	$t->same('2 items', $manager->choice(2, 'global:hello', 'global:many', null, ['count'=>2], null, null, null));

	$before=\dataphyre\localization::state();
	$manager->translate('theme:title', null, null, 'fr-CA', null, 'dark');
	$t->same($before, \dataphyre\localization::state());
	$context=$manager->context('en-CA', 'light', 'home');
	$t->instanceOf(LocalizationContext::class, $context);
	$t->same('Hello Context', $context->translate('global:hello', null, ['name'=>'Context']));
	$t->isTrue($context->has('local:welcome'));
	$t->isFalse($context->missing('global:hello'));
	$t->same('Hello Context', $context->translateOrNull('global:hello', ['name'=>'Context']));
	$t->same('Hello one', $context->choice(1, 'global:hello', 'global:many', null, ['name'=>'one']));
	LocalizationManager::flush();
})->tag('localization', 'coverage')->group('framework-coverage');

test('localization unknown catalogs definitions mutations and maintenance results cover success failure and noops', static function(Context $t): void {
	$manager=LocalizationManager::instance();
	$unknown=$manager->unknownLocales();
	$t->instanceOf(UnknownLocaleCatalog::class, $unknown);
	$t->same(1, $unknown->count());
	$t->isTrue($unknown->has('missing.key'));
	$t->same('MISSING.KEY', $unknown->first()->name());
	$t->isTrue($unknown->first()->isLocal());
	$t->isFalse($unknown->first()->isGlobal());
	$t->isFalse($unknown->first()->isTheme());
	$t->same($unknown->first()->jsonSerialize(), $manager->unknownLocale('missing.key')->jsonSerialize());
	$t->same(null, $manager->unknownLocale('absent'));
	$t->isTrue($manager->hasUnknownLocale('missing.key'));
	$cleared=$manager->clearUnknown('missing.key');
	$t->isTrue($cleared->ok());
	$t->same(1, $cleared->count());
	$t->isFalse($cleared->noop());
	$t->isTrue($manager->clearUnknown('missing.key')->noop());
	$t->isTrue($manager->clearUnknown()->noop());

	$catalog=$manager->definitions([], 10, 0);
	$t->instanceOf(LocaleDefinitionCatalog::class, $catalog);
	$t->same(5, $catalog->count());
	$t->same(10, $catalog->limit());
	$t->same(0, $catalog->offset());
	$t->same([], $catalog->filters());
	$t->instanceOf(LocaleDefinition::class, $catalog->first());
	$t->same(5, iterator_count($catalog->getIterator()));
	$t->hasPathValues([
		'limit'=>10,
		'offset'=>0,
		'entries.0.name'=>$catalog->first()->name(),
	],$t->producesStableResult(static fn(): array=>$catalog->jsonSerialize()));
	$definition=$manager->definition('global', 'en-CA', 'hello');
	$t->instanceOf(LocaleDefinition::class, $definition);
	$t->same(1, $definition->id());
	$t->same('global', $definition->type());
	$t->same('hello', $definition->name());
	$t->isTrue($definition->isGlobal());
	$t->isFalse($definition->isTheme());
	$t->isFalse($definition->isLocal());
	$t->same(null, $manager->definition('global', 'en-CA', 'missing'));

	$global=LocaleDefinitionMutation::global('en-CA', 'saved', 'Saved');
	$theme=LocaleDefinitionMutation::forTheme('en-CA', 'light', 'themed', 'Themed');
	$local=LocaleDefinitionMutation::local('en-CA', 'light', 'home', 'local', 'Local');
	$t->same('global', $global->type());
	$t->same('theme', $theme->type());
	$t->same('local', $local->type());
	$t->same($global->jsonSerialize(), LocaleDefinitionMutation::fromArray($global->jsonSerialize())->jsonSerialize());
	$saved=$manager->saveDefinition('global', 'en-CA', 'single', 'Single');
	$t->isTrue($saved->ok());
	$t->isTrue($saved->forced());
	$t->same(1, $saved->count());
	$t->isTrue($manager->saveDefinition('global', 'en-CA', '', 'Bad')->failed());
	$batch=$manager->saveDefinitions([$global, $theme, $local, $definition, ['type'=>'global','language'=>'en-CA','name'=>'array','string'=>'Array'], new stdClass()]);
	$t->instanceOf(LocaleDefinitionBatchResult::class, $batch);
	$t->isTrue($batch->ok());
	$t->same(5, $batch->requested());
	$t->same(5, $batch->processed());
	$t->same(0, $batch->skipped());
	$t->isTrue($batch->rebuilt());
	$t->isFalse($batch->noop());
	$t->hasPathValues([
		'operation'=>$batch->operation(),
		'requested'=>5,
		'processed'=>5,
		'skipped'=>0,
		'rebuilt'=>true,
	],$batch->jsonSerialize());

	$t->isTrue($manager->deleteDefinition('global', 'en-CA', 'absent')->noop());
	$t->isTrue($manager->deleteDefinition('global', 'en-CA', 'single')->ok());
	$deleted=$manager->deleteDefinitions([$global, $theme, $local, ['type'=>'global','language'=>'en-CA','name'=>'array'], new stdClass()]);
	$t->instanceOf(LocaleDefinitionBatchResult::class, $deleted);
	$t->isTrue($deleted->ok());
	$t->same(4, $deleted->requested());
	$t->same(4, $deleted->processed());

	\dataphyre\localization::$learnResult=2;
	$t->same(2, $manager->learnUnknown()->count());
	\dataphyre\localization::$learnResult='no_locales_to_learn';
	$t->isTrue($manager->learnUnknown()->noop());
	\dataphyre\localization::$learnResult='already_learning_locales';
	$t->isTrue($manager->learnUnknown()->failed());
	\dataphyre\localization::$learnResult='unexpected';
	$t->isTrue($manager->learnUnknown()->failed());
	$t->isTrue($manager->sync(true)->forced());
})->tag('localization', 'coverage')->group('framework-coverage');

test('localization rebuild selections and result value objects preserve dimensions and status', static function(Context $t): void {
	$manager=LocalizationManager::instance();
	$all=LocalizationRebuildSelection::all();
	$global=LocalizationRebuildSelection::global(['en-CA']);
	$theme=LocalizationRebuildSelection::theme(['fr-CA'], ['dark']);
	$local=LocalizationRebuildSelection::local(['en-CA'], ['light'], ['home']);
	$t->same([], $all->types());
	$t->same(['global'], $global->types());
	$t->same(['fr-CA'], $theme->languages());
	$t->same(['dark'], $theme->themes());
	$t->same(['home'], $local->paths());
	$t->hasPathValues([
		'types.0'=>'local',
		'languages.0'=>'en-CA',
		'themes.0'=>'light',
		'paths.0'=>'home',
	],$local->jsonSerialize());
	$result=$manager->rebuild(['global'], ['en-CA']);
	$t->instanceOf(LocalizationMaintenanceResult::class, $result);
	$t->isTrue($result->ok());
	$t->same('rebuild', $result->operation());
	$t->same('rebuilt', $result->status());
	$t->isFalse($result->failed());
	$t->isFalse($result->noop());
	$t->isTrue($result->selection() instanceof LocalizationRebuildSelection);
	$t->hasPathValues([
		'operation'=>'rebuild',
		'status'=>'rebuilt',
		'ok'=>true,
		'selection.types.0'=>'global',
	],$result->jsonSerialize());
	$failed=$manager->rebuildSelection(new LocalizationRebuildSelection(['fail']));
	$t->isTrue($failed->failed());
	$t->same('rebuild_failed', $failed->status());
})->tag('localization', 'coverage')->group('framework-coverage');

test('localization static facade covers scoped convenience lookup maintenance and batch entrypoints', static function(Context $t): void {
	Localization::flush();
	$t->instanceOf(LocalizationManager::class, Localization::manager());
	$t->instanceOf(LocalizationState::class, Localization::state());
	$t->instanceOf(LocalizationContext::class, Localization::context('en-CA','light','home'));
	$t->same('en-CA', Localization::defaultLanguage());
	$t->same('en-CA', Localization::userLanguage());
	$t->same('light', Localization::userTheme());
	$t->same('home', Localization::activePage());
	$t->same('en-CA', Localization::validateLanguage('en-CA'));
	$t->same('Hi Ada', Localization::parameters('Hi :name',['name'=>'Ada']));
	$t->isTrue(Localization::has('global:hello'));
	$t->isFalse(Localization::missing('global:hello'));
	$t->same('Hello Ada', Localization::translate('global:hello', null, ['name'=>'Ada']));
	$t->same('Hello Ada', Localization::globalString('hello', null, ['name'=>'Ada']));
	$t->same('Light title', Localization::themeString('title', null, null, 'en-CA', 'light'));
	$t->same('Welcome Ada', Localization::local('welcome', null, ['name'=>'Ada'], 'en-CA', 'home', 'light'));
	$t->same('Hello one', Localization::choice(1,'global:hello','global:many',null,['name'=>'one']));
	$t->instanceOf(LocaleCatalog::class, Localization::locales('global','','en-CA'));
	$t->instanceOf(UnknownLocaleCatalog::class, Localization::unknownLocales());
	$t->instanceOf(LocaleDefinitionCatalog::class, Localization::definitions());
	$t->instanceOf(LocalizationMaintenanceResult::class, Localization::saveDefinition('global','en-CA','facade','Facade'));
	$t->instanceOf(LocaleDefinitionBatchResult::class, Localization::saveDefinitions([]));
	$t->instanceOf(LocalizationMaintenanceResult::class, Localization::deleteDefinition('global','en-CA','facade'));
	$t->instanceOf(LocaleDefinitionBatchResult::class, Localization::deleteDefinitions([]));
	$t->instanceOf(LocalizationMaintenanceResult::class, Localization::learnUnknown());
	$t->instanceOf(LocalizationMaintenanceResult::class, Localization::sync());
	$t->instanceOf(LocalizationMaintenanceResult::class, Localization::rebuild());
	$t->instanceOf(LocalizationMaintenanceResult::class, Localization::rebuildSelection(LocalizationRebuildSelection::all()));
	Localization::flush();
})->tag('localization', 'coverage')->group('framework-coverage');
