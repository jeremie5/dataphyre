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
 * Bounded bridge from Dataphyre Fulltext (or a compatible callback) to Panel
 * global search.
 */
final class PanelDataphyreFulltextSearchAdapter implements \JsonSerializable {
	private readonly \Closure $search;
	private readonly ?\Closure $mapper;
	private readonly PanelSearchProvider $provider;

	/**
	 * @param callable(string,string,int,PanelRequest,array<string,mixed>):mixed $search
	 * @param null|callable(string,float,string,PanelRequest,mixed):(PanelSearchResult|array<string,mixed>|null) $mapper
	 * @param array<string,mixed> $options
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $index,
		callable $search,
		?callable $mapper=null,
		private readonly array $options=[]
	) {
		if(Resource::normalizeName($name)!==trim($name) || trim($name)===''){
			throw new \InvalidArgumentException('Panel fulltext adapter names must be canonical names.');
		}
		if(trim($index)==='' || strlen($index)>256 || str_contains($index,"\0")){
			throw new \InvalidArgumentException('Panel fulltext adapters require a safe index name.');
		}
		$this->search=\Closure::fromCallable($search);
		$this->mapper=$mapper!==null?\Closure::fromCallable($mapper):null;
		$provider=PanelSearchProvider::make($name)
			->label((string)($options['label']??'Dataphyre Fulltext'))
			->description((string)($options['description']??'Search results from a Dataphyre fulltext index.'))
			->icon((string)($options['icon']??'search'))
			->sort((int)($options['sort']??100))
			->limit((int)($options['limit']??10))
			->meta([
				'adapter'=>'dataphyre_fulltext',
				'index'=>$index,
				'bounded'=>true,
				'cursor_aware'=>false,
			])
			->searchUsing(fn(string $query,PanelRequest $request,PanelSearchProvider $provider,int $limit):PanelSearchPage=>$this->page($query,$request,$limit));
		if(($options['tenant_scoped']??false)===true){
			$provider=$provider->tenantScoped(true,($options['tenant_required']??true)!==false);
		}
		if(is_callable($options['authorize']??null)){$provider=$provider->authorizeUsing($options['authorize']);}
		if(is_callable($options['visible']??null)){$provider=$provider->visibleUsing($options['visible']);}
		if(is_callable($options['score']??null)){$provider=$provider->scoreUsing($options['score']);}
		if(is_callable($options['dedupe']??null)){$provider=$provider->deduplicateUsing($options['dedupe']);}
		if(is_array($options['meta']??null)){$provider=$provider->meta($options['meta']);}
		$this->provider=$provider;
	}

	/**
	 * Creates a bridge over the first-party SearchManager.
	 *
	 * @param null|callable(string,float,string,PanelRequest,mixed):(PanelSearchResult|array<string,mixed>|null) $mapper
	 * @param array<string,mixed> $options
	 */
	public static function fromManager(
		string $name,
		string $index,
		\Dataphyre\FulltextEngine\SearchManager $manager,
		?callable $mapper=null,
		array $options=[]
	): self {
		$criteria=$options['criteria']??null;
		$field=trim((string)($options['criteria_field']??'query')) ?: 'query';
		$search=static function(string $indexName,string $query,int $limit,PanelRequest $request,array $adapterOptions) use ($manager,$criteria,$field):mixed {
			$resolved=is_callable($criteria)?$criteria($query,$request,$indexName):[$field=>$query];
			if(!is_array($resolved) || $resolved===[]){
				throw new \UnexpectedValueException('Fulltext criteria resolvers must return a non-empty map.');
			}
			return $manager->search(
				$indexName,
				$resolved,
				isset($adapterOptions['language'])?(string)$adapterOptions['language']:null,
				$limit,
				isset($adapterOptions['boolean_mode'])?(bool)$adapterOptions['boolean_mode']:null,
				isset($adapterOptions['threshold'])?(float)$adapterOptions['threshold']:null,
				isset($adapterOptions['algorithms'])?(string)$adapterOptions['algorithms']:null
			);
		};
		return new self($name,$index,$search,$mapper,$options);
	}

	public function name(): string {return $this->name;}
	public function index(): string {return $this->index;}
	public function provider(): PanelSearchProvider {return $this->provider;}

