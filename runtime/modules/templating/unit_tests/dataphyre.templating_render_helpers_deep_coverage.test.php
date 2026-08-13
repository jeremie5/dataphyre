<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	use Dataphyre\Test\TestState;

	if(!function_exists(__NAMESPACE__.'\\dp_module_present')){
		function dp_module_present(string $module): bool {
			return TestState::channel('templating.render-helpers')->get('modules',[])[$module] ?? false;
		}
	}

	if(!class_exists(__NAMESPACE__.'\\currency', false)){
		final class currency {
			public static function formatter(float $amount, bool $show_free=false, ?string $currency=null): string {
				return 'currency:'.number_format($amount, 2, '.', '').':'.($currency ?? 'default').':'.($show_free ? 'free' : 'paid');
			}
		}
	}
}

namespace dataphyre\datadoc {
	if(!class_exists(__NAMESPACE__.'\\highlighter', false)){
		final class highlighter {
			public static function retabulate_php(string $code): string {
				return 'retabulated:'.$code;
			}

			/** @param array<string,mixed> $options */
			public static function highlight_code(string $code, string $language, array $options=[]): string {
				return 'highlighted:'.$language.':'.$code.':'.($options['start_line'] ?? 0);
			}

			public static function linkify_php(string $code): string {
				return 'linked:'.$code;
			}
		}
	}
}

namespace {

use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'templating'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

$dp_render_helpers_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_render_helpers_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_render_helpers_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'templating']);
require_once $dp_render_helpers_modules_root.'/templating/unit_tests/templating_render_test_helpers.php';

/** @return array{0:NonPublicAccess,1:TestState} */
function dp_render_helpers_scenario(Context $t, string $name): array {
	$state=$t->state('templating.render-helpers',['modules'=>[]]);
	$workspace=$t->workspace('templating-render-helpers-'.$name);
	\dataphyre\templating::init(false, $workspace->directory('cache'), false, []);
	$templating=$t->nonPublic(\dataphyre\templating::class);
	foreach(['inspection_enabled','active_manifest','current_render_data'] as $property){
		$templating->replacePropertyForTest($property,$templating->readProperty($property));
	}
	return [$templating,$state];
}

function dp_render_helpers_named(string $value): string {
	return 'named:'.$value;
}

final class DpRenderHelpersArrayCallable {
	public function combine(string $left, string $right): string {
		return $left.'+'.$right;
	}
}

final class DpRenderHelpersStaticCallable {
	public static function combine(string $left, string $right): string {
		return $left.'|'.$right;
	}
}

final class DpRenderHelpersInvokable {
	public function __invoke(string ...$values): string {
		return implode(':', $values);
	}
}

final class DpRenderHelpersMoney {
	public function __construct(private string $label='money', private string $code='USD') {}

	public function convertedTo(string $currency): self {
		return new self($this->label.'-converted', strtoupper($currency));
	}

	public function currency(): string {
		return $this->code;
	}

	public function format(bool $show_free=false): string {
		return $this->label.':'.$this->code.':'.($show_free ? 'free' : 'paid');
	}

	public function inDisplayCurrency(): self {
		return new self($this->label.'-display', 'DSP');
	}

	public function inBaseCurrency(): self {
		return new self($this->label.'-base-target', 'BAS');
	}

	public function base(): self {
		return new self($this->label.'-base-subject', 'CAD');
	}

	public function original(): self {
		return new self($this->label.'-original-subject', $this->code);
	}
}

final class DpRenderHelpersFormatted {
	public function __construct(private string $label) {}

	public function format(bool $show_free=false): string {
		return $this->label.':'.($show_free ? 'free' : 'paid');
	}
}

final class DpRenderHelpersMoneyPair {
	public function base(): DpRenderHelpersFormatted {
		return new DpRenderHelpersFormatted('pair-base');
	}

	public function original(): DpRenderHelpersFormatted {
		return new DpRenderHelpersFormatted('pair-original');
	}
}

