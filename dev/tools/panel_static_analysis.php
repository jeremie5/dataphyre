<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/** @return array<string,string> */
function dp_panel_static_analysis_targets(): array {
	return [
		'Dataphyre\\Panel\\Column'=>'runtime/modules/panel/Framework/Tables/Column.php',
		'Dataphyre\\Panel\\Field'=>'runtime/modules/panel/Framework/Forms/Field.php',
		'Dataphyre\\Panel\\FormSection'=>'runtime/modules/panel/Framework/Forms/FormSection.php',
		'Dataphyre\\Panel\\Infolist'=>'runtime/modules/panel/Framework/Infolists/Infolist.php',
		'Dataphyre\\Panel\\InfolistEntry'=>'runtime/modules/panel/Framework/Infolists/InfolistEntry.php',
		'Dataphyre\\Panel\\NavigationCluster'=>'runtime/modules/panel/Framework/Navigation/NavigationCluster.php',
		'Dataphyre\\Panel\\NavigationItem'=>'runtime/modules/panel/Framework/Navigation/NavigationItem.php',
		'Dataphyre\\Panel\\PageTable'=>'runtime/modules/panel/Framework/Tables/PageTable.php',
		'Dataphyre\\Panel\\PanelCommand'=>'runtime/modules/panel/Framework/Navigation/PanelCommand.php',
		'Dataphyre\\Panel\\PanelComponentRegistry'=>'runtime/modules/panel/Framework/Support/PanelComponentRegistry.php',
		'Dataphyre\\Panel\\PanelExtensible'=>'runtime/modules/panel/Framework/Support/PanelExtensible.php',
		'Dataphyre\\Panel\\PanelInstanceExtensionRegistry'=>'runtime/modules/panel/Framework/Support/PanelInstanceExtensionRegistry.php',
		'Dataphyre\\Panel\\PanelMenuItem'=>'runtime/modules/panel/Framework/Navigation/PanelMenuItem.php',
		'Dataphyre\\Panel\\PanelPage'=>'runtime/modules/panel/Framework/Core/PanelPage.php',
		'Dataphyre\\Panel\\PanelSearchProvider'=>'runtime/modules/panel/Framework/Support/PanelSearchProvider.php',
		'Dataphyre\\Panel\\PanelTenant'=>'runtime/modules/panel/Framework/Navigation/PanelTenant.php',
		'Dataphyre\\Panel\\RelationManager'=>'runtime/modules/panel/Framework/Resources/RelationManager.php',
		'Dataphyre\\Panel\\Resource'=>'runtime/modules/panel/Framework/Resources/Resource.php',
		'Dataphyre\\Panel\\ResourceForm'=>'runtime/modules/panel/Framework/Resources/ResourceForm.php',
		'Dataphyre\\Panel\\ResourceTable'=>'runtime/modules/panel/Framework/Resources/ResourceTable.php',
		'Dataphyre\\Panel\\Schema'=>'runtime/modules/panel/Framework/Schemas/Schema.php',
		'Dataphyre\\Panel\\SchemaComponent'=>'runtime/modules/panel/Framework/Schemas/SchemaComponent.php',
		'Dataphyre\\Panel\\SchemaLifecycle'=>'runtime/modules/panel/Framework/Schemas/SchemaLifecycle.php',
		'Dataphyre\\Panel\\TableFilter'=>'runtime/modules/panel/Framework/Tables/TableFilter.php',
		'Dataphyre\\Panel\\TableGroup'=>'runtime/modules/panel/Framework/Tables/TableGroup.php',
		'Dataphyre\\Panel\\TableSummary'=>'runtime/modules/panel/Framework/Tables/TableSummary.php',
		'Dataphyre\\Panel\\TableView'=>'runtime/modules/panel/Framework/Tables/TableView.php',
		'Dataphyre\\Panel\\Widget'=>'runtime/modules/panel/Framework/Widgets/Widget.php',
	];
}

function dp_panel_static_analysis_root(): string {
	return dirname(__DIR__, 2);
}

function dp_panel_static_analysis_contract_path(?string $root=null): string {
	return ($root ?? dp_panel_static_analysis_root()).'/runtime/modules/panel/static-analysis/panel-builder-contract.json';
}