	public function page(string $query, PanelRequest $request, int $limit=10): PanelSearchPage {
		$limit=max(1,min(50,$limit));
		$multiplier=max(1,min(10,(int)($this->options['fetch_multiplier']??4)));
		$budget=min(200,max(20,$limit*$multiplier));
		$requested=min(200,max($limit+1,$budget));
		$raw=($this->search)($this->index,$query,$requested,$request,$this->options);
		if($raw instanceof PanelSearchPage){return $raw;}
		[$rows,$knownTotal]=$this->rows($raw);
		$results=[];$diagnostics=[];$inspected=0;$budgetExhausted=false;$hasMore=false;
		try{
			foreach($rows as $row){
				if($inspected>=$budget){$budgetExhausted=true;break;}
				$inspected++;
				$hit=$this->hit($row);
				if($hit===null){continue;}
				try{$mapped=$this->map($hit['id'],$hit['score'],$query,$request,$row);}
				catch(\Throwable $exception){
					$diagnostics[]=[
						'code'=>'fulltext_mapper_error',
						'message'=>'A fulltext hit could not be mapped.',
						'severity'=>'error',
						'exception'=>$exception::class,
					];
					continue;
				}
				if($mapped===null){continue;}
				$results[]=$mapped;
				if(count($results)>$limit){$hasMore=true;break;}
			}
		}
		catch(\Throwable $exception){
			$diagnostics[]=[
				'code'=>'fulltext_iteration_error',
				'message'=>'The fulltext result stream failed during iteration.',
				'severity'=>'error',
				'exception'=>$exception::class,
			];
		}
		if(count($results)>$limit){$results=array_slice($results,0,$limit);}
		$knownMore=$knownTotal!==null && $knownTotal>$inspected;
		return PanelSearchPage::make(
			$results,
			null,
			!$hasMore&&!$knownMore&&!$budgetExhausted&&$diagnostics===[],
			$budgetExhausted||$diagnostics!==[],
			$diagnostics,
			[
				'provider'=>$this->name,
				'adapter'=>'dataphyre_fulltext',
				'inspected'=>$inspected,
				'known_total'=>$knownTotal,
				'input_budget'=>$budget,
			]
		);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		$keys=array_values(array_map('strval',array_keys($this->options)));sort($keys,SORT_STRING);
		$provider=$this->provider->toArray();
		unset($provider['meta']);
		return [
			'type'=>'panel_dataphyre_fulltext_search_adapter',
			'schema_version'=>1,
			'name'=>$this->name,
			'index'=>$this->index,
			'provider'=>$provider,
			'option_keys'=>$keys,
			'search_callback_supplied'=>true,
			'mapper_supplied'=>$this->mapper!==null,
			'callbacks_serialized'=>false,
			'configuration_serialized'=>false,
		];
	}

	/** @return array{0:iterable<mixed>,1:?int} */
	private function rows(mixed $raw): array {
		if(is_object($raw) && method_exists($raw,'hits')){
			$rows=$raw->hits();
			if(!is_iterable($rows)){throw new \UnexpectedValueException('Fulltext hits() must return an iterable.');}
			$total=method_exists($raw,'total')?$raw->total():null;
			return [$rows,is_numeric($total)?max(0,(int)$total):null];
		}
		if(is_array($raw) && !array_is_list($raw) && (array_key_exists('hits',$raw)||array_key_exists('results',$raw)||array_key_exists('items',$raw))){
			$rows=$raw['hits']??$raw['results']??$raw['items'];
			if(!is_iterable($rows)){throw new \UnexpectedValueException('Fulltext result rows must be iterable.');}
			$total=$raw['total']??$raw['count']??null;
			return [$rows,is_numeric($total)?max(0,(int)$total):null];
		}
		if(!is_iterable($raw)){throw new \UnexpectedValueException('Fulltext callbacks must return an iterable, hits object, or PanelSearchPage.');}
		return [$raw,is_array($raw)?count($raw):null];
	}

	/** @return null|array{id:string,score:float} */
	private function hit(mixed $row): ?array {
		$id=null;$score=0.0;
		if(is_object($row) && method_exists($row,'id')){
			$id=$row->id();$score=method_exists($row,'score')?$row->score():0.0;
		}
		elseif(is_array($row)){
			$id=$row['id']??$row['key']??$row['record_key']??null;
			$score=$row['score']??0.0;
			if($id===null && count($row)===1){
				$id=array_key_first($row);$score=$row[$id];
			}
		}
		elseif(is_string($row)||is_int($row)){$id=$row;}
		if(!is_string($id)&&!is_int($id)){return null;}
		$id=trim((string)$id);
		if($id===''){return null;}
		$score=is_numeric($score)?(float)$score:0.0;
		if(!is_finite($score)){$score=0.0;}
		return ['id'=>$id,'score'=>$score];
	}

	private function map(string $id,float $score,string $query,PanelRequest $request,mixed $raw):PanelSearchResult|array|null {
		$mapped=$this->mapper!==null?($this->mapper)($id,$score,$query,$request,$raw):[
			'title'=>$id,
			'record_key'=>$id,
			'score'=>$score,
		];
		if($mapped instanceof PanelSearchResult){return $mapped;}
		if(!is_array($mapped)){return null;}
		if(!array_key_exists('record_key',$mapped)&&!array_key_exists('key',$mapped)&&!array_key_exists('id',$mapped)){$mapped['record_key']=$id;}
		if(!array_key_exists('score',$mapped)){$mapped['score']=$score;}
		return $mapped;
	}
}