test('templating render helpers deep coverage parses assets helpers globals and callable shapes', static function(Context $t): void {
	[$templating]=dp_render_helpers_scenario($t,'helpers');
	$templating->writeProperty('inspection_enabled', true);
	$templating->writeProperty('active_manifest', []);
	$assets=$templating->invokeWithArguments('parse_assets', ['{{asset "site.css"}}/{{asset "https://cdn.example.test/app.js"}}']);
	$t->same('assets/site.css/https://cdn.example.test/app.js', $assets);
	$t->same(2, count($templating->readProperty('active_manifest')['assets'] ?? []));

	$templating->writeProperty('current_render_data', [
		'user'=>['name'=>'Ada'],
		'nullable'=>null,
	]);
	\dataphyre\templating::register_helper('rh_values', static fn(mixed ...$values): string=>json_encode($values, JSON_UNESCAPED_SLASHES));
	\dataphyre\templating::register_helper('rh_empty', static fn(): string=>'empty');
	$template=<<<'TPL'
{{rh_values("a,b", 'it\'s', true, FALSE, null, 12, -3.5, user.name, nullable, missing)}}/{{rh_empty()}}
TPL;
	$expected=json_encode(['a,b', "it's", true, false, null, 12, -3.5, 'Ada', null, 'missing'], JSON_UNESCAPED_SLASHES).'/empty';
	$t->same($expected, \dataphyre\templating::apply_helpers($template));

	\dataphyre\templating::add_to_global_context('html', '<strong>Ada</strong>');
	$t->same('Hello &lt;strong&gt;Ada&lt;/strong&gt;.', $templating->invokeWithArguments('apply_global_context', ['Hello {{global.html}}.']));
	$t->same('unchanged', $templating->invokeWithArguments('apply_global_context', ['unchanged']));

	$t->same([], $templating->invokeWithArguments('parse_template_arguments', [' ']));
	$t->same('', $templating->invokeWithArguments('resolve_template_argument', [' ']));
	$t->same(['one'], $templating->invokeWithArguments('split_template_arguments', ['one,']));
	$t->same([], $templating->invokeWithArguments('split_template_arguments', ['']));

	$array_callable=new DpRenderHelpersArrayCallable();
	$t->same('a+b', $templating->invokeWithArguments('invoke_template_callable', [[$array_callable, 'combine'], ['a', 'b', 'ignored']]));
	$t->same('a|b', $templating->invokeWithArguments('invoke_template_callable', [DpRenderHelpersStaticCallable::class.'::combine', ['a', 'b', 'ignored']]));
	$t->same('a:b:c', $templating->invokeWithArguments('invoke_template_callable', [new DpRenderHelpersInvokable(), ['a', 'b', 'c']]));
	$t->same('named:x', $templating->invokeWithArguments('invoke_template_callable', ['dp_render_helpers_named', ['x', 'ignored']]));
	$t->same('a,b,c', $templating->invokeWithArguments('invoke_template_callable', [static fn(string ...$values): string=>implode(',', $values), ['a', 'b', 'c']]));
})->tag('templating', 'render-helpers', 'deep-coverage')->group('framework-coverage');

test('templating render helpers deep coverage applies extensions pipelines and filter parsing', static function(Context $t): void {
	[$templating]=dp_render_helpers_scenario($t,'pipelines');
	$templating->writeProperty('inspection_enabled', true);
	$templating->writeProperty('active_manifest', []);
	$templating->writeProperty('current_render_data', ['user'=>['name'=>'<Ada>']]);
	\dataphyre\templating::register_extension('rh_extension', static fn(string $name, bool $enabled): string=>$enabled ? strtoupper($name) : 'off');
	$t->same('<ADA>', \dataphyre\templating::apply_extensions('{{rh_extension(user.name, true)}}'));
	$t->same('plain', \dataphyre\templating::apply_extensions('plain'));

	$filters=[
		'rh_suffix'=>static fn(mixed $value, string $suffix=''): string=>(string)$value.$suffix,
		'rh_wrap'=>static fn(mixed $value, string ...$parts): string=>implode('', array_merge([$parts[0] ?? ''], [(string)$value], array_slice($parts, 1))),
	];
	$pipeline=$templating->invokeWithArguments('apply_pipelines', [
		'{{ user.name | rh_suffix("!") | invalid-filter | missing }} / {{ absent | rh_suffix("?") }} / {{ user.name | rh_wrap("[", "]") }}',
		$filters,
	]);
	$t->same('&lt;Ada&gt;! / ? / [&lt;Ada&gt;]', $pipeline);
	$t->same('no pipeline', $templating->invokeWithArguments('apply_pipelines', ['no pipeline', $filters]));

	$t->same(['name'=>'', 'args'=>[]], $templating->invokeWithArguments('parse_filter_invocation', [' ']));
	$t->same(['name'=>'not-valid!', 'args'=>[]], $templating->invokeWithArguments('parse_filter_invocation', ['not-valid!']));
	$t->same(['name'=>'rh_suffix', 'args'=>[]], $templating->invokeWithArguments('parse_filter_invocation', ['rh_suffix']));
	$t->same(['name'=>'rh_suffix', 'args'=>['!']], $templating->invokeWithArguments('parse_filter_invocation', ['rh_suffix("!")']));
	$manifest=$templating->readProperty('active_manifest');
	$t->contains('rh_extension', $manifest['extensions'] ?? []);
	$t->contains('rh_suffix', $manifest['filters'] ?? []);
})->tag('templating', 'render-helpers', 'deep-coverage')->group('framework-coverage');

