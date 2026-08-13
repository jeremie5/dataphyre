<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait AssertsDomains {

	/** Asserts a shell-free process completed normally and returns it for fluent decoding. */
	public function processSucceeded(ProcessResult $process, string $message=''): ProcessResult {
		$this->recordAssertion();
		if(!$process->succeeded()){
			$this->fail(
				$message!=='' ? $message : 'Expected child process to succeed.',
				['exit_code'=>0, 'timed_out'=>false],
					$process->diagnostic()
			);
		}
		return $process;
	}

	/** Asserts a shell-free process failed, optionally with one exact exit code. */
	public function processFailed(ProcessResult $process, ?int $exit_code=null, string $message=''): ProcessResult {
		$this->recordAssertion();
		$failed=!$process->succeeded() && ($exit_code===null || $process->exitCode()===$exit_code);
		if(!$failed){
			$this->fail(
				$message!=='' ? $message : 'Expected child process to fail.',
				['succeeded'=>false, 'exit_code'=>$exit_code],
					['succeeded'=>$process->succeeded()]+$process->diagnostic()
			);
		}
		return $process;
	}

	/** @param array<string,mixed>|object $response */
	public function responseStatus(int $expected, array|object $response, string $message=''): void {
		$this->same($expected, (int)$this->responseValue($response, ['status', 'status_code', 'code'], 0), $message!=='' ? $message : 'Expected response status to match.');
	}

	/** @param array<string,mixed>|object $response */
	public function responseHeader(string $name, string $expected, array|object $response, string $message=''): void {
		$this->recordAssertion();
		$headers=$this->responseHeaders($response);
		$key=strtolower($name);
		$actual=$headers[$key] ?? null;
		if((string)$actual!==$expected){
			$this->fail($message!=='' ? $message : 'Expected response header to match.', [$name=>$expected], [$name=>$actual]);
		}
	}

	/** @param array<string,mixed>|object $response */
	public function responseJsonPath(string|array $path, mixed $expected, array|object $response, string $message=''): void {
		$this->pathEquals($path, $expected, $this->responseJson($response), $message!=='' ? $message : 'Expected response JSON path to match.');
	}

	/** @param array<string,mixed>|object $response @param array<mixed> $expected */
	public function responseJsonSubset(array $expected, array|object $response, string $message=''): void {
		$this->subset($expected, $this->responseJson($response), $message!=='' ? $message : 'Expected response JSON to contain subset.');
	}

	/** @param array<string,mixed>|object $surface */
	public function panelHasField(array|object $surface, string $name, string $message=''): void {
		$this->hasNamedItem('field', $name, $this->surfaceItems($surface, ['fields', 'columns']), $message);
	}

	/** @param array<string,mixed>|object $surface */
	public function panelHasFilter(array|object $surface, string $name, string $message=''): void {
		$this->hasNamedItem('filter', $name, $this->surfaceItems($surface, ['filters']), $message);
	}

	/** @param array<string,mixed>|object $surface */
	public function panelHasAction(array|object $surface, string $name, string $message=''): void {
		$this->hasNamedItem('action', $name, $this->surfaceItems($surface, ['actions', 'bulk_actions', 'record_actions']), $message);
	}

	public function schemaHasColumn(array|object|string $schema, string $column, string $message=''): void {
		$this->contains($column, $this->schemaColumns($schema), $message!=='' ? $message : 'Expected schema to declare column.');
	}

	/** @param array<string,mixed> $query */
	public function queryMatches(array $query, string $pattern, ?array $bindings=null, string $message=''): void {
		$sql=(string)($query['sql'] ?? $query['query'] ?? '');
		$this->matches($pattern, $sql, $message!=='' ? $message : 'Expected query SQL to match pattern.');
		if($bindings!==null){
			$this->same($bindings, (array)($query['bindings'] ?? []), $message!=='' ? $message : 'Expected query bindings to match.');
		}
	}

	/** @param array<int,array<string,mixed>> $trace @param array<string,mixed> $subset */
	public function traceContains(array $trace, string $type, array $subset=[], string $message=''): void {
		$this->recordContains($trace, ['type'=>$type]+$subset, $message!=='' ? $message : 'Expected trace to contain matching entry.');
	}

	/** @param array<int,array<string,mixed>> $events @param array<string,mixed> $subset */
	public function eventContains(array $events, string $name, array $subset=[], string $message=''): void {
		$this->recordContains($events, ['name'=>$name]+$subset, $message!=='' ? $message : 'Expected event list to contain matching entry.');
	}

	public function htmlContainsText(string $html, string $text, string $message=''): void {
		$this->recordAssertion();
		$actual=trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES|ENT_HTML5, 'UTF-8')) ?? '');
		if(!str_contains($actual, $text)){
			$this->fail($message!=='' ? $message : 'Expected HTML text to contain value.', $text, $actual);
		}
	}

	public function htmlHasSelector(string $html, string $selector, string $message=''): void {
		$this->recordAssertion();
		$matches=HtmlProbe::matches($html, $selector);
		if($matches===[]){
			$this->fail($message!=='' ? $message : 'Expected HTML selector to match.', $selector, HtmlProbe::shape($html));
		}
	}

	public function htmlMissingSelector(string $html, string $selector, string $message=''): void {
		$this->recordAssertion();
		$matches=HtmlProbe::matches($html, $selector);
		if($matches!==[]){
			$this->fail($message!=='' ? $message : 'Expected HTML selector to be absent.', 'missing '.$selector, $matches);
		}
	}

	public function htmlAttribute(string $html, string $selector, string $attribute, string $expected, string $message=''): void {
		$this->recordAssertion();
		$matched=false;
		foreach(HtmlProbe::matches($html, $selector) as $node){
			if((string)($node['attributes'][strtolower($attribute)] ?? '')===$expected){
				$matched=true;
				break;
			}
		}
		if($matched===false){
			$this->fail($message!=='' ? $message : 'Expected HTML selector attribute to match.', [$selector=>$attribute.'='.$expected], HtmlProbe::matches($html, $selector));
		}
	}

	public function tableHas(FakeDatabase|PdoDatabaseAssertions $database, string $table, array $expected, string $message=''): void {
		$database->assertTableHas($this, $table, $expected, $message);
	}

	public function tableMissing(FakeDatabase|PdoDatabaseAssertions $database, string $table, array $expected, string $message=''): void {
		$database->assertTableMissing($this, $table, $expected, $message);
	}

	public function tableCount(FakeDatabase|PdoDatabaseAssertions $database, string $table, int $expected, string $message=''): void {
		$database->assertTableCount($this, $table, $expected, $message);
	}

	public function permits(FakePermissions $permissions, mixed $actor, string $ability, mixed $resource=null, string $message=''): void {
		$this->isTrue($permissions->permits($actor, $ability, $resource), $message!=='' ? $message : 'Expected permission policy to allow ability.');
	}

	public function denies(FakePermissions $permissions, mixed $actor, string $ability, mixed $resource=null, string $message=''): void {
		$this->isFalse($permissions->permits($actor, $ability, $resource), $message!=='' ? $message : 'Expected permission policy to deny ability.');
	}

	/** @param array<string,mixed>|object $response */
	private function responseValue(array|object $response, array $keys, mixed $default=null): mixed {
		foreach($keys as $key){
			if(is_array($response) && array_key_exists($key, $response)){
				return $response[$key];
			}
			if(is_object($response) && isset($response->{$key})){
				return $response->{$key};
			}
		}
		foreach($keys as $key){
			$method=$key;
			if(is_object($response) && method_exists($response, $method)){
				return $response->{$method}();
			}
		}
		return $default;
	}

	/** @param array<string,mixed>|object $response @return array<string,mixed> */
	private function responseHeaders(array|object $response): array {
		$headers=$this->responseValue($response, ['headers'], []);
		$normalized=[];
		foreach(is_array($headers) ? $headers : [] as $key=>$value){
			$normalized[strtolower((string)$key)]=$value;
		}
		return $normalized;
	}

	/** @param array<string,mixed>|object $response */
	private function responseJson(array|object $response): mixed {
		$body=$this->responseValue($response, ['json', 'body', 'content'], null);
		if(is_string($body)){
			$decoded=json_decode($body, true);
			if(json_last_error()===JSON_ERROR_NONE){
				return $decoded;
			}
		}
		return $body;
	}

	/** @param array<string,mixed>|object $surface @param array<int,string> $keys @return array<int,mixed> */
	private function surfaceItems(array|object $surface, array $keys): array {
		foreach($keys as $key){
			$value=$this->responseValue($surface, [$key], null);
			if(is_array($value)){
				return $value;
			}
		}
		return [];
	}

	/** @param array<int,mixed> $items */
	private function hasNamedItem(string $kind, string $name, array $items, string $message=''): void {
		$this->recordAssertion();
		$found=false;
		foreach($items as $key=>$item){
			if(is_string($key) && $key===$name){
				$found=true;
				break;
			}
			if(is_array($item)){
				foreach(['name', 'key', 'id', 'field', 'action'] as $name_key){
					if((string)($item[$name_key] ?? '')===$name){
						$found=true;
						break 2;
					}
				}
			}
			elseif(is_object($item))
			{
				foreach(['name', 'key', 'id', 'field', 'action'] as $name_key){
					if(isset($item->{$name_key}) && (string)$item->{$name_key}===$name){
						$found=true;
						break 2;
					}
				}
			}
		}
		if($found===false){
			$this->fail($message!=='' ? $message : 'Expected Panel '.$kind.' to be declared.', $name, $items);
		}
	}

	/** @return array<int,string> */
	private function schemaColumns(array|object|string $schema): array {
		if(is_string($schema) && class_exists($schema) && defined($schema.'::COLUMNS')){
			return array_values(array_map('strval', (array)constant($schema.'::COLUMNS')));
		}
		if(is_object($schema)){
			foreach(['columns', 'fields'] as $method){
				if(method_exists($schema, $method)){
					return array_values(array_map('strval', (array)$schema->{$method}()));
				}
			}
			$schema=get_object_vars($schema);
		}
		if(is_array($schema)){
			foreach(['columns', 'COLUMNS', 'fields'] as $key){
				if(isset($schema[$key]) && is_array($schema[$key])){
					return array_values(array_map('strval', $schema[$key]));
				}
			}
			return array_values(array_map('strval', array_keys($schema)));
		}
		return [];
	}

	/** @param array<int,array<string,mixed>> $records @param array<string,mixed> $expected */
	private function recordContains(array $records, array $expected, string $message): void {
		$this->recordAssertion();
		$found=false;
		foreach($records as $record){
			if($this->subsetMatches($expected, $record)){
				$found=true;
				break;
			}
		}
		if($found===false){
			$this->fail($message, $expected, $records);
		}
	}
}
