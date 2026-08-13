<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Safe route-free rendering and CSRF boundary for an embedded Studio editor. */
final class PanelStudioEditorOptions implements \JsonSerializable {
	private readonly string $actionUrl;
	private readonly string $previewUrl;
	private readonly string $csrfName;
	private readonly string $csrfToken;
	private readonly string $theme;
	private readonly string $direction;
	private readonly string $title;
	private readonly string $editorId;
	private readonly string $nonce;
	private readonly bool $inlineAssets;
	private readonly string $zoom;
	private readonly string $reflow;
	private readonly ?PanelStudioCollaborationConnector $collaborationConnector;
	private readonly ?PanelStudioCollaborationTransport $collaborationTransport;
	/** @param array<string,mixed> $options */
	public function __construct(array $options){
		$extra=array_diff(array_keys($options),['action_url','preview_url','csrf_name','csrf_token','theme','direction','title','editor_id','nonce','inline_assets','zoom','reflow','collaboration_connector','collaboration_transport']);
		if($extra!==[]){throw new \InvalidArgumentException('Studio editor options contain unsupported keys.');}
		$this->actionUrl=self::localUrl($options['action_url']??'','action');$this->previewUrl=self::localUrl($options['preview_url']??'','preview');
		$this->csrfName=is_string($options['csrf_name']??null)?$options['csrf_name']:'_token';
		if(preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,63}$/',$this->csrfName)!==1){throw new \InvalidArgumentException('Studio editor CSRF field names are invalid.');}
		$this->csrfToken=is_string($options['csrf_token']??null)?$options['csrf_token']:'';
		if(strlen($this->csrfToken)<16||strlen($this->csrfToken)>4096||str_contains($this->csrfToken,"\0")||str_contains($this->csrfToken,"\r")||str_contains($this->csrfToken,"\n")){throw new \InvalidArgumentException('Studio editor CSRF tokens must contain 16 to 4096 safe bytes.');}
		$this->theme=is_string($options['theme']??null)?strtolower($options['theme']):'auto';if(!in_array($this->theme,['auto','light','dark','glass'],true)){throw new \InvalidArgumentException('Studio editor theme is invalid.');}
		$this->direction=is_string($options['direction']??null)?strtolower($options['direction']):'auto';if(!in_array($this->direction,['auto','ltr','rtl'],true)){throw new \InvalidArgumentException('Studio editor direction is invalid.');}
		$this->title=self::text($options['title']??'Panel Studio','title',160);
		$this->editorId=is_string($options['editor_id']??null)?$options['editor_id']:'dp-panel-studio-editor';if(preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/',$this->editorId)!==1){throw new \InvalidArgumentException('Studio editor DOM identifiers are invalid.');}
		$this->nonce=is_string($options['nonce']??null)?$options['nonce']:'';if(strlen($this->nonce)>256||str_contains($this->nonce,"\0")||str_contains($this->nonce,'"')||str_contains($this->nonce,"'")||preg_match('/[\r\n<>]/',$this->nonce)===1){throw new \InvalidArgumentException('Studio editor CSP nonces are invalid.');}
		$inlineAssets=$options['inline_assets']??false;if(!is_bool($inlineAssets)){throw new \InvalidArgumentException('Studio editor inline-assets option must be boolean.');}$this->inlineAssets=$inlineAssets;
		$this->zoom=is_int($options['zoom']??null)?(string)$options['zoom']:(is_string($options['zoom']??null)?$options['zoom']:'100');if(!in_array($this->zoom,['75','100','125','fit'],true)){throw new \InvalidArgumentException('Studio editor zoom is invalid.');}
		$this->reflow=is_string($options['reflow']??null)?strtolower($options['reflow']):'desktop';if(!in_array($this->reflow,['desktop','tablet','mobile'],true)){throw new \InvalidArgumentException('Studio editor reflow mode is invalid.');}
		$connector=$options['collaboration_connector']??null;if($connector!==null&&!$connector instanceof PanelStudioCollaborationConnector){throw new \InvalidArgumentException('Studio editor collaboration connector is invalid.');}$this->collaborationConnector=$connector;
		$transport=$options['collaboration_transport']??null;if($transport!==null&&!$transport instanceof PanelStudioCollaborationTransport){throw new \InvalidArgumentException('Studio editor collaboration transport is invalid.');}if($transport!==null&&$connector===null){throw new \InvalidArgumentException('Studio editor collaboration transport requires a collaboration connector.');}$this->collaborationTransport=$transport;
	}
	/** @param array<string,mixed> $options */ public static function make(array $options):self{return new self($options);}
	public function actionUrl():string{return$this->actionUrl;}
	public function previewUrl():string{return$this->previewUrl;}
	public function csrfName():string{return$this->csrfName;}
	public function csrfToken():string{return$this->csrfToken;}
	public function theme():string{return$this->theme;}
	public function direction():string{return$this->direction;}
	public function title():string{return$this->title;}
	public function editorId():string{return$this->editorId;}
	public function nonce():string{return$this->nonce;}
	public function inlineAssets():bool{return$this->inlineAssets;}
	public function zoom():string{return$this->zoom;}
	public function reflow():string{return$this->reflow;}
	public function collaborationConnector():?PanelStudioCollaborationConnector{return$this->collaborationConnector;}
	public function collaborationTransport():?PanelStudioCollaborationTransport{return$this->collaborationTransport;}
	/** @param array<string,mixed> $input */ public function verifyCsrf(array $input):bool{$value=$input[$this->csrfName]??null;return is_string($value)&&hash_equals($this->csrfToken,$value);}
	public function jsonSerialize():array{return['type'=>'panel_studio_editor_options','version'=>3,'action_url'=>$this->actionUrl,'preview_url_configured'=>$this->previewUrl!=='','theme'=>$this->theme,'direction'=>$this->direction,'title'=>$this->title,'editor_id'=>$this->editorId,'inline_assets'=>$this->inlineAssets,'zoom'=>$this->zoom,'reflow'=>$this->reflow,'collaboration'=>['active'=>$this->collaborationConnector!==null,'connector'=>$this->collaborationConnector?->manifest(),'live_transport_active'=>$this->collaborationTransport!==null,'transport'=>$this->collaborationTransport?->jsonSerialize()],'csrf'=>['required'=>true,'field'=>$this->csrfName,'token_serialized'=>false],'csp'=>['nonce_configured'=>$this->nonce!=='']];}
	private static function localUrl(mixed $value,string $label):string{
		if(!is_string($value)||strlen($value)>2048||str_contains($value,"\0")||str_contains($value,"\r")||str_contains($value,"\n")){throw new \InvalidArgumentException("Studio editor {$label} URLs are invalid.");}
		if($value===''||preg_match('~^/(?!/)[A-Za-z0-9._!$&\'()*+,;=:@%/?#-]*$~D',$value)===1){return$value;}
		throw new \InvalidArgumentException("Studio editor {$label} URLs must be same-origin absolute paths.");
	}
	private static function text(mixed $value,string $label,int $maximum):string{
		if(!is_string($value)||trim($value)===''||strlen($value)>$maximum||preg_match('//u',$value)!==1||preg_match('/<\/?[a-z!][^>]*>/i',$value)===1||PanelSensitiveDataSanitizer::sanitize($value,['max_string_bytes'=>$maximum])!==$value){throw new \InvalidArgumentException("Studio editor {$label} is invalid.");}
		return$value;
	}
}
