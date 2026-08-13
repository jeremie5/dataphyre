<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Shared request cache and renderer integration for navigation intents. */
final class PanelNavigationIntentRuntime {
	public static function manager(): PanelNavigationIntentManager { return PanelConfig::navigationIntentManager(); }

	/** @param array<string,mixed> $expected */
	public static function resolve(PanelRequest $request, bool $privileged=false, bool $consume=false, array $expected=[]): PanelNavigationResolution {
		$resolution=self::manager()->resolve($request, $privileged, $consume, $expected);
		if($resolution->verification()->migrated()){
			@trigger_error('Unsigned Panel return_to navigation is deprecated; configure and submit a signed navigation_intent.', E_USER_DEPRECATED);
			PanelTrace::record('navigation_intent.unsigned_migration', ['target'=>$resolution->target(),'method'=>$request->method()]);
		}
		elseif(!$resolution->verification()->valid()){
			PanelTrace::record('navigation_intent.rejected', ['code'=>$resolution->verification()->code(),'method'=>$request->method(),'privileged'=>$privileged]);
		}
		return $resolution;
	}

	public static function returnTarget(PanelRequest $request): ?string {
		return self::resolve($request, self::privileged($request), false)->target();
	}

	public static function hiddenInputs(string $target, PanelRequest $request, array $options=[]): string {
		$target=PanelNavigationTarget::normalize($target);
		if($target===null){ return ''; }
		$html='<input type="hidden" name="return_to" value="'.self::escape($target).'">';
		try{ $token=self::manager()->issue($target, $request, $options); }
		catch(\Throwable $exception){
			PanelTrace::record('navigation_intent.issue_failed', ['exception'=>$exception]);
			$token=null;
		}
		if(is_string($token) && $token!==''){
			$html.='<input type="hidden" name="'.self::escape(self::manager()->inputName()).'" value="'.self::escape($token).'" data-dp-panel-navigation-intent="1">';
		}
		return $html;
	}

	/** @return array<string,string> */
	public static function query(string $target, PanelRequest $request, array $options=[]): array {
		$target=PanelNavigationTarget::normalize($target);
		if($target===null){ return []; }
		$query=['return_to'=>$target];
		try{ $token=self::manager()->issue($target, $request, $options); }
		catch(\Throwable $exception){ PanelTrace::record('navigation_intent.issue_failed', ['exception'=>$exception]); $token=null; }
		if(is_string($token) && $token!==''){ $query[self::manager()->inputName()]=$token; }
		return $query;
	}

	public static function privileged(PanelRequest $request): bool {
		if(!in_array(strtoupper($request->method()), ['GET','HEAD','OPTIONS'], true)){ return true; }
		return in_array($request->operation(), ['store','update','delete','force_delete','restore','duplicate','transition','bulk_update','bulk_delete','bulk_restore','bulk_duplicate','bulk_transition','inline_update'], true);
	}

	public static function flush(): void {}

	private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}