function dp_panel_static_analysis_type_prefix(string $value): string {
	$depth=0;
	$length=strlen($value);
	for($index=0; $index<$length; $index++){
		$character=$value[$index];
		if(str_contains('<({[', $character)){ $depth++; continue; }
		if(str_contains('>)}]', $character)){ $depth=max(0, $depth-1); continue; }
		if($depth===0 && ctype_space($character)){ return substr($value, 0, $index); }
	}
	return $value;
}

function dp_panel_static_analysis_normalize_tag(string $line): string {
	if(preg_match('/^@(param|var)\s+(.+)$/', $line, $matches)===1){
		$payload=$matches[2];
		if(preg_match('/^(.*?(?:&?\.\.\.)?\$[A-Za-z_][A-Za-z0-9_]*)(?:\s|$)/', $payload, $parameter)===1){
			return '@'.$matches[1].' '.trim($parameter[1]);
		}
		return '@'.$matches[1].' '.dp_panel_static_analysis_type_prefix($payload);
	}
	if(preg_match('/^@return\s+(.+)$/', $line, $matches)===1){
		return '@return '.dp_panel_static_analysis_type_prefix($matches[1]);
	}
	return $line;
}

/** @return list<string> */
function dp_panel_static_analysis_doc_tags(?string $doc): array {
	if($doc===null || $doc===''){ return []; }
	$tags=[];
	foreach(preg_split('/\R/u', $doc) ?: [] as $line){
		$line=trim($line);
		$line=preg_replace('/^\/\*\*?\s*/', '', $line) ?? $line;
		$line=preg_replace('/\s*\*\/\s*$/', '', $line) ?? $line;
		$line=preg_replace('/^\*\s?/', '', $line) ?? $line;
		$line=trim((string)(preg_replace('/\s+/u', ' ', $line) ?? $line));
		if(!str_starts_with($line, '@')){ continue; }
		if(
			preg_match('/^@(template(?:-covariant|-contravariant)?|phpstan-type|psalm-type)\b/', $line)===1
			|| preg_match('/\b(TRecord|TState|TValue|TResult|TCursor|TModel|TRelatedRecord|TParentRecord|TKey)\b/', $line)===1
			|| preg_match('/\bPanel(?:RecordResolver|RecordMutation|DataMutator)\b/', $line)===1
			|| str_contains($line, 'callable(')
			|| str_contains($line, 'class-string<')
			|| str_contains($line, 'iterable<')
			|| str_contains($line, 'list<')
		){
			$tags[]=dp_panel_static_analysis_normalize_tag($line);
		}
	}
	return $tags;
}

/** @return list<string> */
function dp_panel_static_analysis_callable_aliases(string $source): array {
	preg_match_all('/@(?:phpstan|psalm)-type\s+([A-Za-z_][A-Za-z0-9_]*)\s*=?\s*callable\s*\(/', $source, $matches);
	$aliases=array_values(array_unique(array_map('strval', $matches[1] ?? [])));
	sort($aliases, SORT_STRING);
	return $aliases;
}

/** @return array<string,string> */
function dp_panel_static_analysis_doc_param_types(?string $doc): array {
	if($doc===null || $doc===''){ return []; }
	$types=[];
	foreach(preg_split('/\R/u', $doc) ?: [] as $line){
		$line=trim($line);
		$line=preg_replace('/^\/\*\*?\s*/', '', $line) ?? $line;
		$line=preg_replace('/\s*\*\/\s*$/', '', $line) ?? $line;
		$line=preg_replace('/^\*\s?/', '', $line) ?? $line;
		if(preg_match('/^@(?:phpstan-|psalm-)?param\s+(.+?)\s+(?:&\s*)?(?:\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)\b/', trim($line), $match)===1){
			$types[$match[2]]=trim($match[1]);
		}
	}
	return $types;
}

/**
 * Ensures every native public callable parameter has an analyzer-readable shape
 * or references a local callable type alias declared by the builder.
 *
 * @return list<string>
 */
