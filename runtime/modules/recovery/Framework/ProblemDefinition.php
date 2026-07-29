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

/** Stable public failure contract registered by a Dataphyre application. */
final class ProblemDefinition implements JsonSerializable {
	public const SEVERITIES=['info','warning','error','critical'];
	public const RETRY_POLICIES=['none','immediate','backoff','reconcile','manual'];
	public const DATA_STATES=['current','stale','unknown','unsafe'];
	public const INCIDENT_POLICIES=['none','aggregate','individual'];

	private LocalizedText $title;
	private LocalizedText $detail;
	private string $typeUri;
	private string $severity;
	private string $helpTopic;
	private ?string $helpUrl;
	private string $retryPolicy;
	private ?int $retryAfterSeconds;
	private string $dataState;
	private string $incidentPolicy;
	/** @var array<int,string> */
	private array $evidenceKeys;
	/** @var array<int,string> */
	private array $fingerprintKeys;
	/** @var array<int,string> */
	private array $actionIds;

	/**
	 * @param string|array<string,string>|LocalizedText $title
	 * @param string|array<string,string>|LocalizedText $detail
	 * @param array<string,mixed> $options
	 */
	public function __construct(
		private string $id,
		string|array|LocalizedText $title,
		private int $httpStatus=500,
		string|array|LocalizedText $detail='An unexpected problem prevented this action from completing.',
		array $options=[]
	) {
		$this->id=self::identifier($this->id);
		if($this->httpStatus<400 || $this->httpStatus>599){
			throw new InvalidArgumentException('Recovery problem HTTP status must be between 400 and 599.');
		}
		$this->title=LocalizedText::from($title);
		$this->detail=LocalizedText::from($detail);
		$this->typeUri=self::normalizeTypeUri($options['type_uri'] ?? null, $this->id);
		$this->severity=self::choice($options['severity'] ?? ($this->httpStatus>=500 ? 'error' : 'warning'), self::SEVERITIES, 'severity');
		$this->helpTopic=self::identifier((string)($options['help_topic'] ?? 'unexpected'));
		$this->helpUrl=self::safeHelpUrl($options['help_url'] ?? null);
		$this->retryPolicy=self::choice($options['retry_policy'] ?? 'none', self::RETRY_POLICIES, 'retry policy');
		$retryAfter=$options['retry_after_seconds'] ?? null;
		$this->retryAfterSeconds=is_numeric($retryAfter) ? max(0, min(86400, (int)$retryAfter)) : null;
		$this->dataState=self::choice($options['data_state'] ?? 'unknown', self::DATA_STATES, 'data state');
		$this->incidentPolicy=self::choice($options['incident_policy'] ?? 'none', self::INCIDENT_POLICIES, 'incident policy');
		$this->evidenceKeys=self::paths($options['evidence_keys'] ?? []);
		$this->fingerprintKeys=self::paths($options['fingerprint_keys'] ?? []);
		$this->actionIds=self::identifiers($options['actions'] ?? []);
	}

	public function id(): string { return $this->id; }
	public function httpStatus(): int { return $this->httpStatus; }
	public function typeUri(): string { return $this->typeUri; }
	public function severity(): string { return $this->severity; }
	public function helpTopic(): string { return $this->helpTopic; }
	public function helpUrl(): ?string { return $this->helpUrl; }
	public function retryPolicy(): string { return $this->retryPolicy; }
	public function retryAfterSeconds(): ?int { return $this->retryAfterSeconds; }
	public function dataState(): string { return $this->dataState; }
	public function incidentPolicy(): string { return $this->incidentPolicy; }
	/** @return array<int,string> */ public function evidenceKeys(): array { return $this->evidenceKeys; }
	/** @return array<int,string> */ public function fingerprintKeys(): array { return $this->fingerprintKeys; }
	/** @return array<int,string> */ public function actionIds(): array { return $this->actionIds; }
	public function title(string $locale): string { return $this->title->forLocale($locale); }
	public function detail(string $locale): string { return $this->detail->forLocale($locale); }