test('templating render helpers deep coverage renders markdown with and without datadoc', static function(Context $t): void {
	[$templating,$state]=dp_render_helpers_scenario($t,'markdown');
	$state->put('modules',['datadoc'=>false]);
	$markdown=<<<'MD'
# Heading
## Subheading
### Third
#### Fourth
**bold** [link](https://example.test) --- `x<y`
```php
<unsafe>
```
MD;
	$plain=$templating->invokeWithArguments('parse_markdown', [$markdown]);
	$t->contains('<h1> Heading</h1>', $plain);
	$t->contains('<h5> Fourth</h5>', $plain);
	$t->contains('<strong>bold</strong>', $plain);
	$t->contains('<a href="https://example.test"><u>link</u></a>', $plain);
	$t->contains('<hr>', $plain);
	$t->contains('&lt;unsafe&gt;', $plain);

	$state->put('modules',['datadoc'=>true]);
	$highlighted=$templating->invokeWithArguments('parse_markdown', ["```php\necho 1;\n```"]);
	$t->contains('linked:highlighted:php:retabulated:echo 1;', $highlighted);
	$t->contains(':2', $highlighted);
})->tag('templating', 'render-helpers', 'deep-coverage')->group('framework-coverage');

test('templating render helpers deep coverage formats every money subject and option branch', static function(Context $t): void {
	[$templating]=dp_render_helpers_scenario($t,'money');
	$money=new DpRenderHelpersMoney();
	$t->same('money:USD:paid', $templating->invokeWithArguments('format_money_value', [$money]));
	$t->same('money-display:DSP:paid', $templating->invokeWithArguments('format_money_value', [$money, 'display']));
	$t->same('money-base-target:BAS:free', $templating->invokeWithArguments('format_money_value', [$money, true, 'base']));
	$t->same('money-converted:EUR:paid', $templating->invokeWithArguments('format_money_value', [$money, 'eur']));
	$t->same('money:USD:paid', $templating->invokeWithArguments('format_money_value', [$money, 'usd']));
	$t->same('money-base-subject:CAD:paid', $templating->invokeWithArguments('format_money_value', [$money, 'base']));
	$t->same('money-original-subject:USD:paid', $templating->invokeWithArguments('format_money_value', [$money, 'original']));

	$pair=new DpRenderHelpersMoneyPair();
	$t->same('pair-original:paid', $templating->invokeWithArguments('format_money_value', [$pair]));
	$t->same('pair-base:paid', $templating->invokeWithArguments('format_money_value', [$pair, 'base']));
	$t->same('pair-original:free', $templating->invokeWithArguments('format_money_value', [$pair, 'original', true]));
	$t->same('generic:free', $templating->invokeWithArguments('format_money_value', [new DpRenderHelpersFormatted('generic'), true]));

	$t->same('currency:12.50:CAD:free', $templating->invokeWithArguments('format_money_value', [12.5, 'CAD', true]));
	$t->same('currency:0.00:default:paid', $templating->invokeWithArguments('format_money_value', [null, 'display']));
	$t->same('literal', $templating->invokeWithArguments('format_money_value', ['literal']));
	$t->same('', $templating->invokeWithArguments('format_money_value', [['unsupported']]));

	$t->same([null, '23', false], $templating->invokeWithArguments('normalize_money_arguments', [[23, false]]));
	$t->same([null, ' weird ', true], $templating->invokeWithArguments('normalize_money_arguments', [[' weird ', 'ignored', true, null]]));
	$t->same(null, $templating->invokeWithArguments('normalize_money_currency_target', [null]));
	$t->same(null, $templating->invokeWithArguments('normalize_money_currency_target', [' ']));
	$t->same('display', $templating->invokeWithArguments('normalize_money_currency_target', [' Display ']));
	$t->same('base', $templating->invokeWithArguments('normalize_money_currency_target', ['BASE']));
	$t->same('CAD', $templating->invokeWithArguments('normalize_money_currency_target', ['cad']));
	$t->same('unchanged', $templating->invokeWithArguments('normalize_money_subject', ['unchanged', null]));
})->tag('templating', 'render-helpers', 'deep-coverage')->group('framework-coverage');

}
