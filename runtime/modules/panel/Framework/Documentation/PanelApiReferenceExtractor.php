<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Extracts a deterministic public PHP API model without executing source files.
 *
 * The extractor deliberately uses PHP's tokenizer rather than Reflection so a
 * documentation build cannot run package bootstrap code. Every explicit or
	 * discovered PHP file is realpath-confined to the configured source root.
	 * Explicit non-PHP inputs and every encountered symlink are rejected; ordinary
	 * non-PHP files found during recursive discovery are ignored.
 */
final class PanelApiReferenceExtractor {

	private readonly string $root;

	private function __construct(string $root) {
		if(is_link($root)){ throw new \InvalidArgumentException('Panel API source root cannot be a symbolic link.'); }
		$resolved=realpath($root);
		if($resolved===false || !is_dir($resolved)){
			throw new \InvalidArgumentException('Panel API source root must be an existing directory.');
		}
		$this->root=rtrim(str_replace('\\','/',$resolved),'/');
	}

	public static function make(string $root): self { return new self($root); }
	public function root(): string { return $this->root; }

	/**
	 * @param ?array<int,string> $paths Relative PHP files, or null to scan root.
	 * @return list<array<string,mixed>>
	 */
	public function extract(?array $paths=null): array {
		$files=$paths===null ? $this->discover() : array_map(fn(string $path): string=>$this->resolve($path),$paths);
		$files=array_values(array_unique($files));
		sort($files,SORT_STRING);
		$symbols=[];
		foreach($files as $file){
			array_push($symbols,...$this->extractFile($file));
		}
		usort($symbols,static fn(array $left,array $right): int=>[$left['fqcn'],$left['kind']]<=>[$right['fqcn'],$right['kind']]);
		return $symbols;
	}

