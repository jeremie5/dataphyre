<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Auditable, expiring, consent-aware impersonation envelope. */
final class PanelImpersonationSession implements \JsonSerializable {
	private function __construct(
		private readonly string $id,
		private readonly string $impersonatorId,
		private readonly string $targetId,
		private readonly string $reason,
		private readonly int $startedAt,
		private readonly int $expiresAt,
		private readonly bool $consented,
		private readonly array $scopes,
		private readonly ?int $endedAt=null
	){}

	public static function start(PanelSecurityContext $impersonator, string|int $targetId, string $reason, array $options=[]): self {
		$target=trim((string)$targetId); $reason=trim($reason);
		if($target==='' || $target===$impersonator->actorId()){ throw new \InvalidArgumentException('Impersonation requires a different target actor.'); }
		if($reason===''){ throw new \InvalidArgumentException('Impersonation requires an audit reason.'); }
		if(!$impersonator->can('panel.impersonate')){ throw new \DomainException('Actor lacks panel.impersonate permission.'); }
		$duration=max(60, min(3600, (int)($options['duration_seconds'] ?? 900)));
		$started=time();
		return new self('imp_'.bin2hex(random_bytes(10)), $impersonator->actorId(), $target, $reason, $started, $started+$duration, ($options['consented'] ?? false)===true, self::scopes($options['scopes'] ?? ['read']), null);
	}

	public function active(?int $now=null): bool { return $this->endedAt===null && ($now ?? time())<$this->expiresAt; }
	public function can(string $scope): bool { return $this->active() && (in_array('*', $this->scopes, true) || in_array(strtolower(trim($scope)), $this->scopes, true)); }
	public function consented(): bool { return $this->consented; }
	public function end(?int $timestamp=null): self { return new self($this->id, $this->impersonatorId, $this->targetId, $this->reason, $this->startedAt, $this->expiresAt, $this->consented, $this->scopes, max($this->startedAt, $timestamp ?? time())); }
	public function targetContext(array $options=[]): PanelSecurityContext { return PanelSecurityContext::make($this->targetId, array_replace($options, ['impersonator_id'=>$this->impersonatorId, 'attributes'=>array_replace(is_array($options['attributes'] ?? null) ? $options['attributes'] : [], ['impersonation_id'=>$this->id, 'impersonation_consented'=>$this->consented])])); }
	public function jsonSerialize(): array { return ['id'=>$this->id, 'impersonator_id'=>$this->impersonatorId, 'target_id'=>$this->targetId, 'reason'=>$this->reason, 'started_at'=>$this->startedAt, 'expires_at'=>$this->expiresAt, 'consented'=>$this->consented, 'scopes'=>$this->scopes, 'ended_at'=>$this->endedAt, 'active'=>$this->active()]; }
	private static function scopes(mixed $scopes): array { return array_values(array_unique(array_filter(array_map(static fn(mixed $scope): string => strtolower(trim((string)$scope)), is_array($scopes) ? $scopes : [$scopes])))); }
}
