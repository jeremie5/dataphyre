<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the lifecycle kernel shared by Panel browser controllers.
 */
trait PanelRendererAssetsRuntimeKernelScripts {
	/**
	 * Returns abortable controller and listener ownership primitives.
	 */
	private static function runtimeKernelScript(): string {
		return <<<'JS'
(function(global){
	var panel=global.DataphyrePanel=global.DataphyrePanel||{};
	var previous=panel.runtimeController;
	if(previous&&typeof previous.dispose==="function"){
		previous.dispose("replaced");
	}
	var abortController=typeof AbortController==="function" ? new AbortController() : null;
	var runtime={
		version:"2",
		activeController:"kernel",
		controllers:{},
		disposed:false,
		dispose:function(reason){
			if(runtime.disposed){return;}
			runtime.disposed=true;
			runtime.disposeReason=String(reason||"disposed");
			if(abortController){abortController.abort(runtime.disposeReason);}
		},
		signal:abortController ? abortController.signal : null
	};
	panel.runtimeController=runtime;
	panel.controllers=runtime.controllers;
})(window);
function dpPanelControllerRuntime(){
	return window.DataphyrePanel&&window.DataphyrePanel.runtimeController;
}
function dpPanelBeginController(name){
	var runtime=dpPanelControllerRuntime();
	if(!runtime||runtime.disposed){return null;}
	name=String(name||"anonymous");
	runtime.activeController=name;
	if(!runtime.controllers[name]){
		runtime.controllers[name]={name:name,listeners:{},listenerCount:0};
	}
	return runtime.controllers[name];
}
function dpPanelListen(target,type,listener,options){
	if(!target||typeof target.addEventListener!=="function"||typeof listener!=="function"){return listener;}
	var runtime=dpPanelControllerRuntime();
	var controller=runtime&&dpPanelBeginController(runtime.activeController);
	var listenerOptions=options;
	if(runtime&&runtime.signal){
		if(typeof options==="boolean"){
			listenerOptions={capture:options,signal:runtime.signal};
		}
		else {
			listenerOptions=Object.assign({},options||{}, {signal:runtime.signal});
		}
	}
	target.addEventListener(type,listener,listenerOptions);
	if(controller){
		controller.listenerCount++;
		controller.listeners[type]=(controller.listeners[type]||0)+1;
	}
	return listener;
}
dpPanelBeginController("foundation");
JS;
	}
}
