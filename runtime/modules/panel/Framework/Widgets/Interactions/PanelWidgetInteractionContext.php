<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Trusted host binding for an interactive widget request.
 *
 * There is intentionally no fromArray() factory. Tenant, principal, session,
 * panel, and surface claims are resolved by the owning Panel instance before an
 * adapter sees a request; browser payloads cannot select or override them.
 */
final class PanelWidgetInteractionContext {
	/** @param array<string,mixed> $attributes */
	private function __construct(
		private readonly string $panel,
		private readonly string $surface,
		private readonly string $principal,
		private readonly ?string $tenant,
		private readonly ?string $session,
		private readonly PanelRequest $request,
		private readonly string $bindingTag,
		private readonly array $acceptedBindingTags,
		private readonly array $attributes=[]
	){
		if(PanelWidgetInteractionValue::safeIdentifier($panel, 'Widget panel scope', 96)!==$panel){ throw new \InvalidArgumentException('Widget panel scope must already be normalized.'); }
		if(PanelWidgetInteractionValue::safeIdentifier($surface, 'Widget surface scope', 128)!==$surface){ throw new \InvalidArgumentException('Widget surface scope must already be normalized.'); }
		if(PanelWidgetInteractionValue::boundedString($principal, 'Widget principal scope', 192)!==$principal){ throw new \InvalidArgumentException('Widget principal scope must already be normalized.'); }
		if($tenant!==null && PanelWidgetInteractionValue::boundedString($tenant, 'Widget tenant scope', 192)!==$tenant){ throw new \InvalidArgumentException('Widget tenant scope must already be normalized.'); }
		if($session!==null && PanelWidgetInteractionValue::boundedString($session, 'Widget session scope', 192)!==$session){ throw new \InvalidArgumentException('Widget session scope must already be normalized.'); }
		PanelWidgetInteractionValue::boundedString($bindingTag, 'Widget binding tag', 160);
		if($acceptedBindingTags===[] || count($acceptedBindingTags)>8 || !in_array($bindingTag, $acceptedBindingTags, true)){ throw new \InvalidArgumentException('Widget binding tag rotation set is invalid.'); }
		foreach($acceptedBindingTags as $tag){ if(!is_string($tag)){ throw new \InvalidArgumentException('Widget binding tags must be strings.'); } PanelWidgetInteractionValue::boundedString($tag, 'Widget binding tag', 160); }
		PanelWidgetInteractionValue::assertMap($attributes, 'widget host attributes', 8192);
	}

	/** @internal Created only by an instance-owned PanelWidgetRuntimeRegistry. */
	public static function trusted(string $panel, string $surface, string $principal, ?string $tenant, ?string $session, PanelRequest $request, string $bindingTag, array $acceptedBindingTags, array $attributes=[]): self {
		return new self($panel, $surface, $principal, $tenant, $session, $request, $bindingTag, array_values(array_unique($acceptedBindingTags)), $attributes);
	}

	public function panel(): string { return $this->panel; }
	public function surface(): string { return $this->surface; }
	public function principal(): string { return $this->principal; }
	public function tenant(): ?string { return $this->tenant; }
	public function session(): ?string { return $this->session; }
	public function request(): PanelRequest { return $this->request; }
	public function bindingTag(): string { return $this->bindingTag; }
	public function acceptsBindingTag(?string $tag): bool { return is_string($tag) && in_array($tag, $this->acceptedBindingTags, true); }
	/** @return array<string,mixed> */ public function attributes(): array { return $this->attributes; }

	/** Canonical private claims used only for keyed binding and adapter scope keys. */
	public function claims(): array {
		return [
			'panel'=>$this->panel,
			'surface'=>$this->surface,
			'principal'=>$this->principal,
			'tenant'=>$this->tenant,
			'session'=>$this->session,
		];
	}

	public function scopeKey(string $key): string {
		if(strlen($key)<32){ throw new \InvalidArgumentException('Widget scope keys must contain at least 32 bytes.'); }
		return hash_hmac('sha256', PanelWidgetInteractionValue::canonical($this->claims()), $key);
	}
}
