<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Clone-on-write definition for an engine-neutral global-search source.
 *
 * Provider callbacks may return a bounded iterable of result-like arrays or a
 * PanelSearchPage from a cursor-aware index adapter. Panel never waits on
 * promises/futures: truly asynchronous transports should resolve outside the
 * request and expose a synchronous page adapter here.
 *
 * @template TResult of PanelSearchResult|array<string, mixed> = PanelSearchResult|array<string, mixed>
 * @template TCursor of string|array<string, mixed>|null = string|array<string, mixed>|null
 */
final class PanelSearchProvider {

	private string $name;
	private string $label;
	private ?string $description=null;
	private ?string $icon=null;
	private int $sort=100;
	private int $limit=5;
	private bool $hidden=false;
	private bool $tenantScoped=false;
	private bool $tenantRequired=false;
	/** @var array<string,mixed> */
	private array $meta=[];
	/** @var (\Closure(string,PanelRequest,self<TResult,TCursor>,int,?PanelManager,PanelSearchContext):(PanelSearchPage|iterable<TResult>|array<string,mixed>))|null */
	private ?\Closure $searchHandler=null;
	/** @var (\Closure(?PanelRequest,self<TResult,TCursor>,?PanelManager):bool)|null */
	private ?\Closure $visibilityResolver=null;
	/** @var (\Closure(mixed,PanelRequest,self<TResult,TCursor>,?PanelManager,string|int|null):bool)|null */
	private ?\Closure $authorizationResolver=null;
	/** @var (\Closure(PanelSearchResult,string,PanelRequest,self<TResult,TCursor>,?PanelManager,PanelSearchContext):(int|float))|null */
	private ?\Closure $scoreResolver=null;
	/** @var (\Closure(PanelSearchResult,string,PanelRequest,self<TResult,TCursor>,?PanelManager,PanelSearchContext):(scalar|null))|null */
	private ?\Closure $dedupeResolver=null;

	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	public static function make(string $name): self { return new self($name); }