function dp_panel_static_analysis_public_callable_failures(string $source, string $relativePath, string $class): array {
	$tokens=token_get_all($source, TOKEN_PARSE);
	$aliases=dp_panel_static_analysis_callable_aliases($source);
	$pendingDoc=null;
	$visibility=null;
	$failures=[];
	$count=count($tokens);
	for($index=0; $index<$count; $index++){
		$token=$tokens[$index];
		if(is_array($token) && $token[0]===T_DOC_COMMENT){
			$pendingDoc=$token[1];
			$visibility=null;
			continue;
		}
		if(is_array($token) && in_array($token[0], [T_PUBLIC,T_PROTECTED,T_PRIVATE], true)){
			$visibility=$token[0];
			continue;
		}
		if(!is_array($token) || $token[0]!==T_FUNCTION){
			if($token===';' || (is_array($token) && $token[0]===T_CONST)){
				$pendingDoc=null;
				$visibility=null;
			}
			continue;
		}

		$name=null;
		$open=null;
		for($cursor=$index+1; $cursor<$count; $cursor++){
			$part=$tokens[$cursor];
			if(is_array($part) && $part[0]===T_STRING && $name===null){ $name=$part[1]; continue; }
			if($part==='('){ $open=$cursor; break; }
		}
		if($name===null || $open===null || $visibility===T_PROTECTED || $visibility===T_PRIVATE){
			$pendingDoc=null;
			$visibility=null;
			continue;
		}

		$parameters=[];
		$segment=[];
		$round=1;
		$square=0;
		$curly=0;
		for($cursor=$open+1; $cursor<$count; $cursor++){
			$part=$tokens[$cursor];
			if($part==='('){ $round++; $segment[]=$part; continue; }
			if($part===')'){
				$round--;
				if($round===0){ if($segment!==[]){ $parameters[]=$segment; } break; }
				$segment[]=$part;
				continue;
			}
			if($part==='['){ $square++; }
			elseif($part===']'){ $square=max(0, $square-1); }
			elseif($part==='{'){ $curly++; }
			elseif($part==='}'){ $curly=max(0, $curly-1); }
			if($part===',' && $round===1 && $square===0 && $curly===0){
				$parameters[]=$segment;
				$segment=[];
				continue;
			}
			$segment[]=$part;
		}

		$documented=dp_panel_static_analysis_doc_param_types($pendingDoc);
		foreach($parameters as $parameter){
			$parameterName=null;
			$hasCallable=false;
			foreach($parameter as $part){
				if(is_array($part) && $part[0]===T_VARIABLE){ $parameterName=ltrim($part[1], '$'); }
				$text=is_array($part) ? $part[1] : (string)$part;
				if(strtolower($text)==='callable'){ $hasCallable=true; }
			}
			if(!$hasCallable || $parameterName===null){ continue; }
			$type=$documented[$parameterName] ?? '';
			$shaped=str_contains($type, 'callable(');
			if(!$shaped){
				foreach($aliases as $alias){
					if(preg_match('/\b'.preg_quote($alias, '/').'\b/', $type)===1){ $shaped=true; break; }
				}
			}
			if(!$shaped){
				$failures[]=$relativePath.':'.$token[2].' '.$class.'::'.$name.'() has an unshaped callable $'.$parameterName.' parameter.';
			}
		}
		$pendingDoc=null;
		$visibility=null;
	}
	return $failures;
}

/**
 * Extracts the generic contract from source without loading Panel or Composer.
 *
 * @return array{class:string,class_tags:list<string>,properties:array<string,list<string>>,methods:array<string,list<string>>}
 */