	/** @return list<string> */
	private function discover(): array {
		$files=[];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $entry){
			if(!$entry instanceof \SplFileInfo){ continue; }
			if($entry->isLink()){ throw new \RuntimeException('Panel API source discovery encountered a symbolic link.'); }
			if(!$entry->isFile() || strtolower($entry->getExtension())!=='php'){
				continue;
			}
			$files[]=$this->resolve(substr(str_replace('\\','/',$entry->getPathname()),strlen($this->root)+1));
		}
		return $files;
	}

	private function resolve(string $path): string {
		$path=str_replace('\\','/',trim($path));
		if($path==='' || preg_match('/[\x00-\x1F\x7F]/',$path)===1 || str_starts_with($path,'/') || preg_match('/^[A-Za-z]:\//',$path)===1){
			throw new \InvalidArgumentException('Panel API source paths must be relative PHP files.');
		}
		$segments=explode('/',$path);
		if(in_array('..',$segments,true) || in_array('',$segments,true) || strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php'){
			throw new \InvalidArgumentException('Panel API source path is invalid.');
		}
		$candidate=$this->root.'/'.$path;
		self::assertNotLink(is_link($candidate));
		$resolved=realpath($candidate);
		if($resolved===false || !is_file($resolved)){
			throw new \InvalidArgumentException('Panel API source file does not exist.');
		}
		$resolved=str_replace('\\','/',$resolved);
		$root=$this->root;
		$comparison=DIRECTORY_SEPARATOR==='\\' ? strtolower($resolved) : $resolved;
		$rootComparison=DIRECTORY_SEPARATOR==='\\' ? strtolower($root) : $root;
		self::assertContained($comparison,$rootComparison);
		return $resolved;
	}

	private static function assertNotLink(bool $link): void {
		if($link){ throw new \RuntimeException('Panel API source symlinks are not accepted.'); }
	}

	private static function assertContained(string $resolved,string $root): void {
		if(!str_starts_with($resolved,$root.'/')){ throw new \RuntimeException('Panel API source escaped its configured root.'); }
	}

	/** @return list<array<string,mixed>> */
	private function extractFile(string $file): array {
		$source=file_get_contents($file);
		if($source===false){ throw new \RuntimeException('Unable to read Panel API source file.'); }
		// TOKEN_PARSE binds extraction to the host parser and rejects valid API
		// source that targets a newer supported PHP syntax (for example typed
		// class constants or property hooks). Raw tokenization remains
		// non-executing and lets the structural extractor validate the declaration
		// boundaries itself, so documentation can be built consistently across the
		// complete supported runtime matrix.
		$tokens=token_get_all($source);
		$relative=substr(str_replace('\\','/',$file),strlen($this->root)+1);
		$namespace='';
		$namespaceDepth=null;
		$braceDepth=0;
		$braceKinds=[];
		$alternativeDepth=0;
		$alternativeStarts=$this->alternativeSyntaxStarts($tokens);
		$alternativeEnds=array_filter([
			defined('T_ENDIF') ? T_ENDIF : null,defined('T_ENDFOR') ? T_ENDFOR : null,
			defined('T_ENDFOREACH') ? T_ENDFOREACH : null,defined('T_ENDWHILE') ? T_ENDWHILE : null,
			defined('T_ENDSWITCH') ? T_ENDSWITCH : null,defined('T_ENDDECLARE') ? T_ENDDECLARE : null,
		],'is_int');
		$symbols=[];
		$count=count($tokens);
		for($index=0;$index<$count;$index++){
			$token=$tokens[$index];
			$id=is_array($token) ? $token[0] : null;
			if(in_array($id,$alternativeEnds,true)){ $alternativeDepth=max(0,$alternativeDepth-1); continue; }
			if(isset($alternativeStarts[$index])){ $alternativeDepth++; continue; }
			$interpolation=is_array($token) && in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true);
			if($token==='{' || $interpolation){
				$braceDepth++;
				$braceKinds[]=$interpolation ? 'interpolation' : 'structural';
				continue;
			}
			if($token==='}'){
				$kind=array_pop($braceKinds);
				if($kind==='structural' && $namespaceDepth===$braceDepth){ $namespaceDepth=null; $namespace=''; }
				$braceDepth=max(0,$braceDepth-1);
				continue;
			}
			if($id===T_NAMESPACE){
				$namespace=$this->namespaceAt($tokens,$index+1);
				$open=$this->nextCharacter($tokens,$index+1,'{');
				$namespaceDepth=$open===null ? null : $braceDepth+1;
				continue;
			}
			$isType=in_array($id,[T_CLASS,T_INTERFACE,T_TRAIT],true) || (defined('T_ENUM') && $id===T_ENUM);
			$declarationDepth=$namespaceDepth ?? 0;
			if(!$isType || $braceDepth!==$declarationDepth || $alternativeDepth!==0 || $this->anonymousOrClassConstant($tokens,$index)){ continue; }
			$nameIndex=$this->nextToken($tokens,$index+1,T_STRING);
			if($nameIndex===null){ continue; }
			$name=(string)$tokens[$nameIndex][1];
			$open=$this->nextCharacter($tokens,$nameIndex+1,'{');
			if($open===null){ continue; }
			$close=$this->matchingBrace($tokens,$open);
			if($close===null){ throw new \RuntimeException('Panel API type has an unterminated body.'); }
			$kind=$id===T_INTERFACE ? 'interface' : ($id===T_TRAIT ? 'trait' : ((defined('T_ENUM') && $id===T_ENUM) ? 'enum' : 'class'));
			$modifiers=$this->modifiersBefore($tokens,$index);
			$members=$this->members($tokens,$open+1,$close-1,$kind);
			$traitUses=$this->traitUses($tokens,$open+1,$close-1);
			array_push($members,...$traitUses['aliases']);
			usort($members,static fn(array $left,array $right): int=>[$left['kind'],$left['name']]<=>[$right['kind'],$right['name']]);
			$isFinal=in_array(T_FINAL,$modifiers,true);
			$isAbstract=in_array(T_ABSTRACT,$modifiers,true) || $kind==='interface';
			$isReadonly=defined('T_READONLY') && in_array(T_READONLY,$modifiers,true);
			$signature=($isFinal ? 'final ' : '').($isAbstract && $kind==='class' ? 'abstract ' : '').($isReadonly ? 'readonly ' : '').$this->signature(array_slice($tokens,$index,$open-$index));
			$declarationPrefix=$this->declarationPrefix($tokens,$index);
			$doc=$this->declarationDoc($declarationPrefix);
			$attributes=$this->attributesBefore($tokens,$index);
			$relationships=$this->typeRelationships($tokens,$nameIndex+1,$open-1);
			$symbols[]=[
				'name'=>$name,
				'fqcn'=>ltrim($namespace.'\\'.$name,'\\'),
				'namespace'=>$namespace,
				'kind'=>$kind,
				'final'=>$isFinal,
				'abstract'=>$isAbstract,
				'readonly'=>$isReadonly,
				'deprecated'=>stripos($doc,'@deprecated')!==false || $this->deprecatedAttributeTokens($declarationPrefix),
				'internal'=>stripos($doc,'@internal')!==false,
				'attributes'=>$attributes,
				'extends'=>$relationships['extends'],
				'implements'=>$relationships['implements'],
				'uses'=>$traitUses['uses'],
				'trait_adaptations'=>$traitUses['adaptations'],
				'signature'=>$signature,
				'summary'=>$this->summary($doc),
				'source'=>$relative,
				'source_line'=>(int)($token[2] ?? 1),
				'source_sha256'=>hash('sha256',$source),
				'members'=>$members,
			];
			$index=$close;
		}
		return $symbols;
	}

	/** @param array<int,array|string> $tokens */
	private function namespaceAt(array $tokens,int $start): string {
		$value='';
		for($index=$start;$index<count($tokens);$index++){
			$token=$tokens[$index];
			if($token===';' || $token==='{'){ break; }
			if(is_array($token) && in_array($token[0],[T_STRING,T_NS_SEPARATOR,defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1],true)){
				$value.=$token[1];
			}
		}
		return trim($value,'\\');
	}

	/** @param array<int,array|string> $tokens */
	private function anonymousOrClassConstant(array $tokens,int $index): bool {
		$attributeDepth=0;
		for($cursor=$index-1;$cursor>=0;$cursor--){
			$token=$tokens[$cursor];
			if(is_array($token) && in_array($token[0],[T_OPEN_TAG,T_CLOSE_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){ continue; }
			if($token===']'){ $attributeDepth++; continue; }
			if($token==='[' && $attributeDepth>0){ $attributeDepth--; continue; }
			if(is_array($token) && $token[0]===T_ATTRIBUTE && $attributeDepth>0){ $attributeDepth--; continue; }
			if($attributeDepth>0){ continue; }
			return is_array($token) && $token[0]===T_NEW || $token==='::' || (is_array($token) && defined('T_DOUBLE_COLON') && $token[0]===T_DOUBLE_COLON);
		}
		return false;
	}

	/** @param array<int,array|string> $tokens */
	private function nextToken(array $tokens,int $start,int $wanted): ?int {
		for($index=$start;$index<count($tokens);$index++){
			if(is_array($tokens[$index]) && $tokens[$index][0]===$wanted){ return $index; }
			if($tokens[$index]==='{' || $tokens[$index]===';' || $tokens[$index]==='('){ return null; }
		}
		return null;
	}

	/** @param array<int,array|string> $tokens */
	private function nextCharacter(array $tokens,int $start,string $wanted): ?int {
		for($index=$start;$index<count($tokens);$index++){
			if($tokens[$index]===$wanted){ return $index; }
			if($tokens[$index]===';'){ return null; }
		}
		return null;
	}

	/** @param array<int,array|string> $tokens */
	private function matchingBrace(array $tokens,int $open): ?int {
		$depth=0;
		for($index=$open;$index<count($tokens);$index++){
			$token=$tokens[$index];
			$interpolation=is_array($token) && in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true);
			if($token==='{' || $interpolation){ $depth++; }
			elseif($token==='}' && --$depth===0){ return $index; }
		}
		return null;
	}

	/** @param array<int,array|string> $tokens @return list<int> */
	private function modifiersBefore(array $tokens,int $index): array {
		$modifiers=[];
		for($cursor=$index-1;$cursor>=0;$cursor--){
			$token=$tokens[$cursor];
			if($token===';' || $token==='{' || $token==='}'){ break; }
			if(is_array($token) && in_array($token[0],[T_FINAL,T_ABSTRACT,defined('T_READONLY') ? T_READONLY : -1],true)){
				$modifiers[]=$token[0];
			}
		}
		return $modifiers;
	}

	/** @param array<int,array|string> $tokens @return array<int,true> */
	private function alternativeSyntaxStarts(array $tokens): array {
		$starts=[];
		$openers=[T_IF,T_FOR,T_FOREACH,T_WHILE,T_SWITCH,T_DECLARE];
		for($index=0;$index<count($tokens);$index++){
			$token=$tokens[$index];
			if(!is_array($token) || !in_array($token[0],$openers,true)){ continue; }
			$open=null;
			for($cursor=$index+1;$cursor<count($tokens);$cursor++){
				if($tokens[$cursor]==='('){ $open=$cursor; break; } if($tokens[$cursor]===';' || $tokens[$cursor]==='{'){ break; }
			}
			if($open===null){ continue; }
			$depth=1;
			$close=null;
			for($cursor=$open+1;$cursor<count($tokens);$cursor++){
				if($tokens[$cursor]==='('){ $depth++; }
				elseif($tokens[$cursor]===')' && --$depth===0){ $close=$cursor; break; }
			}
			if($close===null){ continue; }
			for($cursor=$close+1;$cursor<count($tokens);$cursor++){
				$current=$tokens[$cursor];
				if(is_array($current) && in_array($current[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){ continue; }
				if($current===':'){ $starts[$cursor]=true; }
				break;
			}
		}
		return $starts;
	}

	/** @param array<int,array|string> $tokens @return array{extends:list<string>,implements:list<string>} */
	private function typeRelationships(array $tokens,int $start,int $end): array {
		$result=['extends'=>[],'implements'=>[]];
		$mode=null;
		$name='';
		$flush=static function() use (&$result,&$mode,&$name): void {
			$name=trim(preg_replace('/\s+/','',$name) ?? '');
			if($mode!==null && $name!=='' && !in_array($name,$result[$mode],true)){ $result[$mode][]=$name; }
			$name='';
		};
		for($index=$start;$index<=$end;$index++){
			$token=$tokens[$index];
			$id=is_array($token) ? $token[0] : null;
			if($id===T_EXTENDS || $id===T_IMPLEMENTS){
				$flush();
				$mode=$id===T_EXTENDS ? 'extends' : 'implements';
				continue;
			}
			if($mode===null){ continue; }
			if($token===','){ $flush(); continue; }
			if(is_array($token) && in_array($id,[T_STRING,T_NS_SEPARATOR,defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1,defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -1,defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : -1],true)){
				$name.=$token[1];
			}
		}
		$flush();
		return $result;
	}

	/** @param array<int,array|string> $tokens @return list<string> */
	private function attributesBefore(array $tokens,int $index): array {
		$start=$index;
		for($cursor=$index-1;$cursor>=0;$cursor--){
			$token=$tokens[$cursor];
			if($token===';' || $token==='{' || $token==='}'){ break; }
			$start=$cursor;
		}
		$attributes=[];
		for($cursor=$start;$cursor<$index;$cursor++){
			$token=$tokens[$cursor];
			if(!is_array($token) || $token[0]!==T_ATTRIBUTE){ continue; }
			$declaration=[$token];
			$depth=1;
			for($cursor++;$cursor<$index;$cursor++){
				$current=$tokens[$cursor];
				$declaration[]=$current;
				if($current==='['){ $depth++; }
				elseif($current===']' && --$depth===0){ break; }
			}
			$attributes[]=$this->signature($declaration);
		}
		return $attributes;
	}

	/** @param array<int,array|string> $tokens */
	private function deprecatedAttributeTokens(array $tokens): bool {
		$attributeDepth=0;
		$roundDepth=0;
		$expectName=false;
		foreach($tokens as $token){
			if(is_array($token) && $token[0]===T_ATTRIBUTE){ $attributeDepth=1; $roundDepth=0; $expectName=true; continue; }
			if($attributeDepth===0){ continue; }
			if($token==='['){ $attributeDepth++; continue; }
			if($token===']'){
				if(--$attributeDepth===0){ $expectName=false; }
				continue;
			}
			if($token==='('){ $roundDepth++; continue; }
			if($token===')'){ $roundDepth=max(0,$roundDepth-1); continue; }
			if($token===',' && $attributeDepth===1 && $roundDepth===0){ $expectName=true; continue; }
			if(!$expectName || $attributeDepth!==1 || $roundDepth!==0 || !is_array($token)){ continue; }
			if(in_array($token[0],[T_STRING,defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1,defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -1],true)){
				$name=strtolower($token[1]);
				if($name==='deprecated' || $name==='\\deprecated'){ return true; }
				$expectName=false;
			}
		}
		return false;
	}

	/** @param array<int,array|string> $tokens @return array{uses:list<string>,aliases:list<array<string,mixed>>,adaptations:list<string>} */
	private function traitUses(array $tokens,int $start,int $end): array {
		$uses=[];
		$aliases=[];
		$adaptationList=[];
		$depth=0;
		for($index=$start;$index<=$end;$index++){
			$token=$tokens[$index];
			$interpolation=is_array($token) && in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true);
			if($token==='{' || $interpolation){ $depth++; continue; }
			if($token==='}'){ $depth=max(0,$depth-1); continue; }
			if($depth!==0 || !is_array($token) || $token[0]!==T_USE){ continue; }
			$declaration=[];
			$bodyOpen=null;
			for($cursor=$index+1;$cursor<=$end;$cursor++){
				$current=$tokens[$cursor];
				if($current===';' || $current==='{'){
					$bodyOpen=$current==='{' ? $cursor : null;
					$index=$cursor;
					break;
				}
				$declaration[]=$current;
			}
			foreach(explode(',',$this->signature($declaration)) as $name){
				$name=trim($name);
				if($name!=='' && !in_array($name,$uses,true)){ $uses[]=$name; }
			}
			if($bodyOpen===null){ continue; }
			$bodyClose=$this->matchingBrace($tokens,$bodyOpen);
			if($bodyClose===null || $bodyClose>$end){ throw new \RuntimeException('Panel API trait adaptation has an unterminated body.'); }
			$adaptations=$this->signature(array_slice($tokens,$bodyOpen+1,$bodyClose-$bodyOpen-1));
			foreach(array_filter(array_map('trim',explode(';',$adaptations))) as $adaptation){ $adaptationList[]=$adaptation.';'; }
			if(preg_match_all('/(?:(?:[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::)?([A-Za-z_][A-Za-z0-9_]*)\s+as\s+(?:(public|protected|private)\s+)?([A-Za-z_][A-Za-z0-9_]*)?\s*;/i',$adaptations,$matches,PREG_SET_ORDER)){
				foreach($matches as $match){
					if(strtolower((string)($match[2] ?? ''))!=='public'){ continue; }
					$name=(string)($match[3] ?? '') ?: (string)$match[1];
					$aliases[]=[
						'kind'=>'trait_alias','name'=>$name,'source_line'=>(int)($token[2] ?? 1),
						'visibility'=>'public','static'=>false,'abstract'=>false,'summary'=>'','deprecated'=>false,
						'signature'=>trim((string)$match[0]),'trait_adaptation'=>true,
					];
				}
			}
			$index=$bodyClose;
		}
		sort($uses,SORT_STRING);
		sort($adaptationList,SORT_STRING);
		return ['uses'=>$uses,'aliases'=>$aliases,'adaptations'=>$adaptationList];
	}

	/** @param array<int,array|string> $tokens @return list<array<string,mixed>> */
	private function members(array $tokens,int $start,int $end,string $typeKind): array {
		$members=[];
		$statement=$start;
		$depth=0;
		for($index=$start;$index<=$end;$index++){
			$token=$tokens[$index];
			$interpolation=is_array($token) && in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true);
			if($token==='{' || $interpolation){
				if($depth===0){
					$declaration=array_slice($tokens,$statement,$index-$statement);
					$ids=array_map(static fn(array|string $item): ?int=>is_array($item) ? $item[0] : null,$declaration);
					if(!$interpolation && in_array(T_VARIABLE,$ids,true) && !in_array(T_FUNCTION,$ids,true) && !in_array(T_USE,$ids,true)){
						$close=$this->matchingBrace($tokens,$index);
						if($close===null || $close>$end){ throw new \RuntimeException('Panel API property hook has an unterminated body.'); }
						$this->appendDeclaration($members,$declaration,$typeKind,$this->propertyHooks(array_slice($tokens,$index+1,$close-$index-1)));
						$index=$close;
						$statement=$index+1;
						continue;
					}
					$this->appendDeclaration($members,$declaration,$typeKind);
				}
				$depth++;
				continue;
			}
			if($token==='}'){
				$depth=max(0,$depth-1);
				if($depth===0){ $statement=$index+1; }
				continue;
			}
			if($token===';' && $depth===0){
				$this->appendDeclaration($members,array_slice($tokens,$statement,$index-$statement),$typeKind);
				$statement=$index+1;
			}
		}
		usort($members,static fn(array $left,array $right): int=>[$left['kind'],$left['name']]<=>[$right['kind'],$right['name']]);
		return $members;
	}

	/** @param list<array<string,mixed>> $members @param array<int,array|string> $declaration */
	private function appendDeclaration(array &$members,array $declaration,string $typeKind,array $hooks=[]): void {
		$ids=array_values(array_map(static fn(array|string $token): ?int=>is_array($token) ? $token[0] : null,$declaration));
		$primary=[];
		foreach([T_FUNCTION,T_CONST,defined('T_CASE') ? T_CASE : -1,T_VARIABLE] as $wanted){
			$position=array_search($wanted,$ids,true);
			if($position!==false){ $primary[]=(int)$position; }
		}
		$modifierIds=array_slice($ids,0,$primary!==[] ? min($primary) : count($ids));
		if(in_array(T_PRIVATE,$modifierIds,true) || in_array(T_PROTECTED,$modifierIds,true)){ return; }
		$primaryBoundary=$primary!==[] ? min($primary) : count($declaration);
		$doc=$this->declarationDoc(array_slice($declaration,0,$primaryBoundary));
		$prefix=array_slice($declaration,0,$primaryBoundary);
		$attributes=$this->attributesBefore($prefix,$primaryBoundary);
		$base=[
			'visibility'=>'public',
			'static'=>in_array(T_STATIC,$modifierIds,true),
			'abstract'=>$typeKind==='interface' || in_array(T_ABSTRACT,$modifierIds,true),
			'summary'=>$this->summary($doc),
			'deprecated'=>stripos($doc,'@deprecated')!==false || $this->deprecatedAttributeTokens($prefix),
			'attributes'=>$attributes,
			'signature'=>$this->signature($declaration),
		];
		$function=array_search(T_FUNCTION,$ids,true);
		if($function!==false){
			$name=$this->nameAfter($declaration,(int)$function+1,T_STRING);
			if($name!==null){
				$members[]=$base+['kind'=>'method','name'=>$name[0],'source_line'=>$name[1]];
				foreach($this->promotedProperties($declaration,(int)$function+1) as $property){
					$members[]=array_replace($base,[
						'kind'=>'property','name'=>$property['name'],'source_line'=>$property['source_line'],
						'static'=>false,'abstract'=>false,'signature'=>$property['signature'],
						'summary'=>$property['summary'],'deprecated'=>$property['deprecated'],'attributes'=>$property['attributes'],
					]);
				}
				return;
			}
		}
		$constant=array_search(T_CONST,$ids,true);
		if($constant!==false){
			foreach($this->constantNames($declaration,(int)$constant+1) as $name){ $members[]=$base+['kind'=>'constant','name'=>$name[0],'source_line'=>$name[1]]; }
			return;
		}
		$case=array_search(defined('T_CASE') ? T_CASE : -1,$ids,true);
		if($case!==false){
			$name=$this->nameAfter($declaration,(int)$case+1,T_STRING);
			if($name!==null){ $members[]=$base+['kind'=>'case','name'=>$name[0],'source_line'=>$name[1]]; }
			return;
		}
		foreach($declaration as $token){
			if(is_array($token) && $token[0]===T_VARIABLE){
				$property=$base+['kind'=>'property','name'=>ltrim($token[1],'$'),'source_line'=>(int)$token[2]];
				if($hooks!==[]){
					$property['has_hooks']=true;
					$property['hooks']=$hooks;
					$property['signature']=$base['signature'].' { '.implode(' ',array_map(static fn(array $hook): string=>$hook['signature'].';',$hooks)).' }';
				}
				$members[]=$property;
			}
		}
	}

	/** @param array<int,array|string> $tokens @return list<array{name:string,signature:string}> */
	private function propertyHooks(array $tokens): array {
		$hooks=[];
		$depth=0;
		$attributeDepth=0;
		$statementStart=0;
		for($index=0;$index<count($tokens);$index++){
			$token=$tokens[$index];
			if(is_array($token) && $token[0]===T_ATTRIBUTE){ $attributeDepth++; continue; }
			if($token==='[' && $attributeDepth>0){ $attributeDepth++; continue; }
			if($token===']' && $attributeDepth>0){ $attributeDepth--; continue; }
			$interpolation=is_array($token) && in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true);
			if($token==='{' || $interpolation){ $depth++; continue; }
			if($token==='}'){ $depth=max(0,$depth-1); continue; }
			if($depth!==0 || $attributeDepth!==0 || !is_array($token) || $token[0]!==T_STRING || !in_array(strtolower($token[1]),['get','set'],true)){ continue; }
			$delimiter=null;
			for($cursor=$index;$cursor<count($tokens);$cursor++){
				$current=$tokens[$cursor];
				if($current==='{' || $current===';' || (is_array($current) && $current[0]===T_DOUBLE_ARROW)){ $delimiter=$cursor; break; }
			}
			$declaration=$delimiter===null ? array_slice($tokens,$statementStart) : array_slice($tokens,$statementStart,$delimiter-$statementStart);
			$hooks[]=['name'=>strtolower($token[1]),'signature'=>$this->signature($declaration),'attributes'=>$this->attributesBefore($declaration,count($declaration))];
			if($delimiter===null){ continue; }
			$current=$tokens[$delimiter];
			if($current==='{'){
				$close=$this->matchingBrace($tokens,$delimiter);
				if($close===null){ throw new \RuntimeException('Panel API property hook has an unterminated body.'); }
				$index=$close;
				$statementStart=$close+1;
				continue;
			}
			if(is_array($current) && $current[0]===T_DOUBLE_ARROW){
				$round=0;
				$square=0;
				$curly=0;
				for($cursor=$delimiter+1;$cursor<count($tokens);$cursor++){
					$part=$tokens[$cursor];
					$interpolation=is_array($part) && in_array($part[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true);
					if($part==='('){ $round++; continue; }
					if($part===')'){ $round=max(0,$round-1); continue; }
					if($part==='['){ $square++; continue; }
					if($part===']'){ $square=max(0,$square-1); continue; }
					if($part==='{' || $interpolation){ $curly++; continue; }
					if($part==='}'){ $curly=max(0,$curly-1); continue; }
					if($part===';' && $round===0 && $square===0 && $curly===0){ $index=$cursor; $statementStart=$cursor+1; break; }
				}
			}
			else{
				$index=$delimiter;
				$statementStart=$delimiter+1;
			}
		}
		return $hooks;
	}

	/** @param array<int,array|string> $tokens @return ?array{0:string,1:int} */
	private function nameAfter(array $tokens,int $start,int $wanted): ?array {
		for($index=$start;$index<count($tokens);$index++){
			$token=$tokens[$index];
			if(is_array($token) && $token[0]===$wanted){ return [$token[1],(int)$token[2]]; }
			if($token==='(' || $token===';' || $token==='{'){ return null; }
		}
		return null;
	}

	/** @param array<int,array|string> $tokens @return list<array{name:string,source_line:int,signature:string,summary:string,deprecated:bool,attributes:list<string>}> */
	private function promotedProperties(array $tokens,int $start): array {
		$open=null;
		for($index=$start;$index<count($tokens);$index++){
			if($tokens[$index]==='('){ $open=$index; break; }
		}
		if($open===null){ return []; }
		$round=1;
		$square=0;
		$curly=0;
		$properties=[];
		$segment=[];
		for($index=$open+1;$index<count($tokens);$index++){
			$token=$tokens[$index];
			if($token==='('){ $round++; $segment[]=$token; continue; }
			if($token===')'){
				if(--$round===0){ $this->appendPromotedProperty($properties,$segment); break; }
				$segment[]=$token; continue;
			}
			if($token==='['){ $square++; $segment[]=$token; continue; }
			if($token===']'){ $square=max(0,$square-1); $segment[]=$token; continue; }
			if($token==='{'){ $curly++; $segment[]=$token; continue; }
			if($token==='}'){ $curly=max(0,$curly-1); $segment[]=$token; continue; }
			if($token===',' && $round===1 && $square===0 && $curly===0){
				$this->appendPromotedProperty($properties,$segment);
				$segment=[];
				continue;
			}
			$segment[]=$token;
		}
		return $properties;
	}

	/** @param list<array{name:string,source_line:int,signature:string,summary:string,deprecated:bool,attributes:list<string>}> $properties @param array<int,array|string> $segment */
	private function appendPromotedProperty(array &$properties,array $segment): void {
		$ids=array_map(static fn(array|string $token): ?int=>is_array($token) ? $token[0] : null,$segment);
		if(!in_array(T_PUBLIC,$ids,true) || in_array(T_PRIVATE,$ids,true) || in_array(T_PROTECTED,$ids,true)){ return; }
		foreach($segment as $token){
			if(is_array($token) && $token[0]===T_VARIABLE){
				$doc=$this->declarationDoc($segment);
				$attributes=$this->attributesBefore($segment,count($segment));
				$properties[]=[
					'name'=>ltrim($token[1],'$'),'source_line'=>(int)$token[2],'signature'=>$this->signature($segment),
					'summary'=>$this->summary($doc),'deprecated'=>stripos($doc,'@deprecated')!==false || $this->deprecatedAttributeTokens($segment),
					'attributes'=>$attributes,
				];
				return;
			}
		}
	}

	/** @param array<int,array|string> $tokens @return list<array{0:string,1:int}> */
	private function constantNames(array $tokens,int $start): array {
		$names=[];
		$candidate=null;
		$readingName=true;
		$round=0;
		$square=0;
		$curly=0;
		foreach(array_slice($tokens,$start) as $token){
			if($readingName && is_array($token) && $token[0]===T_STRING){ $candidate=[$token[1],(int)$token[2]]; continue; }
			if($token==='('){ $round++; continue; }
			if($token===')'){ $round=max(0,$round-1); continue; }
			if($token==='['){ $square++; continue; }
			if($token===']'){ $square=max(0,$square-1); continue; }
			if($token==='{'){ $curly++; continue; }
			if($token==='}'){ $curly=max(0,$curly-1); continue; }
			if($token==='=' && $readingName && $candidate!==null){
				$names[]=$candidate;
				$candidate=null;
				$readingName=false;
				continue;
			}
			if($token===',' && $round===0 && $square===0 && $curly===0){
				$readingName=true;
				$candidate=null;
			}
		}
		if($readingName && $candidate!==null){ $names[]=$candidate; }
		return $names;
	}

	/** @param array<int,array|string> $tokens @return array<int,array|string> */
	private function declarationPrefix(array $tokens,int $index): array {
		$start=0;
		for($cursor=$index-1;$cursor>=0;$cursor--){
			if($tokens[$cursor]===';' || $tokens[$cursor]==='{' || $tokens[$cursor]==='}'){ $start=$cursor+1; break; }
		}
		return array_slice($tokens,$start,$index-$start);
	}

	/** @param array<int,array|string> $tokens */
	private function declarationDoc(array $tokens): string {
		$doc='';
		$attributeDepth=0;
		foreach($tokens as $token){
			if(is_array($token) && $token[0]===T_ATTRIBUTE){ $attributeDepth++; continue; }
			if($token==='[' && $attributeDepth>0){ $attributeDepth++; continue; }
			if($token===']' && $attributeDepth>0){ $attributeDepth--; continue; }
			if($attributeDepth===0 && is_array($token) && $token[0]===T_DOC_COMMENT){ $doc=$token[1]; }
		}
		return $doc;
	}

	private function summary(string $doc): string {
		if($doc===''){ return ''; }
		$lines=preg_split('/\R/',preg_replace('/^\/\*\*|\*\/$/','',$doc) ?? '') ?: [];
		$summary=[];
		foreach($lines as $line){
			$line=trim((string)preg_replace('/^\s*\*\s?/','',$line));
			if($line==='' && $summary!==[]){ break; }
			if($line==='' || str_starts_with($line,'@')){ continue; }
			$summary[]=$line;
		}
		return trim(implode(' ',$summary));
	}

	/** @param array<int,array|string> $tokens */
	private function signature(array $tokens): string {
		$value='';
		$pendingSpace=false;
		$previous='';
		$punctuation='(),:|?=&';
		foreach($tokens as $token){
			if(is_array($token) && in_array($token[0],[T_DOC_COMMENT,T_COMMENT],true)){ $pendingSpace=true; continue; }
			if(is_array($token) && $token[0]===T_WHITESPACE){ $pendingSpace=true; continue; }
			$text=is_array($token) ? $token[1] : $token;
			if($pendingSpace && $value!=='' && !str_contains($punctuation,substr($previous,-1)) && !str_contains($punctuation,substr($text,0,1))){ $value.=' '; }
			if($value!=='' && str_contains($punctuation,substr($text,0,1))){ $value=rtrim($value,' '); }
			$value.=$text;
			$previous=$text;
			$pendingSpace=false;
		}
		return trim($value);
	}
}
