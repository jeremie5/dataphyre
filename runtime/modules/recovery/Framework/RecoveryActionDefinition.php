<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use InvalidArgumentException;
use JsonSerializable;

/** Permission- and scope-aware definition for one allowlisted corrective action. */
final class RecoveryActionDefinition implements JsonSerializable {
	private LocalizedText $label;
	private ?LocalizedText $description;
	/** @var array<int,string> */
	private array $requiredPermissions;
	/** @var array<int,string> */
	private array $scopeTypes;
	/** @var array<string,int|float|string|bool|null> */
	private array $meta;
	private mixed $eligibility;
	private mixed $hrefResolver;

	/** @param string|array<string,string>|LocalizedText $label @param array<string,mixed> $options */
	public function __construct(private string $id, string|array|LocalizedText $label, array $options=[]) {
		$this->id=self::identifier($this->id, 'Recovery action id');
		$this->label=LocalizedText::from($label);
		$description=$options['description'] ?? null;
		$this->description=$description===null || $description==='' ? null : LocalizedText::from($description);
		$this->kind=self::identifier((string)($options['kind'] ?? 'navigate'), 'Recovery action kind');
		$this->href=self::safeHref($options['href'] ?? null);
		$this->method=strtoupper(trim((string)($options['method'] ?? 'GET'))) ?: 'GET';
		if(!in_array($this->method, ['GET','POST','PUT','PATCH','DELETE'], true)){
			throw new InvalidArgumentException('Recovery action method is unsupported.');
		}
		$this->requiredPermissions=self::identifiers($options['required_permissions'] ?? []);
		$this->scopeTypes=self::identifiers($options['scope_types'] ?? []);
		$this->confirmationRequired=($options['confirmation_required'] ?? false)===true;
		$this->retrySafe=($options['retry_safe'] ?? false)===true;
		$this->meta=self::safeMeta($options['meta'] ?? []);
		$this->eligibility=is_callable($options['eligibility'] ?? null) ? $options['eligibility'] : null;
		$this->hrefResolver=is_callable($options['href_resolver'] ?? null) ? $options['href_resolver'] : null;
	}

	private string $kind;
	private ?string $href;
	private string $method;
	private bool $confirmationRequired;
	private bool $retrySafe;

	public function id(): string {
		return $this->id;
	}

	public function resolve(RecoveryContext $context): ?RecoveryAction {
		if(!$context->canAll($this->requiredPermissions)) return null;
		if($this->scopeTypes!==[] && !in_array($context->scopeType(), $this->scopeTypes, true)) return null;
		if(is_callable($this->eligibility) && ($this->eligibility)($context, $this)!==true) return null;
		$href=$this->href;
		if(is_callable($this->hrefResolver)){
			$href=self::safeHref(($this->hrefResolver)($context, $this));
		}
		return new RecoveryAction(
			$this->id,
			$this->kind,
			$this->label->forLocale($context->locale()),
			$this->description?->forLocale($context->locale()) ?? '',
			$href,
			$this->method,
			$this->confirmationRequired,
			$this->retrySafe,
			$this->meta
		);
	}

	public function jsonSerialize(): array {
		return array_filter([
			'id'=>$this->id,
			'kind'=>$this->kind,
			'label'=>$this->label->all(),
			'description'=>$this->description?->all(),
			'href'=>$this->href,
			'method'=>$this->method,
			'required_permissions'=>$this->requiredPermissions,
			'scope_types'=>$this->scopeTypes,
			'confirmation_required'=>$this->confirmationRequired,
			'retry_safe'=>$this->retrySafe,
			'meta'=>$this->meta,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private static function identifier(string $value, string $label): string {
		$value=strtolower(trim($value));
		if(preg_match('/^[a-z][a-z0-9._-]{0,127}$/', $value)!==1){
			throw new InvalidArgumentException($label.' must be a stable identifier.');
		}
		return $value;
	}

	/** @return array<int,string> */
	private static function identifiers(mixed $values): array {
		if(!is_array($values)) return [];
		$normalized=[];
		foreach($values as $value){
			$normalized[]=self::identifier((string)$value, 'Recovery policy value');
		}
		return array_values(array_unique($normalized));
	}

	private static function safeHref(mixed $href): ?string {
		if($href===null || $href==='') return null;
		$href=trim((string)$href);
		if(strlen($href)>2048 || preg_match('/[\x00-\x1F\x7F]/', $href)===1) return null;
		if(str_starts_with($href, '/') && !str_starts_with($href, '//')) return $href;
		$parts=parse_url($href);
		return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
			? $href
			: null;
	}

	/** @return array<string,int|float|string|bool|null> */
	private static function safeMeta(mixed $meta): array {
		if(!is_array($meta)) return [];
		$safe=[];
		foreach($meta as $key=>$value){
			$key=strtolower(trim((string)$key));
			if(preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)===1
				&& preg_match('/(?:password|secret|token|private.?key|card.?number|cvv|cvc|ccv|authorization|cookie)/', $key)!==1
				&& (is_scalar($value) || $value===null)){
				$safe[$key]=is_string($value) ? substr($value, 0, 240) : $value;
			}
		}
		return $safe;
	}
}