function dp_panel_static_analysis_extract(string $absolutePath): array {
	$source=file_get_contents($absolutePath);
	if($source===false){ throw new RuntimeException('Unable to read '.$absolutePath); }
	$tokens=token_get_all($source, TOKEN_PARSE);
	$namespace='';
	$class='';
	$classTags=[];
	$properties=[];
	$methods=[];
	$pendingDoc=null;
	$pendingVisibility=false;
	$count=count($tokens);
	for($index=0; $index<$count; $index++){
		$token=$tokens[$index];
		if(is_array($token) && $token[0]===T_DOC_COMMENT){
			$pendingDoc=$token[1];
			$pendingVisibility=false;
			continue;
		}
		if(is_array($token) && in_array($token[0], [T_PUBLIC,T_PROTECTED,T_PRIVATE], true)){
			$pendingVisibility=true;
			continue;
		}
		if(is_array($token) && $token[0]===T_NAMESPACE){
			$parts=[];
			for($cursor=$index+1; $cursor<$count; $cursor++){
				$part=$tokens[$cursor];
				if($part===';' || $part==='{'){ break; }
				if(is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)){ $parts[]=$part[1]; }
			}
			$namespace=implode('', $parts);
			continue;
		}
		if(is_array($token) && in_array($token[0], [T_CLASS,T_TRAIT], true)){
			for($cursor=$index+1; $cursor<$count; $cursor++){
				$part=$tokens[$cursor];
				if(is_array($part) && $part[0]===T_STRING){
					$class=($namespace!=='' ? $namespace.'\\' : '').$part[1];
					$classTags=dp_panel_static_analysis_doc_tags($pendingDoc);
					break;
				}
			}
			$pendingDoc=null;
			$pendingVisibility=false;
			continue;
		}
		if(is_array($token) && $token[0]===T_FUNCTION){
			$name=null;
			for($cursor=$index+1; $cursor<$count; $cursor++){
				$part=$tokens[$cursor];
				if($part==='('){ break; }
				if(is_array($part) && $part[0]===T_STRING){ $name=$part[1]; break; }
			}
			$tags=dp_panel_static_analysis_doc_tags($pendingDoc);
			if($name!==null && $tags!==[]){ $methods[$name]=$tags; }
			$pendingDoc=null;
			$pendingVisibility=false;
			continue;
		}
		if(is_array($token) && $token[0]===T_VARIABLE && $pendingDoc!==null && $pendingVisibility){
			$tags=dp_panel_static_analysis_doc_tags($pendingDoc);
			if($tags!==[]){ $properties[ltrim($token[1], '$')]=$tags; }
			$pendingDoc=null;
			$pendingVisibility=false;
			continue;
		}
		if($token===';' || $token==='{' || $token==='}' || (is_array($token) && $token[0]===T_CONST)){
			$pendingDoc=null;
			$pendingVisibility=false;
		}
	}
	ksort($properties, SORT_STRING);
	ksort($methods, SORT_STRING);
	return ['class'=>$class, 'class_tags'=>$classTags, 'properties'=>$properties, 'methods'=>$methods];
}

/** @return array{schema_version:int,generated_by:string,targets:array<string,array<string,mixed>>} */
function dp_panel_static_analysis_contract(?string $root=null): array {
	$root=$root ?? dp_panel_static_analysis_root();
	$targets=dp_panel_static_analysis_targets();
	ksort($targets, SORT_STRING);
	$contract=[];
	foreach($targets as $class=>$relativePath){
		$extracted=dp_panel_static_analysis_extract($root.'/'.$relativePath);
		if($extracted['class']!==$class){
			throw new RuntimeException('Expected '.$class.' in '.$relativePath.', found '.($extracted['class'] ?: 'no class').'.');
		}
		$contract[$class]=[
			'file'=>$relativePath,
			'class_tags'=>$extracted['class_tags'],
			'properties'=>$extracted['properties'],
			'methods'=>$extracted['methods'],
		];
	}
	return ['schema_version'=>1, 'generated_by'=>'dev/tools/panel_static_analysis.php', 'targets'=>$contract];
}

