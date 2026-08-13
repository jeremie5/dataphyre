<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One Studio collaboration mutation result with its refreshed public snapshot. */
final class PanelStudioCollaborationResult implements \JsonSerializable {
	public function __construct(
		private readonly string $operation,
		private readonly bool $changed,
		private readonly ?string $resourceId,
		private readonly PanelStudioWorkspaceSnapshot $snapshot
	){
		if(preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D',$operation)!==1){throw new \InvalidArgumentException('Studio collaboration result operation is invalid.');}
		if($resourceId!==null){PanelCollaborationStateEngine::identifier($resourceId,'Studio collaboration resource id',128);}
	}
	public function operation():string{return$this->operation;}
	public function changed():bool{return$this->changed;}
	public function resourceId():?string{return$this->resourceId;}
	public function snapshot():PanelStudioWorkspaceSnapshot{return$this->snapshot;}
	/** @return array<string,mixed> */
	public function jsonSerialize():array{return['type'=>'panel_studio_collaboration_result','version'=>1,'operation'=>$this->operation,'changed'=>$this->changed,'resource_id'=>$this->resourceId,'snapshot'=>$this->snapshot->jsonSerialize()];}
}
