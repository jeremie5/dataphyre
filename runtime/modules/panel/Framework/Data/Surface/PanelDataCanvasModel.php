<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, bounded server projection consumed by advanced DataCanvas SSR and enhancement. */
final class PanelDataCanvasModel implements \JsonSerializable {
	private const MAX_BYTES=2097152;

	/** @param array<string,mixed> $model @param list<array{code:string,count:int}> $diagnostics */
	public function __construct(
		private readonly PanelDataSurfaceType $surface,
		private readonly int $recordCount,
		private readonly array $model,
		private readonly array $diagnostics=[]
	){
		if(!$surface->advanced()){throw new \InvalidArgumentException('Panel DataCanvas models require an advanced surface type.');}
		if($recordCount<0||$recordCount>PanelDataSurfaceRange::MAX_FETCH){throw new \InvalidArgumentException('Panel DataCanvas record count is invalid.');}
		if($model!==[]&&array_is_list($model)){throw new \InvalidArgumentException('Panel DataCanvas model must be object-like.');}
		if(!array_is_list($diagnostics)||count($diagnostics)>32){throw new \InvalidArgumentException('Panel DataCanvas diagnostics are invalid.');}
		foreach($diagnostics as$diagnostic){
			if(!is_array($diagnostic)||array_keys($diagnostic)!==['code','count']||!is_string($diagnostic['code'])||preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$diagnostic['code'])!==1||!is_int($diagnostic['count'])||$diagnostic['count']<1||$diagnostic['count']>$recordCount){throw new \InvalidArgumentException('Panel DataCanvas diagnostic entry is invalid.');}
		}
		PanelDataSurfaceGuard::assertJson($model,self::MAX_BYTES);
		PanelDataSurfaceGuard::assertJson($this->jsonSerialize(),self::MAX_BYTES);
	}

	public function surface():PanelDataSurfaceType{return$this->surface;}
	public function recordCount():int{return$this->recordCount;}
	/** @return array<string,mixed> */public function model():array{return$this->model;}
	/** @return list<array{code:string,count:int}> */public function diagnostics():array{return$this->diagnostics;}
	public function jsonSerialize():array{return[
		'type'=>'panel_data_canvas_model','version'=>1,'surface'=>$this->surface->value,'record_count'=>$this->recordCount,
		'model'=>$this->model,'diagnostics'=>$this->diagnostics,
	];}
}