function dp_panel_static_analysis_json(array $value): string {
	return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

/** @return list<string> */
function dp_panel_static_analysis_support_failures(?string $root=null): array {
	$root=$root ?? dp_panel_static_analysis_root();
	$required=[
		'runtime/modules/panel/static-analysis/fixtures/panel-builders.php'=>[
			'@var Resource<StaticAnalysisOrder,StaticAnalysisOrderState>',
			'@var Field<StaticAnalysisOrder,string,StaticAnalysisOrderState>',
			'@var RelationManager<StaticAnalysisOrder,StaticAnalysisLineItem,StaticAnalysisLineState>',
			'@var PanelPage<string,StaticAnalysisOrder,StaticAnalysisOrderState>',
			'PanelCommand::make(',
			'NavigationItem::make(',
		],
		'runtime/modules/panel/static-analysis/phpstan.neon.dist'=>['level: max','panel-builders.php'],
		'runtime/modules/panel/static-analysis/psalm.xml.dist'=>['errorLevel="1"','panel-builders.php'],
		'runtime/modules/panel/static-analysis/panel-macros.example.json'=>['"schema_version": 1','"Dataphyre\\\\Panel\\\\Field"'],
	];
	$failures=[];
	foreach($required as $relativePath=>$needles){
		$contents=@file_get_contents($root.'/'.$relativePath);
		if($contents===false){ $failures[]='Missing static-analysis support file: '.$relativePath; continue; }
		foreach($needles as $needle){
			if(!str_contains($contents, $needle)){ $failures[]=$relativePath.' is missing required contract token: '.$needle; }
		}
	}
	return $failures;
}

/** @return list<string> */
function dp_panel_static_analysis_phpdoc_failures(?string $root=null): array {
	$root=$root ?? dp_panel_static_analysis_root();
	$failures=[];
	foreach(dp_panel_static_analysis_targets() as $class=>$relativePath){
		$source=@file_get_contents($root.'/'.$relativePath);
		if($source===false){ continue; }
		foreach(token_get_all($source) as $token){
			if(!is_array($token) || $token[0]!==T_DOC_COMMENT){ continue; }
			$lineNumber=$token[2];
			foreach(preg_split('/\R/u', $token[1]) ?: [] as $offset=>$line){
				$line=trim((string)(preg_replace('/^\s*\*\s?/', '', trim($line)) ?? $line));
				$location=$relativePath.':'.($lineNumber+$offset);
				if(str_contains($line, 'param($m)')){ $failures[]=$location.' contains a corrupted PHPDoc generator fragment.'; }
				if(preg_match('/^@return\s+\S+\.\s*$/', $line)===1){ $failures[]=$location.' has punctuation attached to its return type.'; }
				if(preg_match('/^@param\s+\??callable\s+\$/', $line)===1){ $failures[]=$location.' uses an unshaped public callback.'; }
				if(preg_match('/^@(param|return|var)\b.*(?:mixed\||\|mixed(?:[>,)| ]|$))/', $line)===1){ $failures[]=$location.' contains a mixed union that erases its declared type.'; }
				if(!str_starts_with($line, '@')){ continue; }
				foreach([['(',')'],['[',']'],['{','}'],['<','>']] as [$open,$close]){
					if(substr_count($line, $open)!==substr_count($line, $close)){
						$failures[]=$location.' has unbalanced '.$open.$close.' delimiters.';
						break;
					}
				}
			}
		}
		array_push($failures, ...dp_panel_static_analysis_public_callable_failures($source, $relativePath, $class));
		$extracted=dp_panel_static_analysis_extract($root.'/'.$relativePath);
		if($extracted['class']!==$class){ $failures[]=$relativePath.' does not declare '.$class.'.'; }
	}
	return array_values(array_unique($failures));
}

/** @return list<string> */
function dp_panel_static_analysis_check(?string $root=null, ?string $contractPath=null): array {
	$root=$root ?? dp_panel_static_analysis_root();
	$contractPath=$contractPath ?? dp_panel_static_analysis_contract_path($root);
	$encoded=@file_get_contents($contractPath);
	if($encoded===false){ return ['Missing static-analysis contract: '.$contractPath]; }
	try{
		$expected=json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
	}
	catch(JsonException $exception){
		return ['Invalid static-analysis contract JSON: '.$exception->getMessage()];
	}
	if(!is_array($expected)){ return ['Static-analysis contract root must be an object.']; }
	try{
		$actual=dp_panel_static_analysis_contract($root);
	}
	catch(Throwable $exception){
		return ['Unable to extract static-analysis contract: '.$exception->getMessage()];
	}
	$failures=[];
	if(($expected['schema_version'] ?? null)!==1){ $failures[]='Static-analysis contract schema_version must be 1.'; }
	if(($expected['generated_by'] ?? null)!==$actual['generated_by']){ $failures[]='Static-analysis contract generated_by is invalid.'; }
	$expectedTargets=is_array($expected['targets'] ?? null) ? $expected['targets'] : [];
	$actualTargets=$actual['targets'];
	if(array_keys($expectedTargets)!==array_keys($actualTargets)){
		$failures[]='Static-analysis target inventory drifted; regenerate the contract.';
	}
	foreach($actualTargets as $class=>$definition){
		if(!array_key_exists($class, $expectedTargets)){ continue; }
		if($expectedTargets[$class]!==$definition){ $failures[]=$class.' generic/callback contract drifted.'; }
	}
	return array_merge($failures, dp_panel_static_analysis_support_failures($root), dp_panel_static_analysis_phpdoc_failures($root));
}

function dp_panel_static_analysis_assert_identifier(string $value, string $label): string {
	if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)!==1){ throw new InvalidArgumentException($label.' is not a valid PHP identifier.'); }
	return $value;
}