	/** @param array<string,mixed> $definition */
	public static function fromArray(array $definition): self {
		$provider=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'description', 'icon'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$provider=$provider->{$key}($definition[$key]);
			}
		}
		if(isset($definition['sort'])){ $provider=$provider->sort((int)$definition['sort']); }
		if(isset($definition['limit'])){ $provider=$provider->limit((int)$definition['limit']); }
		if(isset($definition['tenant_scoped'])){ $provider=$provider->tenantScoped((bool)$definition['tenant_scoped'], (bool)($definition['tenant_required'] ?? true)); }
		if(isset($definition['handler']) && is_callable($definition['handler'])){ $provider=$provider->searchUsing($definition['handler']); }
		if(isset($definition['search']) && is_callable($definition['search'])){ $provider=$provider->searchUsing($definition['search']); }
		if(isset($definition['visible']) && is_callable($definition['visible'])){ $provider=$provider->visibleUsing($definition['visible']); }
		if(isset($definition['authorize']) && is_callable($definition['authorize'])){ $provider=$provider->authorizeUsing($definition['authorize']); }
		if(isset($definition['score']) && is_callable($definition['score'])){ $provider=$provider->scoreUsing($definition['score']); }
		if(isset($definition['rank']) && is_callable($definition['rank'])){ $provider=$provider->scoreUsing($definition['rank']); }
		if(isset($definition['dedupe']) && is_callable($definition['dedupe'])){ $provider=$provider->deduplicateUsing($definition['dedupe']); }
		if(!empty($definition['hidden'])){ $provider=$provider->hide(); }
		if(isset($definition['meta']) && is_array($definition['meta'])){ $provider=$provider->meta($definition['meta']); }
		return $provider;
	}

	public function name(): string { return $this->name; }
	public function sortOrder(): int { return $this->sort; }
	public function resultLimit(): int { return $this->limit; }

	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=self::text($label);
		return $clone;
	}

	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=self::text($description) ?: null;
		return $clone;
	}

	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=self::text($icon) ?: null;
		return $clone;
	}

	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	public function limit(int $limit): self {
		$clone=clone $this;
		$clone->limit=max(1, min(50, $limit));
		return $clone;
	}

	/**
	 * Callback signature is `(query, request, provider, limit, manager, context)`.
	 * Existing five-argument callbacks remain compatible.
	 *
	 * @template TSearchResult of PanelSearchResult|array<string,mixed>
	 * @param callable(string,PanelRequest,self<TSearchResult,TCursor>,int,?PanelManager,PanelSearchContext):(PanelSearchPage|iterable<TSearchResult>|array<string,mixed>) $handler
	 * @return self<TSearchResult,TCursor>
	 */
	public function searchUsing(callable $handler): self {
		$clone=clone $this;
		$clone->searchHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Cursor-aware readability alias for searchUsing().
	 *
	 * @template TSearchResult of PanelSearchResult|array<string,mixed>
	 * @param callable(string,PanelRequest,self<TSearchResult,TCursor>,int,?PanelManager,PanelSearchContext):(PanelSearchPage|iterable<TSearchResult>|array<string,mixed>) $handler
	 * @return self<TSearchResult,TCursor>
	 */
	public function pageUsing(callable $handler): self { return $this->searchUsing($handler); }

	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Declares and enforces tenant-aware provider execution.
	 *
	 * Required tenant providers are denied before their authorization/search
	 * callbacks run when the request has no tenant key.
	 */
	public function tenantScoped(bool $scoped=true, bool $required=true): self {
		$clone=clone $this;
		$clone->tenantScoped=$scoped;
		$clone->tenantRequired=$scoped && $required;
		return $clone;
	}

	/** @param callable(?PanelRequest,self<TResult,TCursor>,?PanelManager):bool $resolver */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Installs a request/tenant-aware provider authorization rule.
	 *
	 * @param callable(mixed,PanelRequest,self<TResult,TCursor>,?PanelManager,string|int|null):bool $resolver
	 */
	public function authorizeUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->authorizationResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Installs a stable score resolver for normalized results.
	 *
	 * @param callable(PanelSearchResult,string,PanelRequest,self<TResult,TCursor>,?PanelManager,PanelSearchContext):(int|float) $resolver
	 */
	public function scoreUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->scoreResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Alias emphasizing ranking intent.
	 *
	 * @param callable(PanelSearchResult,string,PanelRequest,self<TResult,TCursor>,?PanelManager,PanelSearchContext):(int|float) $resolver
	 */
	public function rankUsing(callable $resolver): self { return $this->scoreUsing($resolver); }

	/**
	 * Installs a cross-provider deduplication identity resolver.
	 *
	 * @param callable(PanelSearchResult,string,PanelRequest,self<TResult,TCursor>,?PanelManager,PanelSearchContext):(scalar|null) $resolver
	 */
	public function deduplicateUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->dedupeResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/** @param array<string,mixed> $meta */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=PanelSearchSanitizer::map(array_replace($clone->meta, $meta));
		return $clone;
	}

	public function isVisible(?PanelRequest $request=null, ?PanelManager $manager=null): bool {
		if($this->hidden){ return false; }
		if($this->visibilityResolver===null){ return true; }
		try{
			return (bool)($this->visibilityResolver)($request, $this, $manager);
		}
		catch(\Throwable $exception){
			PanelTrace::record('search_provider.visibility_error', ['provider'=>$this->name, 'exception'=>$exception::class]);
			return false;
		}
	}

	/** Authorization exceptions fail closed and are traced. */
	public function isAuthorized(PanelRequest $request, ?PanelManager $manager=null): bool {
		if($this->tenantRequired && $request->tenantKey()===null){
			PanelTrace::record('search_provider.tenant_missing', ['provider'=>$this->name]);
			return false;
		}
		if($this->authorizationResolver===null){ return true; }
		try{
			return (bool)($this->authorizationResolver)($request->user(), $request, $this, $manager, $request->tenantKey());
		}
		catch(\Throwable $exception){
			PanelTrace::record('search_provider.authorization_error', ['provider'=>$this->name, 'exception'=>$exception::class]);
			return false;
		}
	}

	/**
	 * Guarded direct execution for callers outside PanelManager orchestration.
	 * The legacy search()/searchPage() methods remain raw adapter primitives for
	 * backward compatibility; application-facing calls should use this method or
	 * PanelManager::globalSearchPage().
	 *
	 * @param TCursor $cursor
	 */
	public function searchAuthorizedPage(string $query, PanelRequest $request, PanelManager $manager, ?int $limit=null, string|array|null $cursor=null, ?int $globalLimit=null): PanelSearchPage {
		if(!$manager->allowsSearchProvider($this, $request)){
			return PanelSearchPage::make(meta:['provider'=>$this->name, 'authorized'=>false]);
		}
		return $this->searchPage($query, $request, $manager, $limit, $cursor, $globalLimit);
	}

	/**
	 * Executes and normalizes one bounded provider page.
	 *
	 * Scalar/future-like responses are rejected instead of being polled or
	 * blocked. Failures become partial diagnostics and trace events.
	 *
	 * @param TCursor $cursor
	 */
	public function searchPage(string $query, PanelRequest $request, ?PanelManager $manager=null, ?int $limit=null, string|array|null $cursor=null, ?int $globalLimit=null): PanelSearchPage {
		$query=self::text($query);
		$limit=max(1, min(50, $limit ?? $this->limit));
		$context=PanelSearchContext::make($query, $request, $limit, $globalLimit ?? $limit, $cursor, ['provider'=>$this->name]);
		if($query==='' || $this->searchHandler===null){
			return PanelSearchPage::make(meta:['provider'=>$this->name]);
		}
		try{
			$response=($this->searchHandler)($query, $request, $this, $limit, $manager, $context);
		}
		catch(\Throwable $exception){
			PanelTrace::record('search_provider.error', ['provider'=>$this->name, 'exception'=>$exception::class]);
			return PanelSearchPage::make(partial:true, diagnostics:[self::diagnostic('provider_error', $this->name, 'Provider search failed.', $exception)], meta:['provider'=>$this->name]);
		}

		$page=$response instanceof PanelSearchPage ? $response : null;
		if($page===null && is_array($response) && !array_is_list($response) && (array_key_exists('results', $response) || array_key_exists('items', $response))){
			$page=PanelSearchPage::fromArray($response);
		}
		$rows=$page?->results() ?? (is_iterable($response) ? $response : null);
		if($rows===null){
			PanelTrace::record('search_provider.invalid_response', ['provider'=>$this->name, 'response_type'=>get_debug_type($response)]);
			return PanelSearchPage::make(partial:true, diagnostics:[self::diagnostic('invalid_response', $this->name, 'Provider returned an unsupported response type.')], meta:['provider'=>$this->name]);
		}

		$normalized=[];
		$diagnostics=$page?->diagnostics() ?? [];
		$partial=$page?->isPartial() ?? false;
		$complete=$page?->isComplete() ?? true;
		$inputBudget=min(200, max(20, $limit*4));
		$inspected=0;
		$inputBudgetExhausted=false;
		$knownCount=$page!==null
			? count($page)
			: (is_array($response) && array_is_list($response) ? count($response) : null);
		try{
			foreach($rows as $row){
				if($inspected++>=$inputBudget){
					$inputBudgetExhausted=true;
					break;
				}
				if(count($normalized)>=$limit){ break; }
				if(is_array($row)){
					$row=array_replace($row, ['provider'=>$this->name, 'source'=>$this->name, 'resource'=>$this->name]);
					$result=PanelSearchResult::fromArray($row, $this->name, $this->label, $this->icon);
				}
				else {
					$result=$row instanceof PanelSearchResult ? $row->forProvider($this->name, $this->label, $this->icon) : null;
				}
				if(!$result instanceof PanelSearchResult){ continue; }
				if($this->scoreResolver!==null){
					try{
						$score=($this->scoreResolver)($result, $query, $request, $this, $manager, $context);
						if(!is_numeric($score) || !is_finite((float)$score)){ throw new \UnexpectedValueException('Search score must be finite and numeric.'); }
						$result=$result->withScore((float)$score);
					}
					catch(\Throwable $exception){
						$partial=true;
						$diagnostics[]=self::diagnostic('score_error', $this->name, 'Provider score resolver failed.', $exception);
						PanelTrace::record('search_provider.score_error', ['provider'=>$this->name, 'exception'=>$exception::class]);
					}
				}
				if($this->dedupeResolver!==null){
					try{
						$key=($this->dedupeResolver)($result, $query, $request, $this, $manager, $context);
						if(!is_scalar($key) && $key!==null){ throw new \UnexpectedValueException('Search dedupe key must be scalar or null.'); }
						if(trim((string)$key)!==''){ $result=$result->withDedupeKey((string)$key); }
					}
					catch(\Throwable $exception){
						$partial=true;
						$diagnostics[]=self::diagnostic('dedupe_error', $this->name, 'Provider dedupe resolver failed.', $exception);
						PanelTrace::record('search_provider.dedupe_error', ['provider'=>$this->name, 'exception'=>$exception::class]);
					}
				}
				$normalized[]=$result;
			}
		}
		catch(\Throwable $exception){
			$complete=false;
			$partial=true;
			$diagnostics[]=self::diagnostic('provider_iteration_error', $this->name, 'Provider result iteration failed.', $exception);
			PanelTrace::record('search_provider.iteration_error', ['provider'=>$this->name, 'exception'=>$exception::class]);
		}
		if(($knownCount!==null && $knownCount>$limit) || ($knownCount===null && count($normalized)>=$limit)){
			$complete=false;
		}
		if($inputBudgetExhausted){
			$complete=false;
			$partial=true;
			$diagnostics[]=self::diagnostic('input_budget_exhausted', $this->name, 'Provider input budget was exhausted.');
			PanelTrace::record('search_provider.input_budget_exhausted', ['provider'=>$this->name, 'input_limit'=>$inputBudget]);
		}
		$diagnostics=array_map(
			fn(array $diagnostic): array=>array_replace($diagnostic, ['provider'=>$this->name]),
			array_values(array_filter($diagnostics, 'is_array'))
		);
		return PanelSearchPage::make(
			$normalized,
			$page?->nextCursor(),
			$complete,
			$partial,
			$diagnostics,
			array_replace($page?->meta() ?? [], ['provider'=>$this->name, 'tenant'=>$request->tenantKey()])
		);
	}

	/**
	 * Backward-compatible array-returning provider API.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function search(string $query, PanelRequest $request, ?PanelManager $manager=null, ?int $limit=null): array {
		return array_map(static fn(PanelSearchResult $result): array=>$result->toArray(), $this->searchPage($query, $request, $manager, $limit)->results());
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'icon'=>$this->icon,
			'sort'=>$this->sort,
			'limit'=>$this->limit,
			'hidden'=>$this->hidden,
			'tenant_scoped'=>$this->tenantScoped,
			'tenant_required'=>$this->tenantRequired,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'authorization_lazy'=>$this->authorizationResolver!==null,
			'search_lazy'=>$this->searchHandler!==null,
			'score_lazy'=>$this->scoreResolver!==null,
			'dedupe_lazy'=>$this->dedupeResolver!==null,
			'page_results'=>true,
			'iterable_results'=>true,
			'cursor_aware'=>true,
			'meta'=>$this->meta,
		];
	}

	/** @return array<string,mixed> */
	private static function diagnostic(string $code, string $provider, string $message, ?\Throwable $exception=null): array {
		$diagnostic=['code'=>$code, 'provider'=>$provider, 'message'=>$message, 'severity'=>'error'];
		if($exception!==null){ $diagnostic['exception']=$exception::class; }
		return $diagnostic;
	}

	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}

	private static function text(string $value): string {
		$value=PanelSearchSanitizer::value(trim($value));
		return is_string($value) ? $value : '';
	}
}
