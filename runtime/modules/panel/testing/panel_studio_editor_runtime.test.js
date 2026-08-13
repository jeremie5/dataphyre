#!/usr/bin/env node
/*
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
"use strict";

const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");
const {spawnSync}=require("node:child_process");

const panelRoot=path.resolve(__dirname,"..");
const assetRoot=path.join(panelRoot,"Framework","Studio","Rendering","Assets");
const source=fs.readFileSync(path.join(assetRoot,"panel-studio-editor.js"),"utf8");
const css=fs.readFileSync(path.join(assetRoot,"panel-studio-editor.css"),"utf8");
const fixture=fs.readFileSync(path.join(__dirname,"fixtures","panel_studio_editor_showroom.php"),"utf8");

function occurrences(haystack,needle){return haystack.split(needle).length-1;}

const syntax=spawnSync(process.execPath,["--check",path.join(assetRoot,"panel-studio-editor.js")],{encoding:"utf8",windowsHide:true});
assert.equal(syntax.status,0,syntax.stderr||"Studio editor JavaScript parses");
for(const forbidden of ["innerHTML","insertAdjacentHTML","outerHTML","eval(","new Function","document.write","javascript:"]){assert.equal(source.includes(forbidden),false,"Studio runtime excludes "+forbidden);}
for(const forbidden of ["!important","overflow-x:auto","overflow-x: auto"]){assert.equal(css.includes(forbidden),false,"Studio CSS excludes "+forbidden);}
for(const mojibake of [String.fromCharCode(0xfffd),String.fromCharCode(0x00c3),String.fromCharCode(0x00c2)]){assert.equal(source.includes(mojibake)||css.includes(mojibake),false,"Studio assets exclude mojibake");}
assert.equal(/[\u2013\u2014]/u.test(source+css+fixture),false,"Studio visible source excludes typographic dash corruption risks");
assert.equal(occurrences(source,"MutationObserver"),2,"Studio runtime owns one dynamic-mount observer and feature check");
assert.equal(source.includes("var mounted=new Set()"),true,"Studio runtime tracks mounted roots for deterministic disposal");
assert.equal(source.includes("record.removedNodes.forEach(scheduleRemoval)"),true,"Studio runtime observes terminal removals as well as mounts");
assert.equal(source.includes("if(!root.isConnected){dispose(root);}"),true,"same-turn moves survive delayed terminal-removal cleanup");
assert.equal(source.includes("Array.from(mounted).forEach(dispose)"),true,"Studio stop releases every mounted root");
assert.equal(source.includes("count:function(){return mounted.size;}"),true,"Studio exposes bounded lifecycle ownership for browser contracts");
assert.equal(source.includes("control===instance.root"),true,"Studio root state cannot masquerade as a mobile-panel control and cancel form submissions");
assert.equal(source.includes("textContent"),true,"Studio runtime builds user-visible content through textContent");
assert.equal(source.includes("stableStringify"),true,"Studio runtime serializes definitions deterministically");
assert.equal(source.includes("movePlacement"),true,"Studio runtime exposes deterministic pointer placement");
assert.equal(source.includes('event.altKey&&["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"]'),true,"Studio runtime provides keyboard reordering");
assert.equal(source.includes('event.pointerType==="mouse"'),true,"Studio runtime distinguishes touch pointer reordering");
assert.equal(source.includes('event.ctrlKey||event.metaKey'),true,"Studio runtime provides platform undo shortcuts");
assert.equal(source.includes("renamedPath"),true,"Inspector key changes preserve the renamed focus path");
assert.equal(source.includes("DataphyrePanelStudioEditor.version===2"),true,"Duplicate inline assets reuse the first Studio runtime");
assert.equal(source.includes("payload.contract_version!==3"),true,"Studio runtime and renderer use the same live-collaboration contract");
assert.equal(source.includes("new DOMParser()"),true,"Server-rendered collaboration fragments cross an inert parser boundary");
assert.equal(source.includes("document.importNode(replacement,true)"),true,"Validated collaboration fragments are imported instead of interpreted as raw markup");
assert.equal(source.includes("new FormData(instance.form)"),true,"Collaboration mutations preserve the current unsaved editor state");
assert.equal(source.includes("new AbortController()"),true,"Collaboration requests own bounded cancellation");
assert.equal(source.includes("navigator.sendBeacon"),true,"Presence release survives terminal page lifecycle transitions");
assert.equal(source.includes("network_ambiguous"),true,"Ambiguous mutations have an explicit non-replay failure state");
assert.equal(source.includes('collaborationRequest(instance,"mutate"'),true,"Collaboration mutations use the signed transport boundary");
assert.equal(source.includes("scheduleCollaborationPoll(instance,0)"),true,"Mutation ambiguity reconciles through a read-only delta");
assert.equal(source.includes("captureCollaborationDrafts"),true,"Server fragment refreshes preserve local review drafts");
assert.equal(source.includes("captureCollaborationFocus"),true,"Server fragment refreshes preserve stable focus");
assert.equal(css.includes(".dp-studio-live-status"),true,"Studio visibly exposes live, reconnecting, and offline state");
assert.equal(css.includes("@media(max-width:980px)"),true,"Studio owns tablet reflow");
assert.equal(css.includes("@media(max-width:720px)"),true,"Studio owns mobile reflow");
assert.equal(css.includes("prefers-reduced-motion:reduce"),true,"Studio honors reduced motion");
assert.equal(css.includes("forced-colors:active"),true,"Studio honors forced colors");
assert.equal(css.includes("prefers-reduced-transparency:reduce"),true,"Studio honors reduced transparency");
assert.equal(css.includes("padding-inline-start"),true,"Studio hierarchy uses logical spacing");
assert.equal(css.includes("container-type"),false,"Studio avoids fragile nested field containment");
assert.equal(fixture.includes("PanelStudioEditor::render"),true,"Browser fixture renders the public route-free facade");
assert.equal(fixture.includes("PanelStudioEditorCommand::select"),true,"Browser fixture opens a deterministic inspector selection");
console.log("panel Studio editor runtime source audit passed");