function dp_panel_static_analysis_assert_type(string $value, string $label): string {
	$value=trim($value);
	if($value==='' || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, '*/') || str_contains($value, '@')){
		throw new InvalidArgumentException($label.' is not a safe PHPDoc type.');
	}
	if(preg_match('/^[A-Za-z0-9_\\\\|&?<>,:{}\[\]() .\'"$-]+$/D', $value)!==1){ throw new InvalidArgumentException($label.' contains unsupported type characters.'); }
	return $value;
}

/** @param array<string,mixed> $manifest */
function dp_panel_static_analysis_macro_stubs(array $manifest): string {
	if(($manifest['schema_version'] ?? null)!==1){ throw new InvalidArgumentException('Macro manifest schema_version must be 1.'); }
	$classes=$manifest['classes'] ?? null;
	if(!is_array($classes)){ throw new InvalidArgumentException('Macro manifest classes must be an object.'); }
	ksort($classes, SORT_STRING);
	$output="<?php\n/*************************************************************************\n * Generated Panel macro stubs. Do not edit by hand.\n *************************************************************************/\n\ndeclare(strict_types=1);\n";
	foreach($classes as $class=>$definition){
		if(!is_string($class) || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/D', $class)!==1){ throw new InvalidArgumentException('Macro class name is invalid.'); }
		if(!is_array($definition)){ throw new InvalidArgumentException('Macro class definition for '.$class.' must be an object.'); }
		$offset=strrpos($class, '\\');
		$namespace=substr($class, 0, $offset);
		$short=substr($class, $offset+1);
		$methods=$definition['methods'] ?? null;
		if(!is_array($methods)){ throw new InvalidArgumentException('Macro methods for '.$class.' must be an object.'); }
		ksort($methods, SORT_STRING);
		$doc=[];
		foreach($definition['templates'] ?? [] as $template){
			if(!is_string($template) || preg_match('/^@template(?:-covariant|-contravariant)?\s+[A-Za-z_][A-Za-z0-9_]*(?:\s+of\s+.+)?$/D', $template)!==1 || str_contains($template, '*/')){
				throw new InvalidArgumentException('Macro template for '.$class.' is invalid.');
			}
			$doc[]=$template;
		}
		foreach($methods as $method=>$methodDefinition){
			$method=dp_panel_static_analysis_assert_identifier((string)$method, 'Macro method');
			if(!is_array($methodDefinition)){ throw new InvalidArgumentException('Macro method '.$class.'::'.$method.' must be an object.'); }
			$return=dp_panel_static_analysis_assert_type((string)($methodDefinition['return'] ?? 'mixed'), 'Macro return type');
			$parameters=$methodDefinition['parameters'] ?? [];
			if(!is_array($parameters)){ throw new InvalidArgumentException('Macro parameters for '.$class.'::'.$method.' must be a list.'); }
			$parts=[];
			foreach($parameters as $parameter){
				if(!is_array($parameter)){ throw new InvalidArgumentException('Macro parameter for '.$class.'::'.$method.' must be an object.'); }
				$name=dp_panel_static_analysis_assert_identifier((string)($parameter['name'] ?? ''), 'Macro parameter');
				$type=dp_panel_static_analysis_assert_type((string)($parameter['type'] ?? 'mixed'), 'Macro parameter type');
				$prefix=!empty($parameter['by_reference']) ? '&' : '';
				$prefix.=!empty($parameter['variadic']) ? '...' : '';
				$part=$type.' '.$prefix.'$'.$name;
				if(!empty($parameter['optional']) && empty($parameter['variadic'])){
					$default=(string)($parameter['default'] ?? 'null');
					if(str_contains($default, "\n") || str_contains($default, "\r") || str_contains($default, '*/')){ throw new InvalidArgumentException('Macro default value is unsafe.'); }
					$part.=' = '.$default;
				}
				$parts[]=$part;
			}
			$doc[]='@method '.(!empty($methodDefinition['static']) ? 'static ' : '').$return.' '.$method.'('.implode(', ', $parts).')';
		}
		$output.="\nnamespace ".$namespace." {\n";
		if($doc!==[]){
			$output.="\t/**\n";
			foreach($doc as $line){ $output.="\t * ".$line."\n"; }
			$output.="\t */\n";
		}
		$output.="\tclass ".$short." {}\n}\n";
	}
	return $output;
}

/** @return array<string,mixed> */
function dp_panel_static_analysis_read_json(string $path): array {
	$encoded=@file_get_contents($path);
	if($encoded===false){ throw new RuntimeException('Unable to read JSON file '.$path); }
	$value=json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
	if(!is_array($value)){ throw new InvalidArgumentException('JSON root must be an object.'); }
	return $value;
}

function dp_panel_static_analysis_write(string $path, string $contents): void {
	$directory=dirname($path);
	if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){ throw new RuntimeException('Unable to create '.$directory); }
	if(file_put_contents($path, $contents)===false){ throw new RuntimeException('Unable to write '.$path); }
}