	public function withTypeBase(string $base): self {
		if($this->typeUri!=='about:blank') return $this;
		$base=rtrim(trim($base), '/');
		if($base==='' || self::safeAbsoluteUrl($base)===null) return $this;
		$clone=clone $this;
		$clone->typeUri=$base.'/'.rawurlencode($this->id);
		return $clone;
	}

	public function withHelpUrl(?string $url): self {
		$url=self::safeHelpUrl($url);
		if($url===null) return $this;
		$clone=clone $this;
		$clone->helpUrl=$url;
		return $clone;
	}

	public function jsonSerialize(): array {
		return array_filter([
			'id'=>$this->id,
			'type_uri'=>$this->typeUri,
			'title'=>$this->title->all(),
			'detail'=>$this->detail->all(),
			'http_status'=>$this->httpStatus,
			'severity'=>$this->severity,
			'help_topic'=>$this->helpTopic,
			'help_url'=>$this->helpUrl,
			'retry_policy'=>$this->retryPolicy,
			'retry_after_seconds'=>$this->retryAfterSeconds,
			'data_state'=>$this->dataState,
			'incident_policy'=>$this->incidentPolicy,
			'evidence_keys'=>$this->evidenceKeys,
			'fingerprint_keys'=>$this->fingerprintKeys,
			'actions'=>$this->actionIds,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private static function identifier(string $value): string {
		$value=strtolower(trim($value));
		if(preg_match('/^[a-z][a-z0-9._-]{0,127}$/', $value)!==1){
			throw new InvalidArgumentException('Recovery problem identifiers must be stable path-safe values.');
		}
		return $value;
	}

	private static function choice(mixed $value, array $allowed, string $label): string {
		$value=strtolower(trim((string)$value));
		if(!in_array($value, $allowed, true)){
			throw new InvalidArgumentException('Recovery problem '.$label.' is unsupported.');
		}
		return $value;
	}

	/** @return array<int,string> */
	private static function identifiers(mixed $values): array {
		if(!is_array($values)) return [];
		return array_values(array_unique(array_map(static fn(mixed $value): string => self::identifier((string)$value), $values)));
	}

	/** @return array<int,string> */
	private static function paths(mixed $values): array {
		if(!is_array($values)) return [];
		$paths=[];
		foreach($values as $value){
			$value=strtolower(trim((string)$value));
			if(preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,5}$/', $value)!==1){
				throw new InvalidArgumentException('Recovery evidence paths must use dotted identifiers.');
			}
			$paths[]=$value;
		}
		return array_values(array_unique($paths));
	}

	private static function normalizeTypeUri(mixed $value, string $id): string {
		if($value===null || trim((string)$value)==='') return 'about:blank';
		$value=trim((string)$value);
		if($value==='about:blank') return $value;
		return self::safeAbsoluteUrl($value) ?? throw new InvalidArgumentException('Recovery problem type URI must be an absolute HTTP(S) URL.');
	}

	private static function safeHelpUrl(mixed $value): ?string {
		if(is_string($value)){
			$value=trim($value);
			if(str_starts_with($value, '/') && !str_starts_with($value, '//') && strlen($value)<=2048 && preg_match('/[\x00-\x1F\x7F]/', $value)!==1){
				return $value;
			}
		}
		return self::safeAbsoluteUrl($value);
	}

	private static function safeAbsoluteUrl(mixed $value): ?string {
		if(!is_string($value) && !is_numeric($value)) return null;
		$value=trim((string)$value);
		if($value==='' || strlen($value)>2048 || preg_match('/[\x00-\x1F\x7F]/', $value)===1) return null;
		$parts=parse_url($value);
		return is_array($parts)
			&& in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
			&& trim((string)($parts['host'] ?? ''))!==''
			? $value
			: null;
	}
}
