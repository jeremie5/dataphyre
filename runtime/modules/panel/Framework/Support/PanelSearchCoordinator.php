<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Deterministic coordinator for resource and custom global-search providers.
 *
 * The coordinator bounds every provider and the aggregate candidate pool,
 * ranks before deduplication so the strongest duplicate wins, and retains
 * partial diagnostics without letting one adapter failure take down search.
 */
final class PanelSearchCoordinator {

	private const MAX_RESULTS=50;
	private const MAX_CANDIDATES=200;
	private const MAX_SOURCES=100;

	public function __construct(private readonly PanelManager $manager) {}

	public function search(string $query, PanelRequest $request, int $limit=12, string|array|null $cursor=null): PanelSearchPage {
		$query=PanelSearchSanitizer::value(trim($query));
		$query=is_string($query) ? $query : '';
		$limit=max(1, min(self::MAX_RESULTS, $limit));
		if($query===''){
			return PanelSearchPage::make(meta:$this->meta($query, $request, $limit, 0, 0));
		}

		$providers=array_values($this->manager->searchProviders());
		usort($providers, static fn(PanelSearchProvider $left, PanelSearchProvider $right): int=>
			[$left->sortOrder(), $left->name()] <=> [$right->sortOrder(), $right->name()]
		);
		$resources=array_values(array_filter(
			$this->manager->resources(),
			static fn(mixed $resource): bool=>$resource instanceof Resource && $resource->isGlobalSearchable()
		));
		$allSources=[
			...array_map(static fn(PanelSearchProvider $provider): array=>['kind'=>'provider','value'=>$provider], $providers),
			...array_map(static fn(Resource $resource): array=>['kind'=>'resource','value'=>$resource], $resources),
		];
		$sources=array_slice($allSources, 0, self::MAX_SOURCES);
		$sourceCount=count($sources);
		$candidateLimit=min(self::MAX_CANDIDATES, max($limit, $limit*4, $sourceCount));
		$candidates=[];
		$diagnostics=[];
		$nextCursors=[];
		$partial=false;
		$attempted=0;
		$completed=0;
		$sequence=0;
		$sourcesIncomplete=false;
		if(count($allSources)>self::MAX_SOURCES){
			$partial=true;
			$diagnostics[]=[
				'code'=>'source_budget_exhausted',
				'message'=>'Search source budget was exhausted.',
				'severity'=>'warning',
				'source_limit'=>self::MAX_SOURCES,
			];
		}
		$tenant=$request->tenantKey();
		$span=PanelTrace::begin('global_search.coordinate', [
			'query_length'=>strlen($query),
			'limit'=>$limit,
			'sources'=>$sourceCount,
			'has_tenant'=>$tenant!==null && $tenant!=='',
			'tenant_hash'=>$tenant!==null && $tenant!=='' ? hash('sha256',$tenant) : null,
		]);

		foreach($sources as $sourceIndex=>$source){
			$remaining=$candidateLimit-count($candidates);
			$fairShare=max(1, (int)ceil($remaining / max(1, $sourceCount-$sourceIndex)));
			$kind=(string)$source['kind'];
			$value=$source['value'];
			if($kind==='provider' && $value instanceof PanelSearchProvider){
				if(!$this->manager->allowsSearchProvider($value, $request)){ continue; }
				$attempted++;
				$budget=min($value->resultLimit(), $fairShare, $remaining);
				$providerCursor=self::cursorFor($cursor, $value->name());
				$page=$value->searchPage($query, $request, $this->manager, $budget, $providerCursor, $limit);
				$completed++;
				$partial=$partial || $page->isPartial();
				$sourcesIncomplete=$sourcesIncomplete || !$page->isComplete();
				foreach($page->diagnostics() as $diagnostic){
					$diagnostics[]=array_replace($diagnostic, ['source_type'=>'provider','provider'=>$value->name()]);
				}
				if($page->nextCursor()!==null){
					$nextCursors[$value->name()]=$page->nextCursor();
				}
				foreach($page->results() as $localIndex=>$result){
					$candidates[]=$this->candidate($result, $value->sortOrder(), $value->name(), $sourceIndex, (int)$localIndex, $sequence++);
				}
				PanelTrace::record('global_search.provider_completed', [
					'provider'=>$value->name(),
					'result_count'=>count($page),
					'partial'=>$page->isPartial(),
					'complete'=>$page->isComplete(),
				]);
				continue;
			}
			if(!$value instanceof Resource || !$this->manager->allowsSearchResource($value, $request)){ continue; }
			$attempted++;
			$budget=min($fairShare, $remaining, self::MAX_RESULTS);
			try{
				$resourceData=$value->toArray();
				$label=(string)($resourceData['plural_label'] ?? $resourceData['label'] ?? $value->name());
				$rows=$value->globalSearchResults($query, $request, $budget);
				$localIndex=0;
				foreach($rows as $row){
					if($localIndex>=$budget){ break; }
					if(!is_array($row)){ continue; }
					$row=array_replace($row, ['provider'=>$value->name(), 'source'=>$value->name(), 'resource'=>$value->name()]);
					$result=PanelSearchResult::fromArray($row, $value->name(), $label);
					if(!$result instanceof PanelSearchResult){ continue; }
					$result=$result->forProvider($value->name(), $label);
					$candidates[]=$this->candidate($result, 1000+$sourceIndex, $value->name(), $sourceIndex, $localIndex++, $sequence++);
				}
				$completed++;
				PanelTrace::record('global_search.resource_completed', ['resource'=>$value->name(), 'result_count'=>$localIndex]);
			}
			catch(\Throwable $exception){
				$partial=true;
				$diagnostics[]=[
					'code'=>'resource_error',
					'source_type'=>'resource',
					'provider'=>$value->name(),
					'message'=>'Resource search failed.',
					'severity'=>'error',
					'exception'=>$exception::class,
				];
				PanelTrace::record('global_search.resource_error', ['resource'=>$value->name(), 'exception'=>$exception::class]);
			}
		}

		usort($candidates, static fn(array $left, array $right): int=>
			[-$left['result']->score(), $left['sort'], $left['source'], $left['source_index'], $left['local_index'], $left['sequence']]
			<=> [-$right['result']->score(), $right['sort'], $right['source'], $right['source_index'], $right['local_index'], $right['sequence']]
		);
		$deduplicated=[];
		$seen=[];
		foreach($candidates as $candidate){
			/** @var PanelSearchResult $result */
			$result=$candidate['result'];
			$key=$result->dedupeKey();
			if(isset($seen[$key])){ continue; }
			$seen[$key]=true;
			$deduplicated[]=$result;
		}
		$more=count($deduplicated)>$limit || $nextCursors!==[] || $sourcesIncomplete || count($allSources)>self::MAX_SOURCES;
		$results=array_slice($deduplicated, 0, $limit);
		PanelTrace::end($span, [
			'attempted'=>$attempted,
			'completed'=>$completed,
			'candidates'=>count($candidates),
			'deduplicated'=>count($deduplicated),
			'results'=>count($results),
			'partial'=>$partial,
		]);
		PanelTrace::record('global_search.completed', ['query_length'=>strlen($query), 'result_count'=>count($results), 'partial'=>$partial]);
		return PanelSearchPage::make(
			$results,
			$nextCursors!==[] ? $nextCursors : null,
			!$more,
			$partial,
			$diagnostics,
			$this->meta($query, $request, $limit, $attempted, $completed)+[
				'candidate_count'=>count($candidates),
				'deduplicated_count'=>count($deduplicated),
				'candidate_budget'=>$candidateLimit,
			]
		);
	}

	/** @return array<string,mixed> */
	private function candidate(PanelSearchResult $result, int $sort, string $source, int $sourceIndex, int $localIndex, int $sequence): array {
		return compact('result', 'sort', 'source', 'sequence')+[
			'source_index'=>$sourceIndex,
			'local_index'=>$localIndex,
		];
	}

	private static function cursorFor(string|array|null $cursor, string $provider): string|array|null {
		if(!is_array($cursor)){ return $cursor; }
		$value=$cursor[$provider] ?? null;
		return is_string($value) || is_array($value) ? $value : null;
	}

	/** @return array<string,mixed> */
	private function meta(string $query, PanelRequest $request, int $limit, int $attempted, int $completed): array {
		return [
			'query'=>$query,
			'limit'=>$limit,
			'tenant'=>$request->tenantKey(),
			'has_user'=>$request->user()!==null,
			'attempted_sources'=>$attempted,
			'completed_sources'=>$completed,
		];
	}
}
