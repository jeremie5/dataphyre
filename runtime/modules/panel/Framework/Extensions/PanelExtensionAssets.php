<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Optional stable browser API served separately from the core Panel bundle. */
final class PanelExtensionAssets {
	public static function javascript(): string { return <<<'JS'
(function(window,document){
if(window.DataphyrePanelExtensions){return;}
var apiVersion=1,extensions=new Map(),listeners=new Map(),cleanups=new WeakMap();
function name(value){return String(value||"").trim().toLowerCase().replace(/[^a-z0-9_.:-]+/g,"-").replace(/^-|-$/g,"");}
function on(event,listener,options){event=name(event);options=options||{};if(!event||typeof listener!=="function"){throw new TypeError("A valid extension event listener is required.");}var entry={listener:listener,priority:Number(options.priority||0),scope:options.scope||"*"},items=listeners.get(event)||[];items.push(entry);items.sort(function(a,b){return b.priority-a.priority;});listeners.set(event,items);return function(){listeners.set(event,(listeners.get(event)||[]).filter(function(item){return item!==entry;}));};}
async function emit(event,detail,scope){event=name(event);var value=detail;for(var entry of listeners.get(event)||[]){if(entry.scope!=="*"&&scope!=="*"&&entry.scope!==scope){continue;}var result=await entry.listener(value,{event:event,scope:scope||"*",apiVersion:apiVersion});if(result!==undefined){value=result;}}document.dispatchEvent(new CustomEvent("dp:panel-extension:"+event,{detail:{value:value,scope:scope||"*"}}));return value;}
function register(descriptor,setup){var id=name(descriptor&&descriptor.id);if(!id){throw new TypeError("A Panel extension id is required.");}if(extensions.has(id)){throw new Error("Panel extension already registered: "+id);}var record={descriptor:Object.assign({api_version:apiVersion},descriptor),setup:setup,instance:null};extensions.set(id,record);return record;}
async function mount(root,context){if(!root){return;}var owned=[];for(var pair of extensions){var record=pair[1];if(typeof record.setup!=="function"){continue;}var instance=await record.setup({root:root,context:context||{},on:on,emit:emit,apiVersion:apiVersion});record.instance=instance||null;if(typeof instance==="function"){owned.push(instance);}else if(instance&&typeof instance.destroy==="function"){owned.push(function(){instance.destroy();});}}cleanups.set(root,owned);await emit("mounted",{root:root,context:context||{}},context&&context.scope||"*");}
async function unmount(root){for(var cleanup of cleanups.get(root)||[]){try{await cleanup();}catch(error){console.error(error);}}cleanups.delete(root);await emit("unmounted",{root:root},"*");}
window.DataphyrePanelExtensions={apiVersion:apiVersion,register:register,get:function(id){return extensions.get(name(id))||null;},all:function(){return Array.from(extensions.values()).map(function(record){return record.descriptor;});},on:on,emit:emit,mount:mount,unmount:unmount};
document.addEventListener("dp:panel:mounted",function(event){mount(event.target,event.detail||{});});
document.addEventListener("dp:panel:before-unmount",function(event){unmount(event.target);});
})(window,document);
JS; }
	public static function scriptTag(string $url): string { $url=trim($url); if($url==='' || preg_match('/^(?:javascript|data):/i', $url)===1){ return ''; } return '<script src="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" defer data-dp-panel-extension-api="1"></script>'; }
}
