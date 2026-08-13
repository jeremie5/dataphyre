<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/**
 * Removes phpdbg line-table artifacts that do not represent executable PHP.
 *
 * phpdbg can expose brace-only lines and finally headers as executable op-array
 * locations, and PHP 8.4 can label declaration-only `never` interface methods
 * as executable. It can also leave a variable-only continuation line red after the
 * enclosing call was executed. Their bodies and branch-bearing expressions
 * remain in the denominator; the structural artifact does not. Switch labels
 * deliberately remain executable because their comparison can represent an
 * untaken branch. Every ignored line is returned with a reason so an exact
 * report remains auditable.
 */
final class CoverageLineNormalizer {

	/**
	 * @param list<int> $executableLines
	 * @param list<int> $coveredLines
	 * @return array{
	 *   raw_executable_lines:list<int>,
	 *   executable_lines:list<int>,
	 *   covered_lines:list<int>,
	 *   ignored_lines:list<int>,
	 *   ignored_by_reason:array<string,list<int>>
	 * }
	 */
	public static function phpdbg(string $file, array $executableLines, array $coveredLines): array {
		$raw=self::positiveUnique($executableLines);
		$covered=array_values(array_intersect(self::positiveUnique($coveredLines), $raw));
		$source=is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
		$ignored=[];
		$byReason=[];
		if(is_array($source)){
			$coveredLookup=array_fill_keys($covered,true);
			$argumentCandidates=[];
			foreach($raw as $line){
				$text=$source[$line-1] ?? null;
				if(!isset($coveredLookup[$line])&&is_string($text)&&self::isSimpleArgumentContinuation($text)){
					$argumentCandidates[$line]=true;
				}
			}
			$argumentGroups=self::smallestMultilineGroupsContaining($source,$argumentCandidates);
			foreach($raw as $line){
				$text=$source[$line-1] ?? null;
				if(!is_string($text)){continue;}
				$reason=self::structuralReason($text);
				if($reason===null && !isset($coveredLookup[$line])){
					$reason=self::coveredSwitchLabelReason($source,$line,$coveredLookup);
				}
				if($reason===null && !isset($coveredLookup[$line])){
					$reason=self::coveredSimpleArgumentContinuationReason($line,$coveredLookup,$argumentGroups);
				}
				if($reason===null){continue;}
				$ignored[]=$line;
				$byReason[$reason][]=$line;
			}
		}
		$executable=array_values(array_diff($raw, $ignored));
		$covered=array_values(array_intersect($covered, $executable));
		sort($executable, SORT_NUMERIC);
		sort($covered, SORT_NUMERIC);
		sort($ignored, SORT_NUMERIC);
		ksort($byReason, SORT_STRING);
		return [
			'raw_executable_lines'=>$raw,
			'executable_lines'=>$executable,
			'covered_lines'=>$covered,
			'ignored_lines'=>$ignored,
			'ignored_by_reason'=>$byReason,
		];
	}

