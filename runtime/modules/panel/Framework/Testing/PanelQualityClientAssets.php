<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Optional browser quality auditor used by CI, theme workbenches, and extensions. */
final class PanelQualityClientAssets {
	public static function javascript(): string { return <<<'JS'
(function(window,document){
if(window.DataphyrePanelQuality){return;}
function visible(node){var style=getComputedStyle(node),rect=node.getBoundingClientRect();return !node.hidden&&node.getAttribute("aria-hidden")!=="true"&&style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0&&rect.height>0;}
function label(node){var id=node.id,aria=node.getAttribute("aria-label")||node.getAttribute("aria-labelledby")||node.getAttribute("title")||"";if(aria.trim()){return true;}if(id&&document.querySelector('label[for="'+CSS.escape(id)+'"]')){return true;}return !!node.closest("label");}
function rgb(value){var match=String(value).match(/[\d.]+/g);return match?match.slice(0,3).map(Number):[0,0,0];}
function luminance(color){return rgb(color).map(function(value){value/=255;return value<=.03928?value/12.92:Math.pow((value+.055)/1.055,2.4);}).reduce(function(sum,value,index){return sum+value*[.2126,.7152,.0722][index];},0);}
function contrast(foreground,background){var a=luminance(foreground),b=luminance(background);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);}
function issue(issues,rule,severity,node,message,detail){issues.push({rule:rule,severity:severity,message:message,element:node&&node.outerHTML?node.outerHTML.slice(0,500):"",detail:detail||{}});}
async function audit(root,options){root=root||document;options=options||{};var issues=[],metrics={focusable:0,controls:0,contrast:0,targets:0,overflow:0,dialogs:0},ids=new Map();
root.querySelectorAll("[id]").forEach(function(node){if(ids.has(node.id)){issue(issues,"duplicate-id","serious",node,"Duplicate id: "+node.id);}ids.set(node.id,node);});
root.querySelectorAll('input:not([type="hidden"]),select,textarea').forEach(function(node){if(!visible(node)){return;}metrics.controls++;if(!label(node)){issue(issues,"control-label","critical",node,"Visible control has no accessible label.");}});
root.querySelectorAll('a[href],button,input:not([type="hidden"]),select,textarea,[tabindex]').forEach(function(node){if(!visible(node)){return;}metrics.focusable++;var tabindex=parseInt(node.getAttribute("tabindex")||"0",10);if(tabindex>0){issue(issues,"positive-tabindex","serious",node,"Positive tabindex overrides document focus order.",{tabindex:tabindex});}var rect=node.getBoundingClientRect();metrics.targets++;if(rect.width<24||rect.height<24){issue(issues,"target-size","moderate",node,"Interactive target is smaller than 24 by 24 CSS pixels.",{width:rect.width,height:rect.height});}var style=getComputedStyle(node),ratio=contrast(style.color,style.backgroundColor);metrics.contrast++;if(ratio<3){issue(issues,"contrast","serious",node,"Interactive text contrast is below 3:1.",{ratio:ratio});}});
root.querySelectorAll('[role="dialog"],dialog').forEach(function(node){if(!visible(node)){return;}metrics.dialogs++;if(!node.getAttribute("aria-label")&&!node.getAttribute("aria-labelledby")){issue(issues,"dialog-name","critical",node,"Visible dialog has no accessible name.");}if(!node.querySelector('[autofocus],[data-dp-panel-modal-focus],button,input,select,textarea,a[href]')){issue(issues,"dialog-focus","serious",node,"Dialog has no focusable entry target.");}});
root.querySelectorAll("*").forEach(function(node){if(!visible(node)){return;}var rect=node.getBoundingClientRect();if(rect.right>document.documentElement.clientWidth+2||rect.left<-2){metrics.overflow++;issue(issues,"viewport-overflow","serious",node,"Visible element escapes the viewport.",{left:rect.left,right:rect.right,viewport:document.documentElement.clientWidth});}});
if(window.axe&&options.axe!==false){try{var axeResult=await window.axe.run(root,options.axeOptions||{});axeResult.violations.forEach(function(violation){violation.nodes.forEach(function(node){issues.push({rule:"axe:"+violation.id,severity:violation.impact||"moderate",message:violation.help,element:(node.html||"").slice(0,500),detail:{help:violation.helpUrl,target:node.target}});});});}catch(error){issue(issues,"axe-runtime","serious",null,String(error&&error.message||error));}}
var failOn=options.failOn||["critical","serious"],passed=!issues.some(function(item){return failOn.indexOf(item.severity)>=0;}),report={type:"dataphyre_panel_browser_quality",passed:passed,issues:issues,metrics:metrics,viewport:{width:innerWidth,height:innerHeight,deviceScaleFactor:devicePixelRatio},preferences:{direction:getComputedStyle(document.documentElement).direction,reducedMotion:matchMedia("(prefers-reduced-motion: reduce)").matches,forcedColors:matchMedia("(forced-colors: active)").matches,dark:matchMedia("(prefers-color-scheme: dark)").matches}};document.dispatchEvent(new CustomEvent("dp:panel-quality-complete",{detail:report}));return report;}
window.DataphyrePanelQuality={audit:audit,contrast:contrast,version:1};
})(window,document);
JS; }
}
