<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Instance-owned typed surface definition; query materialization remains server-side. */
final class PanelDataSurfaceDefinition implements \JsonSerializable {
	private ?\Closure $queryResolver;

	/** @param array<string,mixed> $options */
	private function __construct(
		private readonly string $id,
		private readonly string $resource,
		private readonly string $source,
		private readonly PanelDataSurfaceType $surface,
		private readonly PanelDataSurfaceProjection $projection,
		private readonly PanelDataSurfaceRange $defaultRange,
		?callable $queryResolver,
		private readonly ?PanelDataCanvasSpec $canvas,
		private readonly array $options
	){ $this->queryResolver=$queryResolver===null ? null : \Closure::fromCallable($queryResolver); }

	/** @param array<string,mixed> $options */
	public static function make(
		string $id,
		string $resource,
		string $source,
		PanelDataSurfaceType|string $surface,
		PanelDataSurfaceProjection $projection,
		?PanelDataSurfaceRange $defaultRange=null,
		?callable $queryResolver=null,
		array $options=[]
	): self {
		$surface=PanelDataSurfaceType::normalize($surface);$canvasValue=$options['canvas']??null;unset($options['canvas']);
		if($canvasValue instanceof PanelDataCanvasSpec){if($canvasValue->surface()!==$surface){throw new \InvalidArgumentException('Panel DataCanvas spec surface does not match its DataSurface definition.');}$canvas=$canvasValue;}
		elseif(is_array($canvasValue)){$canvas=PanelDataCanvasSpec::make($surface,$projection,$canvasValue);}
		elseif($canvasValue===null&&$surface->advanced()){$canvas=PanelDataCanvasSpec::make($surface,$projection);}
		elseif($canvasValue===null){$canvas=null;}
		else{throw new \InvalidArgumentException('Panel DataSurface canvas must be a PanelDataCanvasSpec or options map.');}
		$normalized=[];
		foreach(['title','description','empty_message','endpoint','aria_label'] as $key){
			if(!array_key_exists($key, $options)){ continue; }
			$maximum=$key==='endpoint' ? 2048 : 256;
			$normalized[$key]=PanelDataSurfaceGuard::boundedString($options[$key], $key, $maximum, $key==='description');
		}
		$estimated=(int)($options['estimated_item_size'] ?? 52);
		if($estimated<20 || $estimated>2000){ throw new \InvalidArgumentException('Panel DataSurface estimated item size must be between 20 and 2000 pixels.'); }
		$normalized['estimated_item_size']=$estimated;
		$normalized['virtualize']=($options['virtualize'] ?? true)===true;
		return new self(
			PanelDataSurfaceGuard::identifier($id, 'definition', 100),
			PanelDataSurfaceGuard::identifier($resource, 'resource', 100),
			PanelDataSurfaceGuard::identifier($source, 'source', 100),
				$surface, $projection,
				$defaultRange ?? PanelDataSurfaceRange::make(), $queryResolver, $canvas, $normalized
		);
	}

	public function id(): string { return $this->id; }
	public function resource(): string { return $this->resource; }
	public function source(): string { return $this->source; }
	public function surface(): PanelDataSurfaceType { return $this->surface; }
	public function projection(): PanelDataSurfaceProjection { return $this->projection; }
	public function defaultRange(): PanelDataSurfaceRange { return $this->defaultRange; }
	public function canvas():?PanelDataCanvasSpec{return$this->canvas;}
	public function option(string $name, mixed $default=null): mixed { return $this->options[$name] ?? $default; }

	/** @param array<string,mixed> $safeState */
	public function resolveQuery(PanelDataSurfaceContext $context, array $safeState=[]): PanelDataQuery {
		if($safeState!==[] && array_is_list($safeState)){ throw new \InvalidArgumentException('Panel DataSurface query state must be object-like.'); }
		PanelDataSurfaceGuard::assertJson($safeState, 131072);
		$query=$this->queryResolver===null
			? PanelDataQuery::fromArray($safeState)
			: ($this->queryResolver)($context, $safeState, $this);
		if(!$query instanceof PanelDataQuery){ throw new \UnexpectedValueException('Panel DataSurface query resolvers must return PanelDataQuery.'); }
		if(array_key_exists('canvas_filter',$safeState)){
			if(!$this->canvas instanceof PanelDataCanvasSpec||!is_array($safeState['canvas_filter'])||array_keys($safeState['canvas_filter'])!==['values']||!is_array($safeState['canvas_filter']['values'])||!array_is_list($safeState['canvas_filter']['values'])){throw new \InvalidArgumentException('Panel DataSurface canvas filter state is invalid.');}
			$query=$this->canvas->applyCrossFilter($query,$safeState['canvas_filter']['values']);
		}
		return $query->tenant($context->tenant());
	}

	/** Safe state that can be embedded in a signed, inspectable token without credentials. @return array<string,mixed> */
	public function safeState(PanelDataQuery|array|null $query=null): array {
		$state=$query instanceof PanelDataQuery ? $query->urlState() : ($query ?? []);
		if($state!==[] && array_is_list($state)){ throw new \InvalidArgumentException('Panel DataSurface query state must be object-like.'); }
		self::assertPublicStateKeys($state);
		PanelDataSurfaceGuard::assertJson($state, 131072);
		return $state;
	}

	private static function assertPublicStateKeys(mixed $value): void {
		if(!is_array($value)){ return; }
		$sensitive=['tenant','authorization','metadata','cursor','api_token','access_token','password','secret','credential','credentials','private_key'];
		foreach($value as $key=>$item){
			if(is_string($key)){
				$normalized=strtolower(str_replace(['-','.'], '_', trim($key)));
				if(in_array($normalized, $sensitive, true)){ throw new \InvalidArgumentException('Panel DataSurface query state contains a server-owned or sensitive field.'); }
			}
			self::assertPublicStateKeys($item);
		}
	}

	public function fingerprint():string{return hash('sha256',PanelDataSurfaceGuard::canonicalJson($this->jsonSerialize()));}

	/** Secret-free definition manifest. */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_data_surface_definition','version'=>1,'id'=>$this->id,
				'resource'=>$this->resource,'source'=>$this->source,'surface'=>$this->surface->value,
				'projection'=>$this->projection->jsonSerialize(),'default_range'=>$this->defaultRange->jsonSerialize(),
				'canvas'=>$this->canvas?->jsonSerialize(),'options'=>$this->options,'query_resolver'=>$this->queryResolver!==null,
		];
	}
}