	/**
	 * Returns a stable reason only when a physical line is exclusively a known
	 * structural token. Switch labels and executable statements such as
	 * break/return/catch/else are preserved.
	 */
	public static function structuralReason(string $line): ?string {
		$tokens=token_get_all("<?php ".$line);
		$semantic=[];
		foreach($tokens as $token){
			if(is_array($token)){
				[$id,$text]=$token;
				if(in_array($id, [T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)){continue;}
				$semantic[]=['id'=>$id, 'text'=>$text];
				continue;
			}
			$semantic[]=['id'=>null, 'text'=>$token];
		}
		if($semantic===[]){return null;}

		if(count($semantic)===1
			&& $semantic[0]['id']===null
			&& in_array($semantic[0]['text'], ['{','}'], true)){
			return 'brace-only';
		}

		$hasFunction=false;
		$hasBody=false;
		foreach($semantic as $token){
			if($token['id']===T_FUNCTION){$hasFunction=true;}
			if($token['id']===null && $token['text']==='{'){$hasBody=true;}
		}
		$last=$semantic[array_key_last($semantic)];
		if($hasFunction && !$hasBody && $last['id']===null && $last['text']===';'){
			return 'declaration-only-method';
		}

		$finally=0;
		$finallyOnly=true;
		foreach($semantic as $token){
			if($token['id']===T_FINALLY){$finally++;continue;}
			if($token['id']!==null || !in_array($token['text'], ['{','}'], true)){$finallyOnly=false;break;}
		}
		if($finallyOnly && $finally===1){return 'finally-header';}
		return null;
	}

	/**
	 * phpdbg can leave switch labels red after entering their arm. A consecutive
	 * alias-label chain has no executable body of its own, so every label in the
	 * chain is ignored once a covered line proves that shared arm ran. An arm
	 * with its own unexecuted body remains in the denominator.
	 *
	 * @param list<string> $source
	 * @param array<int,bool> $covered
	 */
	private static function coveredSwitchLabelReason(array $source,int $line,array $covered): ?string {
		$label=$source[$line-1] ?? '';
		if(!self::isBareSwitchLabel($label)){return null;}
		preg_match('/^[ \t]*/',$label,$indentMatch);
		$labelIndent=strlen($indentMatch[0] ?? '');
		$sawBody=false;
		for($next=$line+1,$count=count($source);$next<=$count;$next++){
			$text=$source[$next-1];
			$trimmed=trim($text);
			if($trimmed===''||str_starts_with($trimmed,'//')||str_starts_with($trimmed,'#')||str_starts_with($trimmed,'/*')||str_starts_with($trimmed,'*')){continue;}
			preg_match('/^[ \t]*/',$text,$nextIndentMatch);
			$nextIndent=strlen($nextIndentMatch[0] ?? '');
			if(self::isBareSwitchLabel($text)&&$nextIndent<=$labelIndent){
				if($sawBody){return null;}
				continue;
			}
			if(preg_match('/^\}\s*;?$/',$trimmed)===1&&$nextIndent<=$labelIndent){return null;}
			if(isset($covered[$next])){return 'covered-switch-label';}
			$sawBody=true;
		}
		return null;
	}

	private static function isBareSwitchLabel(string $line): bool {
		$tokens=token_get_all('<?php '.$line);
		$semantic=[];
		foreach($tokens as $token){
			if(is_array($token)){
				if(in_array($token[0],[T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){continue;}
				$semantic[]=['id'=>$token[0],'text'=>$token[1]];
			}else{$semantic[]=['id'=>null,'text'=>$token];}
		}
		if(count($semantic)===2&&$semantic[0]['id']===T_DEFAULT&&$semantic[1]['text']===':'){return true;}
		return count($semantic)>=3&&$semantic[0]['id']===T_CASE&&$semantic[array_key_last($semantic)]['text']===':';
	}

	/**
	 * Normalizes phpdbg's false-red location for a branch-free argument line.
	 *
	 * The candidate must contain only a variable with optional closing call/array
	 * punctuation, and a covered physical line must exist inside the same
	 * smallest multiline parenthesis or bracket group. This intentionally does
	 * not normalize calls, operators, ternaries, coalescing expressions, array
	 * reads, or literals: those can carry independently untested behavior.
	 *
	 * @param array<int,bool> $covered
	 * @param array<int,array{start:int,end:int}> $groups
	 */
	private static function coveredSimpleArgumentContinuationReason(int $line,array $covered,array $groups): ?string {
		$group=$groups[$line] ?? null;
		if($group===null){return null;}
		for($candidate=$group['start']+1;$candidate<$group['end'];$candidate++){
			if($candidate!==$line&&isset($covered[$candidate])){return 'covered-simple-argument-continuation';}
		}
		return null;
	}

	private static function isSimpleArgumentContinuation(string $line): bool {
		$tokens=token_get_all('<?php '.$line);
		$variableCount=0;
		$punctuationCount=0;
		foreach($tokens as $token){
			if(is_array($token)){
				if(in_array($token[0],[T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){continue;}
				if($token[0]!==T_VARIABLE){return false;}
				$variableCount++;
				continue;
			}
			if(!in_array($token,[',',']',')',';'],true)){return false;}
			$punctuationCount++;
		}
		return $variableCount===1;
	}

	/**
	 * @param list<string> $source
	 * @param array<int,bool> $targetLines
	 * @return array<int,array{start:int,end:int}>
	 */
	private static function smallestMultilineGroupsContaining(array $source,array $targetLines): array {
		if($targetLines===[]){return [];}
		$tokens=token_get_all(implode("\n",$source));
		$line=1;
		$stack=[];
		$groups=[];
		foreach($tokens as $token){
			$text=is_array($token)?$token[1]:$token;
			$tokenLine=is_array($token)?$token[2]:$line;
			if(!is_array($token)&&($token==='('||$token==='[')){
				$stack[]=['token'=>$token,'line'=>$tokenLine];
			}
			elseif(!is_array($token)&&($token===')'||$token===']')){
				$expected=$token===')'?'(': '[';
				$opening=array_pop($stack);
				if(is_array($opening)&&$opening['token']===$expected&&$opening['line']<$tokenLine){
					$span=$tokenLine-$opening['line'];
					foreach($targetLines as $targetLine=>$_){
						if($opening['line']>$targetLine||$targetLine>$tokenLine){continue;}
						$current=$groups[$targetLine] ?? null;
						if($current===null||$span<($current['end']-$current['start'])){
							$groups[$targetLine]=['start'=>$opening['line'],'end'=>$tokenLine];
						}
					}
				}
			}
			$line=$tokenLine+substr_count($text,"\n");
		}
		ksort($groups,SORT_NUMERIC);
		return $groups;
	}

	/** @param list<int> $lines @return list<int> */
	private static function positiveUnique(array $lines): array {
		$lines=array_values(array_unique(array_filter(array_map('intval', $lines), static fn(int $line): bool=>$line>0)));
		sort($lines, SORT_NUMERIC);
		return $lines;
	}
}
