<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$modulesRoot=rtrim((string)($argv[1] ?? ''),'/\\');
$workspace=rtrim((string)($argv[2] ?? ''),'/\\');
$template=(string)($argv[3] ?? '');
if($modulesRoot==='' || !is_dir($modulesRoot) || $workspace==='' || !is_dir($workspace)
	|| $template==='' || !is_file($template)){
	fwrite(STDERR,"Managed templating cache boundary arguments are invalid.\n");
	exit(2);
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!function_exists('dp_module_required')){
	function dp_module_required(string $module,string $dependency): void {}
}
function dp_source_local_runtime_writes_allowed(): bool {
	return false;
}

define('ROOTPATH',['dataphyre'=>$workspace.DIRECTORY_SEPARATOR]);
require_once $modulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modulesRoot);
\dataphyre\autoloader::register_framework_modules(['core','templating']);
require_once $modulesRoot.'/templating/kernel/templating.main.php';

$automaticCache=$workspace.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'templating';
$explicitCache=$workspace.DIRECTORY_SEPARATOR.'explicit-cache';
$developmentCache=$workspace.DIRECTORY_SEPARATOR.'development-cache';
$stateCache=$workspace.DIRECTORY_SEPARATOR.'state-cache';

\dataphyre\templating::init(false,$explicitCache,false,[]);
$rendered=\dataphyre\templating::render($template);
$plan=\dataphyre\templating::plan($template);
\dataphyre\templating::init(true,$developmentCache,false,[]);
$developmentRendered=\dataphyre\templating::render($template);
\dataphyre\templating::apply_state(['cache_dir'=>$stateCache]);

$manager=\Dataphyre\Templating\TemplatingManager::instance();
$bindingCalls=0;
$binding=$manager->rememberBinding(
	static function() use (&$bindingCalls): string {
		$bindingCalls++;
		return 'managed-binding';
	},
	['managed'=>true],
	60,
	['managed'],
	'managed',
);
$firstBinding=$manager->renderString('{{value}}',['value'=>$binding],templateName:'managed-binding.tpl',overrides:[
	'cache_dir'=>$stateCache,
	'is_dev_mode'=>false,
]);
$secondBinding=$manager->renderString('{{value}}',['value'=>$binding],templateName:'managed-binding.tpl',overrides:[
	'cache_dir'=>$stateCache,
	'is_dev_mode'=>false,
]);
$cleared=$manager->clearBindingCache('managed');

$paths=[$automaticCache,$explicitCache,$developmentCache,$stateCache];
echo json_encode([
	'write_allowed'=>dp_source_local_runtime_writes_allowed(),
	'rendered'=>$rendered,
	'development_rendered'=>$developmentRendered,
	'plan_has_graph'=>is_array($plan) && is_array($plan['graph']['nodes'] ?? null),
	'binding_contents'=>[$firstBinding->content(),$secondBinding->content()],
	'binding_calls'=>$bindingCalls,
	'clear_count'=>$cleared,
	'cache_paths_absent'=>array_reduce(
		$paths,
		static fn(bool $absent,string $path): bool=>$absent && !file_exists($path) && !is_link($path),
		true,
	),
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