function dp_panel_static_analysis_option(array $argv, string $name): ?string {
	$prefix='--'.$name.'=';
	foreach($argv as $argument){ if(str_starts_with((string)$argument, $prefix)){ return substr((string)$argument, strlen($prefix)); } }
	return null;
}

function dp_panel_static_analysis_usage(): string {
	return implode("\n", [
		'Usage: php dev/tools/panel_static_analysis.php [check|dump-contract|update-contract|generate-stubs] [options]',
		'  check                                      Verify the checked generic/callback inventory (default).',
		'  dump-contract                              Print the deterministic contract JSON.',
		'  update-contract [--output=PATH]             Regenerate the checked contract.',
		'  generate-stubs --manifest=PATH [--output=PATH]  Generate deterministic macro stubs.',
		'',
	]);
}

function dp_panel_static_analysis_early_result(array $argv, string $sapi): ?int {
	if($sapi!=='cli'){
		http_response_code(404);
		echo "Panel static-analysis tooling is only available from CLI.\n";
		return 2;
	}
	if(in_array('--help', $argv, true) || in_array('-h', $argv, true) || in_array('help', $argv, true)){
		echo dp_panel_static_analysis_usage();
		return 0;
	}
	return null;
}

function dp_panel_static_analysis_main(array $argv): int {
	$command=(string)($argv[1] ?? 'check');
	try{
		if($command==='check'){
			$failures=dp_panel_static_analysis_check();
			if($failures!==[]){ foreach($failures as $failure){ fwrite(STDERR, '[FAIL] '.$failure."\n"); } return 1; }
			echo 'Panel static-analysis contract is current ('.count(dp_panel_static_analysis_targets())." builders).\n";
			return 0;
		}
		if($command==='dump-contract'){
			echo dp_panel_static_analysis_json(dp_panel_static_analysis_contract());
			return 0;
		}
		if($command==='update-contract'){
			$path=dp_panel_static_analysis_option($argv, 'output') ?? dp_panel_static_analysis_contract_path();
			dp_panel_static_analysis_write($path, dp_panel_static_analysis_json(dp_panel_static_analysis_contract()));
			echo 'Wrote '.$path."\n";
			return 0;
		}
		if($command==='generate-stubs'){
			$manifest=dp_panel_static_analysis_option($argv, 'manifest');
			if($manifest===null || $manifest===''){ throw new InvalidArgumentException('generate-stubs requires --manifest=PATH.'); }
			$stubs=dp_panel_static_analysis_macro_stubs(dp_panel_static_analysis_read_json($manifest));
			$output=dp_panel_static_analysis_option($argv, 'output');
			if($output===null){ echo $stubs; }
			else { dp_panel_static_analysis_write($output, $stubs); echo 'Wrote '.$output."\n"; }
			return 0;
		}
		fwrite(STDERR, 'Unknown command: '.$command."\n".dp_panel_static_analysis_usage());
		return 2;
	}
	catch(Throwable $exception){
		fwrite(STDERR, '[ERROR] '.$exception->getMessage()."\n");
		return 2;
	}
}

if(!defined('DATAPHYRE_PANEL_STATIC_ANALYSIS_EMBEDDED')){
	$earlyResult=dp_panel_static_analysis_early_result($argv ?? [], PHP_SAPI);
	if($earlyResult!==null){ exit($earlyResult); }
	exit(dp_panel_static_analysis_main($argv ?? []));
}
