/*
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
(function(){
"use strict";

if(window.DataphyrePanelStudioEditor&&window.DataphyrePanelStudioEditor.version===2){if(typeof window.DataphyrePanelStudioEditor.start==="function"){window.DataphyrePanelStudioEditor.start();}else{window.DataphyrePanelStudioEditor.boot(document);}return;}

var instances=new WeakMap();
var mounted=new Set();
var observer=null;
var mobileMedia=typeof window.matchMedia==="function"?window.matchMedia("(max-width: 720px)"):null;
var mobileListener=null;
var MAX_HISTORY=50;
var PATH_PATTERN=/^[a-z][a-z0-9_.-]{0,127}(?:\/[a-z][a-z0-9_.-]{0,127}){0,12}$/;

function stableStringify(value){
	if(value===null||typeof value!=="object"){return JSON.stringify(value);}
	if(Array.isArray(value)){return "["+value.map(stableStringify).join(",")+"]";}
	return "{"+Object.keys(value).sort().map(function(key){return JSON.stringify(key)+":"+stableStringify(value[key]);}).join(",")+"}";
}
function clone(value){return JSON.parse(JSON.stringify(value));}
function text(value){return typeof value==="string"?value:String(value==null?"":value);}
function title(value){return text(value).replace(/[_-]+/g," ").replace(/\b\w/g,function(letter){return letter.toUpperCase();});}
function parts(path){if(!PATH_PATTERN.test(path)){throw new Error("invalid_editor_path");}return path.split("/");}
function parentPath(path){var values=parts(path);values.pop();return values.length?values.join("/"):null;}
function findNode(root,path){
	var keys=parts(path);if(root.key!==keys[0]){return null;}var node=root,parent=null,index=-1,current=root.key;
	for(var depth=1;depth<keys.length;depth++){
		parent=node;index=node.children.findIndex(function(child){return child.key===keys[depth];});if(index<0){return null;}node=node.children[index];current+="/"+node.key;
	}
	return{node:node,parent:parent,index:index,path:current,parentPath:parentPath(current)};
}
function walk(node,path,visit,depth){
	visit(node,path,depth);node.children.forEach(function(child){walk(child,path+"/"+child.key,visit,depth+1);});
}
function schemaFor(instance,kind){var entry=instance.registry.schemas&&instance.registry.schemas[kind];return entry&&entry.schema?entry.schema:null;}
function childRule(schema,kind){var rules=schema&&schema.children&&Array.isArray(schema.children.rules)?schema.children.rules:[];return rules.find(function(rule){return rule.kind===kind;})||null;}
function accepts(instance,parent,kind){var schema=schemaFor(instance,parent.kind);var rule=childRule(schema,kind);if(!rule){return false;}var count=parent.children.filter(function(child){return child.kind===kind;}).length;return count<rule.maximum&&parent.children.length<schema.children.maximum;}
function defaultProperties(instance,kind){
	var schema=schemaFor(instance,kind);var properties={};if(!schema){return properties;}
	(schema.properties||[]).forEach(function(property){if(property.has_default){properties[property.name]=clone(property.default);}});return properties;
}
function uniqueKey(parent,kind){var used=new Set(parent.children.map(function(child){return child.key;}));for(var index=1;index<=512;index++){var candidate=kind+"_"+index;if(!used.has(candidate)){return candidate;}}throw new Error("component_key_budget_exhausted");}
function selected(instance){return findNode(instance.model,instance.selection)||findNode(instance.model,instance.model.key);}
function diagnostic(path,code,message,severity){return{path:path,code:code,message:message,severity:severity||"error"};}
function propertyValid(property,value){
	if(value===null){return !!property.nullable;}
	if(property.type==="string"){return typeof value==="string";}
	if(property.type==="boolean"){return typeof value==="boolean";}
	if(property.type==="integer"){return Number.isInteger(value);}
	if(property.type==="number"){return typeof value==="number"&&Number.isFinite(value);}
	if(property.type==="scalar"){return typeof value==="string"||typeof value==="boolean"||typeof value==="number"&&Number.isFinite(value);}
	if(property.type==="string_list"){return Array.isArray(value)&&value.every(function(item){return typeof item==="string";});}
	if(property.type==="number_list"){return Array.isArray(value)&&value.every(function(item){return typeof item==="number"&&Number.isFinite(item);});}
	if(property.type==="scalar_map"){return value&&typeof value==="object"&&!Array.isArray(value)&&Object.values(value).every(function(item){return item===null||typeof item==="string"||typeof item==="boolean"||typeof item==="number"&&Number.isFinite(item);});}
	return false;
}
function validate(instance){
	var issues=[];
	walk(instance.model,instance.model.key,function(node,path){
		var schema=schemaFor(instance,node.kind);if(!schema){issues.push(diagnostic(path+".kind","schema_missing","This component is portable-only and has no trusted visual materializer."));return;}
		var properties=new Map((schema.properties||[]).map(function(property){return[property.name,property];}));
		Object.keys(node.properties).forEach(function(name){var property=properties.get(name);if(!property){issues.push(diagnostic(path+".properties."+name,"property_not_allowed","This property is not part of the trusted component schema."));return;}var value=node.properties[name];if(!propertyValid(property,value)){issues.push(diagnostic(path+".properties."+name,"invalid_property_type","The property value does not match its trusted type."));return;}if(Array.isArray(property.enum)&&property.enum.length&&!property.enum.some(function(item){return stableStringify(item)===stableStringify(value);})){issues.push(diagnostic(path+".properties."+name,"property_not_in_enum","Choose one of the trusted property values."));}});
		;(schema.properties||[]).forEach(function(property){if(property.required&&!Object.prototype.hasOwnProperty.call(node.properties,property.name)){issues.push(diagnostic(path+".properties."+property.name,"required_property_missing","This trusted property is required."));}});
		var minimum=schema.children&&schema.children.minimum||0;var maximum=schema.children&&schema.children.maximum||0;if(node.children.length<minimum||node.children.length>maximum){issues.push(diagnostic(path+".children","child_cardinality_violation","The component child count is outside its trusted bounds."));}
		var seen=new Set();node.children.forEach(function(child,index){if(seen.has(child.key)){issues.push(diagnostic(path+".children["+index+"].key","duplicate_identity","Sibling component keys must be unique."));}seen.add(child.key);if(!childRule(schema,child.kind)){issues.push(diagnostic(path+".children["+index+"].kind","child_kind_not_allowed","This child kind is not allowed here."));}});
		(schema.children.rules||[]).forEach(function(rule){var count=node.children.filter(function(child){return child.kind===rule.kind;}).length;if(count<rule.minimum||count>rule.maximum){issues.push(diagnostic(path+".children","child_kind_cardinality_violation","Required child-kind cardinality is not satisfied."));}});
	});
	var unique=new Map();instance.serverDiagnostics.concat(issues).forEach(function(issue){unique.set([issue.path,issue.code,issue.message,issue.severity].join("\u0000"),issue);});return Array.from(unique.values()).sort(function(left,right){return(left.path+left.code).localeCompare(right.path+right.code);});
}
function element(name,className){var node=document.createElement(name);if(className){node.className=className;}return node;}
function button(label,className){var node=element("button",className);node.type="button";node.textContent=label;return node;}
function setFocus(instance,token){if(!token){return;}var nodes=instance.root.querySelectorAll("[data-dp-studio-focus]");for(var index=0;index<nodes.length;index++){if(nodes[index].dataset.dpStudioFocus===token){nodes[index].focus({preventScroll:true});return;}}}
function activeToken(instance){var active=document.activeElement;return active&&instance.root.contains(active)?active.dataset.dpStudioFocus||"":"";}
function listen(instance,target,type,listener,options){target.addEventListener(type,listener,options);instance.cleanups.push(function(){target.removeEventListener(type,listener,options);});}
function dispose(root){var instance=instances.get(root);if(!instance){return false;}if(instance.collaboration){releasePresence(instance);}instance.cleanups.splice(0).forEach(function(cleanup){cleanup();});instances.delete(root);mounted.delete(root);delete root.dataset.dpStudioEnhanced;return true;}
function rootsIn(node){var roots=[];if(node&&node.nodeType===1&&node.matches&&node.matches("[data-dp-studio-editor]")){roots.push(node);}if(node&&node.nodeType===1&&node.querySelectorAll){node.querySelectorAll("[data-dp-studio-editor]").forEach(function(root){roots.push(root);});}return roots;}
function scheduleRemoval(node){var roots=rootsIn(node);if(!roots.length){return;}var cleanup=function(){roots.forEach(function(root){if(!root.isConnected){dispose(root);}});};if(typeof window.queueMicrotask==="function"){window.queueMicrotask(cleanup);}else{Promise.resolve().then(cleanup);}}
function announce(instance,message){instance.status.textContent=message;instance.status.dataset.dpStudioAnnouncement=String((Number(instance.status.dataset.dpStudioAnnouncement)||0)+1);}
function snapshot(instance){return{model:clone(instance.model),selection:instance.selection};}
function remember(instance){instance.history.push(snapshot(instance));if(instance.history.length>MAX_HISTORY){instance.history.shift();}instance.future=[];}
function mutate(instance,message,operation,focus){
	var previous=snapshot(instance);try{remember(instance);operation();}catch(error){instance.model=previous.model;instance.selection=previous.selection;instance.history.pop();announce(instance,"The editor change was rejected.");return false;}
	instance.serverDiagnostics=[];render(instance,focus||("node:"+instance.selection));announce(instance,message);return true;
}
function undo(instance){if(!instance.history.length){announce(instance,"There is no change to undo.");return;}instance.future.push(snapshot(instance));var state=instance.history.pop();instance.model=state.model;instance.selection=state.selection;instance.serverDiagnostics=[];render(instance,"node:"+instance.selection);announce(instance,"The last change was undone.");}
function redo(instance){if(!instance.future.length){announce(instance,"There is no change to redo.");return;}instance.history.push(snapshot(instance));var state=instance.future.pop();instance.model=state.model;instance.selection=state.selection;instance.serverDiagnostics=[];render(instance,"node:"+instance.selection);announce(instance,"The change was restored.");}
function moveRelative(instance,path,direction){
	var location=findNode(instance.model,path);if(!location||!location.parent){announce(instance,"The root component cannot be moved.");return;}
	mutate(instance,"Component moved.",function(){
		location=findNode(instance.model,path);var parent=location.parent;var node=location.node;var index=location.index;
		if(direction==="up"||direction==="down"){
			var target=direction==="up"?index-1:index+1;if(target<0||target>=parent.children.length){throw new Error("move_boundary");}parent.children.splice(index,1);parent.children.splice(target,0,node);instance.selection=location.parentPath+"/"+node.key;return;
		}
		if(direction==="indent"){
			if(index===0){throw new Error("indent_boundary");}var previous=parent.children[index-1];if(!accepts(instance,previous,node.kind)||previous.children.some(function(child){return child.key===node.key;})){throw new Error("invalid_indent");}parent.children.splice(index,1);previous.children.push(node);instance.selection=location.parentPath+"/"+previous.key+"/"+node.key;return;
		}
		var parentLocation=findNode(instance.model,location.parentPath);if(!parentLocation||!parentLocation.parent||!accepts(instance,parentLocation.parent,node.kind)||parentLocation.parent.children.some(function(child){return child.key===node.key;})){throw new Error("invalid_outdent");}parent.children.splice(index,1);parentLocation.parent.children.splice(parentLocation.index+1,0,node);instance.selection=parentLocation.parentPath+"/"+node.key;
	},"node:"+path);
}
function movePlacement(instance,sourcePath,targetPath,placement){
	if(sourcePath===targetPath||targetPath.indexOf(sourcePath+"/")===0){return false;}
	return mutate(instance,"Component reordered by pointer.",function(){
		var source=findNode(instance.model,sourcePath);var target=findNode(instance.model,targetPath);if(!source||!source.parent||!target){throw new Error("invalid_drag");}
		var destination=placement==="inside"?target.node:target.parent;if(!destination||!accepts(instance,destination,source.node.kind)||destination.children.some(function(child){return child!==source.node&&child.key===source.node.key;})){throw new Error("invalid_drop");}
		source.parent.children.splice(source.index,1);target=findNode(instance.model,targetPath);destination=placement==="inside"?target.node:target.parent;if(placement==="inside"){destination.children.push(source.node);}else{var offset=placement==="after"?1:0;destination.children.splice(target.index+offset,0,source.node);}var destinationPath=placement==="inside"?targetPath:target.parentPath;instance.selection=destinationPath+"/"+source.node.key;
	},"node:"+sourcePath);
}
function renderPalette(instance){
	instance.root.querySelectorAll("[data-dp-studio-add]").forEach(function(control){var selection=selected(instance);var kind=control.dataset.dpStudioAdd;var portable=!schemaFor(instance,kind);var allowed=!portable&&!!selection&&accepts(instance,selection.node,kind);control.disabled=!allowed;control.setAttribute("aria-disabled",allowed?"false":"true");control.title=portable?"This kind remains portable-only and cannot be materialized.":allowed?"Add to "+selection.node.key:"Select a compatible parent first";});
}
function treeItem(instance,node,path,level){
	var item=element("li");item.setAttribute("role","none");var row=element("div","dp-studio-tree-row");row.dataset.dpStudioPath=path;row.dataset.dpStudioSelected=path===instance.selection?"true":"false";
	var select=button("","dp-studio-tree-select");select.setAttribute("role","treeitem");select.setAttribute("aria-level",String(level));select.setAttribute("aria-selected",path===instance.selection?"true":"false");if(node.children.length){select.setAttribute("aria-expanded","true");}select.tabIndex=path===instance.selection?0:-1;select.draggable=true;select.dataset.dpStudioTreeitem="1";select.dataset.dpStudioPath=path;select.dataset.dpStudioAction="select";select.dataset.dpStudioFocus="node:"+path;
	var kind=element("span","dp-studio-tree-kind");kind.textContent=node.kind.slice(0,3);var words=element("span","dp-studio-tree-text");var strong=element("strong");strong.textContent=node.properties.label||title(node.key);var small=element("small");small.textContent=node.key;words.append(strong,small);select.append(kind,words);row.append(select);
	var tools=element("span","dp-studio-tree-tools");[["up","Up","\u2191"],["down","Down","\u2193"],["indent","Indent","\u21b3"],["outdent","Outdent","\u21b0"]].forEach(function(entry){var tool=button(entry[2],"dp-studio-tree-tool");tool.setAttribute("aria-label",entry[1]+" "+node.key);tool.dataset.dpStudioAction="move";tool.dataset.dpStudioDirection=entry[0];tool.dataset.dpStudioPath=path;tool.dataset.dpStudioFocus="move:"+entry[0]+":"+path;tools.append(tool);});if(level>1){var remove=button("\u00d7","dp-studio-tree-tool dp-studio-button-danger");remove.setAttribute("aria-label","Remove "+node.key);remove.dataset.dpStudioAction="remove";remove.dataset.dpStudioPath=path;remove.dataset.dpStudioFocus="remove:"+path;tools.append(remove);}row.append(tools);item.append(row);
	if(node.children.length){var children=element("ol");children.setAttribute("role","group");node.children.forEach(function(child){children.append(treeItem(instance,child,path+"/"+child.key,level+1));});item.append(children);}return item;
}
function renderTree(instance){var list=element("ol","dp-studio-tree-list");list.setAttribute("role","tree");list.setAttribute("aria-label","Studio component tree");list.append(treeItem(instance,instance.model,instance.model.key,1));instance.tree.replaceChildren(list);}
function canvasNode(instance,node,path){
	var article=element("article","dp-studio-canvas-node");article.dataset.dpStudioPath=path;article.dataset.dpStudioSelected=path===instance.selection?"true":"false";article.tabIndex=-1;
	var header=button("","dp-studio-canvas-node-header");header.dataset.dpStudioAction="select";header.dataset.dpStudioPath=path;header.dataset.dpStudioFocus="canvas:"+path;var strong=element("strong");strong.textContent=node.properties.label||title(node.key);var kind=element("span");kind.textContent=node.kind;header.append(strong,kind);article.append(header);
	if(node.properties.description){var help=element("p","dp-studio-canvas-help");help.textContent=node.properties.description;article.append(help);}
	if(node.kind==="field"){
		var control=node.properties.type==="textarea"?element("textarea","dp-studio-canvas-control"):element("input","dp-studio-canvas-control");control.disabled=true;control.setAttribute("aria-label",text(node.properties.label||node.key));if(control.tagName==="INPUT"){control.type=node.properties.type==="number"||node.properties.type==="integer"?"number":"text";control.placeholder=text(node.properties.placeholder||node.properties.label||node.key);}article.append(control);
	}
	if(node.children.length){var children=element("div","dp-studio-canvas-children");node.children.forEach(function(child){children.append(canvasNode(instance,child,path+"/"+child.key));});article.append(children);}return article;
}
function renderCanvas(instance){var sheet=element("div","dp-studio-canvas-sheet");sheet.dataset.dpStudioCanvasSheet="1";sheet.append(canvasNode(instance,instance.model,instance.model.key));instance.canvas.replaceChildren(sheet);}
function fieldShell(labelText,name){var field=element("div","dp-studio-field");field.dataset.dpStudioProperty=name;var label=element("label");label.textContent=labelText;field.append(label);return{field:field,label:label};}
function helper(property){var values=[property.type];if(property.required){values.push("required");}if(property.nullable){values.push("nullable");}if(property.bounds){if(property.bounds.min!=null){values.push("minimum "+property.bounds.min);}if(property.bounds.max!=null){values.push("maximum "+property.bounds.max);}}return values.join(", ");}
function renderInspector(instance){
	var location=selected(instance);var body=instance.properties;body.replaceChildren();if(!location){var missing=element("p","dp-studio-empty");missing.textContent="Select a component to edit its trusted properties.";body.append(missing);return;}
	var schema=schemaFor(instance,location.node.kind);if(!schema){var portable=element("p","dp-studio-secret-note");portable.textContent="This kind is portable-only. It can be inspected in blueprints but cannot be edited or materialized by the trusted Studio runtime.";body.append(portable);return;}
	var fields=element("div","dp-studio-properties-fields");var keyField=fieldShell("Component key","__key");var keyInput=element("input");keyInput.type="text";keyInput.name="studio_key";keyInput.value=location.node.key;keyInput.pattern="[a-z][a-z0-9_.\\-]{0,127}";keyInput.required=true;keyInput.autocomplete="off";keyInput.dataset.dpStudioKey="1";keyInput.dataset.dpStudioFocus="property:"+location.path+":key";keyField.label.htmlFor=instance.id+"-key";keyInput.id=instance.id+"-key";keyField.field.append(keyInput);fields.append(keyField.field);
	(schema.properties||[]).forEach(function(property,index){
		if(location.node.kind==="field"&&location.node.properties.type==="password"&&property.name==="default"){var secret=element("p","dp-studio-secret-note");secret.textContent="Credential defaults are never editable or serialized.";fields.append(secret);return;}
		var shell=fieldShell(title(property.name),property.name);var id=instance.id+"-property-"+index;shell.label.htmlFor=id;var current=location.node.properties[property.name];var control;
		if(property.type==="boolean"){
			var wrapper=element("label","dp-studio-check");shell.label.removeAttribute("for");shell.label.className="dp-studio-visually-hidden";control=element("input");control.type="checkbox";control.checked=current===true;wrapper.append(control,document.createTextNode(title(property.name)));shell.field.append(wrapper);var marker=element("input");marker.type="hidden";marker.name="studio_boolean_fields[]";marker.value=property.name;shell.field.append(marker);
		}else if(Array.isArray(property.enum)&&property.enum.length){
			control=element("select");var unset=element("option");unset.value="__dp_studio_unset__";unset.textContent=property.has_default?"Use schema default":"Not set";control.append(unset);property.enum.forEach(function(value){var option=element("option");option.value=stableStringify(value);option.textContent=text(value);option.selected=stableStringify(current)===stableStringify(value);control.append(option);});control.dataset.dpStudioEncoding="json";shell.field.append(control);
		}else if(["string_list","number_list","scalar_map"].indexOf(property.type)>=0){control=element("textarea");control.value=current===undefined?"":JSON.stringify(current,null,2);shell.field.append(control);}
		else{control=element("input");control.type=property.type==="integer"||property.type==="number"?"number":"text";if(property.type==="integer"){control.step="1";}if(property.type==="number"){control.step="any";}control.value=current===undefined?"":text(current);shell.field.append(control);}
		control.id=id;control.name="studio_properties["+property.name+"]";control.dataset.dpStudioPropertyInput=property.name;control.dataset.dpStudioPropertyType=property.type;control.dataset.dpStudioFocus="property:"+location.path+":"+property.name;var small=element("small");small.textContent=helper(property);shell.field.append(small);fields.append(shell.field);
	});body.append(fields);
}
function renderDiagnostics(instance,issues){
	instance.diagnostics.replaceChildren();if(!issues.length){var empty=element("li","dp-studio-diagnostic");empty.dataset.severity="info";var code=element("code");code.textContent="valid";var message=element("span");message.textContent="No trusted-schema diagnostics.";empty.append(code,message);instance.diagnostics.append(empty);return;}
	issues.forEach(function(issue){var item=element("li","dp-studio-diagnostic");item.dataset.severity=issue.severity||"error";var code=element("code");code.textContent=issue.path;var message=element("span");message.textContent=issue.message;item.append(code,message);instance.diagnostics.append(item);});
}
function updateState(instance,issues){
	var dirty=instance.initialDirty||stableStringify(instance.model)!==instance.initialSnapshot;instance.root.dataset.dpStudioDirty=dirty?"true":"false";instance.state.textContent=instance.conflicted?"Conflict":dirty?"Unsaved changes":"Saved revision "+instance.baseRevision;instance.hiddenDefinition.value=stableStringify(instance.model);instance.hiddenSelection.value=instance.selection;
	instance.root.querySelectorAll("[data-dp-studio-action='undo']").forEach(function(control){control.disabled=!instance.history.length;});instance.root.querySelectorAll("[data-dp-studio-action='redo']").forEach(function(control){control.disabled=!instance.future.length;});instance.root.querySelectorAll("[data-dp-studio-submit='preview']").forEach(function(control){control.disabled=dirty||instance.conflicted||issues.some(function(issue){return issue.severity!=="info";});});
}
function render(instance,focusToken){var focus=focusToken||activeToken(instance);renderPalette(instance);renderTree(instance);renderCanvas(instance);renderInspector(instance);var issues=validate(instance);renderDiagnostics(instance,issues);updateState(instance,issues);setFocus(instance,focus);}
function parseProperty(control){
	var type=control.dataset.dpStudioPropertyType;if(control.type==="checkbox"){return control.checked;}if(control.value==="__dp_studio_unset__"){return{unset:true};}
	if(control.dataset.dpStudioEncoding==="json"){return JSON.parse(control.value);}if(type==="integer"){if(!/^-?\d+$/.test(control.value)){throw new Error("invalid_integer");}return Number.parseInt(control.value,10);}if(type==="number"){var number=Number(control.value);if(!Number.isFinite(number)){throw new Error("invalid_number");}return number;}if(["string_list","number_list","scalar_map"].indexOf(type)>=0){return JSON.parse(control.value);}if(type==="scalar"&&/^(?:null|true|false|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)$/.test(control.value)){return JSON.parse(control.value);}return control.value;
}
function onPropertyChange(instance,control){
	var location=selected(instance);if(!location){return;}if(control.dataset.dpStudioKey){var key=control.value.trim();if(!/^[a-z][a-z0-9_.-]{0,127}$/.test(key)||location.parent&&location.parent.children.some(function(child){return child!==location.node&&child.key===key;})){control.setAttribute("aria-invalid","true");announce(instance,"Use a unique lowercase component key.");return;}control.removeAttribute("aria-invalid");var renamedPath=location.parentPath?location.parentPath+"/"+key:key;mutate(instance,"Component key updated.",function(){location.node.key=key;instance.selection=renamedPath;},"property:"+renamedPath+":key");return;}
	var name=control.dataset.dpStudioPropertyInput;if(!name){return;}try{var value=parseProperty(control);control.removeAttribute("aria-invalid");mutate(instance,"Property updated.",function(){if(value&&value.unset){delete location.node.properties[name];}else{location.node.properties[name]=value;}},"property:"+instance.selection+":"+name);}catch(error){control.setAttribute("aria-invalid","true");announce(instance,"Enter a valid value for "+title(name)+".");}
}
function clearDrop(instance){instance.root.querySelectorAll("[data-dp-studio-drop]").forEach(function(row){delete row.dataset.dpStudioDrop;});}
function syncMobilePanel(instance,panelName){
	var selectedPanel=panelName||instance.root.dataset.dpStudioMobilePanel||"canvas";instance.root.dataset.dpStudioMobilePanel=selectedPanel;var mobile=mobileMedia?mobileMedia.matches:false;
	instance.root.querySelectorAll("[data-dp-studio-panel]").forEach(function(panel){panel.hidden=mobile&&panel.dataset.dpStudioPanel!==selectedPanel;});instance.root.querySelectorAll("[data-dp-studio-mobile-panel]").forEach(function(control){control.setAttribute("aria-pressed",control.dataset.dpStudioMobilePanel===selectedPanel?"true":"false");});
}
function syncMountedPanels(){document.querySelectorAll("[data-dp-studio-editor]").forEach(function(root){var instance=instances.get(root);if(instance){syncMobilePanel(instance);}});}
function dropPlacement(row,clientY,instance,sourcePath){var rect=row.getBoundingClientRect();var ratio=rect.height?((clientY-rect.top)/rect.height):.5;var targetPath=row.dataset.dpStudioPath;var source=findNode(instance.model,sourcePath);var target=findNode(instance.model,targetPath);if(source&&target&&ratio>.32&&ratio<.68&&accepts(instance,target.node,source.node.kind)){return"inside";}return ratio<.5?"before":"after";}
function onClick(instance,event){
	var control=event.target.closest("[data-dp-studio-action],[data-dp-studio-add],[data-dp-studio-mobile-panel]");if(!control||control===instance.root||!instance.root.contains(control)){return;}
	if(control.dataset.dpStudioMobilePanel){event.preventDefault();syncMobilePanel(instance,control.dataset.dpStudioMobilePanel);var pane=instance.root.querySelector("[data-dp-studio-panel='"+control.dataset.dpStudioMobilePanel+"']");if(pane){pane.querySelector("h3,button,input")?.focus();}return;}
	if(control.dataset.dpStudioAdd){event.preventDefault();var location=selected(instance);var kind=control.dataset.dpStudioAdd;if(!location||!accepts(instance,location.node,kind)){announce(instance,"Select a compatible parent before adding "+title(kind)+".");return;}mutate(instance,title(kind)+" added.",function(){var key=uniqueKey(location.node,kind);location.node.children.push({kind:kind,key:key,properties:defaultProperties(instance,kind),children:[]});instance.selection=location.path+"/"+key;},"node:"+location.path);return;}
	var action=control.dataset.dpStudioAction;if(["select","move","remove","undo","redo"].indexOf(action)<0){return;}event.preventDefault();
	if(action==="select"){instance.selection=control.dataset.dpStudioPath;render(instance,"node:"+instance.selection);announce(instance,"Selected "+instance.selection.split("/").pop()+".");}
	else if(action==="move"){moveRelative(instance,control.dataset.dpStudioPath,control.dataset.dpStudioDirection);}
	else if(action==="remove"){var path=control.dataset.dpStudioPath;var parent=parentPath(path);mutate(instance,"Component removed.",function(){var location=findNode(instance.model,path);if(!location||!location.parent){throw new Error("remove_root");}location.parent.children.splice(location.index,1);instance.selection=parent;},"node:"+parent);}
	else if(action==="undo"){undo(instance);}else{redo(instance);}
}
function onKeydown(instance,event){
	if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==="z"){event.preventDefault();if(event.shiftKey){redo(instance);}else{undo(instance);}return;}
	if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==="y"){event.preventDefault();redo(instance);return;}
	var item=event.target.closest("[data-dp-studio-treeitem]");if(!item||!instance.root.contains(item)){return;}
	if(event.altKey&&["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].indexOf(event.key)>=0){event.preventDefault();var direction={ArrowUp:"up",ArrowDown:"down",ArrowLeft:instance.direction==="rtl"?"indent":"outdent",ArrowRight:instance.direction==="rtl"?"outdent":"indent"}[event.key];moveRelative(instance,item.dataset.dpStudioPath,direction);return;}
	var items=Array.from(instance.root.querySelectorAll("[data-dp-studio-treeitem]"));var index=items.indexOf(item);var focusItem=function(target){items.forEach(function(candidate){candidate.tabIndex=-1;});target.tabIndex=0;target.focus();};if(event.key==="ArrowDown"&&index<items.length-1){event.preventDefault();focusItem(items[index+1]);}else if(event.key==="ArrowUp"&&index>0){event.preventDefault();focusItem(items[index-1]);}else if(event.key==="Home"){event.preventDefault();focusItem(items[0]);}else if(event.key==="End"){event.preventDefault();focusItem(items[items.length-1]);}
}
function collaborationOnline(){return !("onLine" in navigator)||navigator.onLine!==false;}
function collaborationConfig(model){
	if(!model||model.type!=="panel_studio_collaboration_transport"||model.version!==1||typeof model.endpoint_url!=="string"||!model.endpoint_url.startsWith("/")||model.endpoint_url.startsWith("//")){throw new Error("studio_collaboration_transport_invalid");}
	var endpoint=new URL(model.endpoint_url,window.location.href);if(endpoint.origin!==window.location.origin||endpoint.username||endpoint.password){throw new Error("studio_collaboration_transport_origin");}
	var intent=model.intent,settings=model.settings;if(!intent||intent.type!=="panel_studio_collaboration_browser_intent"||intent.version!==1||typeof intent.token!=="string"||intent.token.length>4096||intent.token.split(".").length!==3){throw new Error("studio_collaboration_intent_invalid");}
	if(!settings||typeof settings!=="object"){throw new Error("studio_collaboration_settings_invalid");}
	["visible_poll_milliseconds","hidden_poll_milliseconds","maximum_backoff_milliseconds","request_timeout_milliseconds","presence_heartbeat_milliseconds","typing_idle_milliseconds"].forEach(function(name){if(!Number.isSafeInteger(settings[name])||settings[name]<1){throw new Error("studio_collaboration_settings_invalid");}});
	return{endpoint:model.endpoint_url,intent:clone(intent),settings:clone(settings),cursor:0,root:null,pollTimer:null,presenceTimer:null,typingTimer:null,typingThread:"",backoff:0,mutating:false,presenceBusy:false,released:false,controllers:new Set()};
}
function collaborationStatus(instance,message,state){
	var collaboration=instance.collaboration;if(!collaboration){return;}var root=collaboration.root&&collaboration.root.isConnected?collaboration.root:instance.root.querySelector("[data-dp-studio-collaboration]");if(root){collaboration.root=root;}var status=root&&root.querySelector("[data-dp-studio-collaboration-live-status]");instance.root.dataset.dpStudioCollaborationState=state;if(status&&status.textContent!==message){status.textContent=message;status.dataset.state=state;}
}
function collaborationCsrf(instance,data){
	var field=instance.form.elements.namedItem(instance.csrfField);if(!field||typeof field.value!=="string"||field.value.length<16){throw new Error("studio_collaboration_csrf_missing");}data.set(instance.csrfField,field.value);
}
function collaborationPayload(instance,action){
	var state=instance.collaboration;if(!state){throw new Error("studio_collaboration_unavailable");}var data=new FormData();data.set("studio_collaboration_transport_action",action);data.set("studio_collaboration_intent",state.intent.token);collaborationCsrf(instance,data);return data;
}
function collaborationMutationPayload(instance,submitter){
	var state=instance.collaboration;if(!state){throw new Error("studio_collaboration_unavailable");}var data=new FormData(instance.form);data.set("studio_collaboration_transport_action","mutate");data.set("studio_collaboration_intent",state.intent.token);data.set("studio_collaboration_operation",submitter.value);collaborationCsrf(instance,data);return data;
}
function collaborationError(code,message,retryable,status){
	var error=new Error(message||"Studio collaboration request failed.");error.code=code||"collaboration_failed";error.retryable=retryable===true;error.status=status||0;return error;
}
async function collaborationRequest(instance,action,data){
	var state=instance.collaboration;if(!state||typeof window.fetch!=="function"){throw collaborationError("transport_unavailable","Live collaboration transport is unavailable.",true);}
	if(!collaborationOnline()){throw collaborationError("offline","Live collaboration is paused while offline.",true);}
	var controller=typeof AbortController==="function"?new AbortController():null;if(controller){state.controllers.add(controller);}var timeout=window.setTimeout(function(){if(controller){controller.abort();}},state.settings.request_timeout_milliseconds);
	try{
		var response=await window.fetch(state.endpoint,{method:"POST",credentials:"same-origin",cache:"no-store",redirect:"error",headers:{"Accept":"application/json"},body:data,signal:controller?controller.signal:undefined});
		var payload;try{payload=await response.json();}catch(error){throw collaborationError("response_invalid","Live collaboration returned an invalid response.",true,response.status);}
		if(!response.ok||!payload||payload.ok!==true){throw collaborationError(payload&&payload.code,payload&&payload.message,payload&&payload.retryable,response.status);}
		if(payload.type!=="panel_studio_collaboration_transport_response"||payload.version!==1||payload.action!==action||!Number.isSafeInteger(payload.cursor)||payload.cursor<0||typeof payload.changed!=="boolean"||typeof payload.reset_required!=="boolean"||!Array.isArray(payload.changes)||!payload.intent||typeof payload.intent.token!=="string"){throw collaborationError("response_invalid","Live collaboration returned an unsupported response.",true,response.status);}
		return payload;
	}catch(error){
		if(error&&error.name==="AbortError"){throw collaborationError("request_timeout","Live collaboration timed out.",true);}
		if(error&&typeof error.code==="string"){throw error;}
		throw collaborationError("network_ambiguous","The live collaboration request could not be confirmed.",true);
	}finally{
		window.clearTimeout(timeout);if(controller){state.controllers.delete(controller);}
	}
}
function collaborationControlKey(control){if(control.id){return"id:"+control.id;}if(control.name){return"name:"+control.name;}return"";}
function captureCollaborationDrafts(root){
	var drafts=new Map();root.querySelectorAll("input,textarea,select").forEach(function(control){var type=(control.type||"").toLowerCase();if(["hidden","submit","button","file"].indexOf(type)>=0){return;}var key=collaborationControlKey(control);if(!key){return;}drafts.set(key,{name:control.name||"",value:control.value,checked:control.checked===true,selectionStart:typeof control.selectionStart==="number"?control.selectionStart:null,selectionEnd:typeof control.selectionEnd==="number"?control.selectionEnd:null});});return drafts;
}
function captureCollaborationFocus(root){
	var active=document.activeElement;if(!active||!root.contains(active)){return null;}return{id:active.id||"",name:active.name||"",value:active.value||"",selectionStart:typeof active.selectionStart==="number"?active.selectionStart:null,selectionEnd:typeof active.selectionEnd==="number"?active.selectionEnd:null};
}
function exactControl(root,focus){
	if(!focus){return null;}var controls=Array.from(root.querySelectorAll("button,input,select,textarea"));if(focus.id){var byId=controls.find(function(control){return control.id===focus.id;});if(byId){return byId;}}if(focus.name){return controls.find(function(control){return control.name===focus.name&&(!focus.value||control.value===focus.value);})||controls.find(function(control){return control.name===focus.name;})||null;}return null;
}
function restoreCollaborationDrafts(root,drafts,clearNames){
	root.querySelectorAll("input,textarea,select").forEach(function(control){var key=collaborationControlKey(control),draft=key?drafts.get(key):null;if(!draft||clearNames.has(draft.name)){return;}if(control.type==="checkbox"||control.type==="radio"){control.checked=draft.checked;}else{control.value=draft.value;}});
}
function collaborationFragment(fragment){
	if(typeof fragment!=="string"||fragment.length<32){throw collaborationError("fragment_invalid","Live collaboration returned an invalid workspace fragment.",true);}var parsed=new DOMParser().parseFromString(fragment,"text/html");var candidates=parsed.querySelectorAll("[data-dp-studio-collaboration]");if(candidates.length!==1||parsed.querySelector("script,style,iframe,object,embed,form,base,meta,link")){throw collaborationError("fragment_invalid","Live collaboration returned an unsafe workspace fragment.",true);}var replacement=candidates[0],nodes=[replacement].concat(Array.from(replacement.querySelectorAll("*")));nodes.forEach(function(node){Array.from(node.attributes||[]).forEach(function(attribute){var name=attribute.name.toLowerCase();if(name.startsWith("on")||["src","srcdoc","formaction"].indexOf(name)>=0){throw collaborationError("fragment_invalid","Live collaboration returned an unsafe workspace fragment.",true);}});});return replacement;
}
function replaceCollaboration(instance,fragment,clearNames){
	var state=instance.collaboration,current=state&&state.root&&state.root.isConnected?state.root:instance.root.querySelector("[data-dp-studio-collaboration]");if(!state||!current){throw collaborationError("fragment_target_missing","Live collaboration could not find its workspace.",true);}var drafts=captureCollaborationDrafts(current),focus=captureCollaborationFocus(current),replacement=collaborationFragment(fragment);if(replacement.id!==current.id){throw collaborationError("fragment_scope_invalid","Live collaboration returned a fragment for another editor.",true);}var imported=document.importNode(replacement,true);restoreCollaborationDrafts(imported,drafts,clearNames);current.replaceWith(imported);state.root=imported;var target=exactControl(imported,focus);if(target){try{target.focus({preventScroll:true});}catch(error){target.focus();}if(focus.selectionStart!==null&&typeof target.setSelectionRange==="function"){try{target.setSelectionRange(focus.selectionStart,focus.selectionEnd);}catch(error){}}}
}
function applyCollaboration(instance,response,clearNames){
	var state=instance.collaboration;if(!state){return false;}if(!response.intent||response.intent.type!=="panel_studio_collaboration_browser_intent"||response.intent.version!==1||typeof response.intent.token!=="string"||response.intent.token.length>4096){throw collaborationError("intent_refresh_invalid","Live collaboration returned an invalid refreshed intent.",true);}state.intent=clone(response.intent);if(response.cursor<state.cursor){return false;}state.cursor=response.cursor;if(response.fragment!==null){replaceCollaboration(instance,response.fragment,clearNames||new Set());}var root=state.root;if(root){root.dataset.dpStudioCollaborationCursor=String(state.cursor);}return true;
}
function clearCollaborationTimer(state,name){if(state[name]!==null){window.clearTimeout(state[name]);state[name]=null;}}
function scheduleCollaborationPoll(instance,delay){
	var state=instance.collaboration;if(!state||state.released){return;}clearCollaborationTimer(state,"pollTimer");state.pollTimer=window.setTimeout(function(){pollCollaboration(instance);},Math.max(0,delay));
}
async function pollCollaboration(instance){
	var state=instance.collaboration;if(!state||state.released){return;}state.pollTimer=null;var visible=document.visibilityState!=="hidden",base=visible?state.settings.visible_poll_milliseconds:state.settings.hidden_poll_milliseconds;
	if(!collaborationOnline()){collaborationStatus(instance,"Offline. Local drafts are preserved.","offline");scheduleCollaborationPoll(instance,Math.min(state.settings.maximum_backoff_milliseconds,state.settings.hidden_poll_milliseconds));return;}
	try{
		var data=collaborationPayload(instance,"delta");data.set("studio_collaboration_cursor",String(state.cursor));var response=await collaborationRequest(instance,"delta",data);var applied=applyCollaboration(instance,response,new Set());state.backoff=0;if(applied&&response.changed){collaborationStatus(instance,response.reset_required?"Workspace re-synchronized.":"Live changes applied.","live");}else{collaborationStatus(instance,"Live","live");}
	}catch(error){
		state.backoff=state.backoff?Math.min(state.settings.maximum_backoff_milliseconds,state.backoff*2):Math.min(state.settings.maximum_backoff_milliseconds,base);collaborationStatus(instance,"Reconnecting. Local drafts are preserved.","reconnecting");
	}
	scheduleCollaborationPoll(instance,state.backoff||base);
}
function schedulePresence(instance,delay){
	var state=instance.collaboration;if(!state||state.released){return;}clearCollaborationTimer(state,"presenceTimer");state.presenceTimer=window.setTimeout(function(){syncPresence(instance);},Math.max(0,delay));
}
async function syncPresence(instance){
	var state=instance.collaboration;if(!state||state.released){return;}state.presenceTimer=null;if(state.presenceBusy){schedulePresence(instance,state.settings.presence_heartbeat_milliseconds);return;}if(document.visibilityState==="hidden"||!collaborationOnline()){schedulePresence(instance,state.settings.presence_heartbeat_milliseconds);return;}state.presenceBusy=true;
	try{var response=await collaborationRequest(instance,"presence_sync",collaborationPayload(instance,"presence_sync"));applyCollaboration(instance,response,new Set());}
	catch(error){collaborationStatus(instance,"Presence reconnecting.","reconnecting");}
	finally{state.presenceBusy=false;schedulePresence(instance,state.settings.presence_heartbeat_milliseconds);}
}
async function sendTyping(instance,thread,typing){
	var state=instance.collaboration;if(!state||state.released||!collaborationOnline()||document.visibilityState==="hidden"){return;}try{var data=collaborationPayload(instance,"typing");data.set("studio_collaboration_thread_id",thread);data.set("studio_collaboration_typing",typing?"true":"false");var response=await collaborationRequest(instance,"typing",data);applyCollaboration(instance,response,new Set());}catch(error){}
}
function stopTyping(instance,thread,send){
	var state=instance.collaboration;if(!state){return;}clearCollaborationTimer(state,"typingTimer");var active=thread||state.typingThread;state.typingThread="";if(send&&active){sendTyping(instance,active,false);}
}
function onCollaborationInput(instance,event){
	var state=instance.collaboration;if(!state){return;}var control=event.target.closest("textarea[name^='studio_collaboration_comments[']");if(!control||!instance.root.contains(control)){return;}var match=/^studio_collaboration_comments\[([^\]]+)\]$/.exec(control.name);if(!match){return;}var thread=match[1];if(state.typingThread&&state.typingThread!==thread){sendTyping(instance,state.typingThread,false);}if(state.typingThread!==thread){state.typingThread=thread;sendTyping(instance,thread,true);}clearCollaborationTimer(state,"typingTimer");state.typingTimer=window.setTimeout(function(){stopTyping(instance,thread,true);},state.settings.typing_idle_milliseconds);
}
function releasePresence(instance){
	var state=instance.collaboration;if(!state||state.released){return;}state.released=true;clearCollaborationTimer(state,"pollTimer");clearCollaborationTimer(state,"presenceTimer");clearCollaborationTimer(state,"typingTimer");try{var data=collaborationPayload(instance,"presence_release");if(typeof navigator.sendBeacon==="function"&&navigator.sendBeacon(state.endpoint,data)){return;}if(typeof window.fetch==="function"){window.fetch(state.endpoint,{method:"POST",credentials:"same-origin",cache:"no-store",redirect:"error",body:data,keepalive:true}).catch(function(){});}}catch(error){}
}
function collaborationClearNames(operation){
	var clear=new Set();if(operation==="create_thread"){clear.add("studio_collaboration_title");}else if(operation==="assign"||operation==="unassign"){clear.add("studio_collaboration_assignee");}else if(operation.startsWith("comment:")){clear.add("studio_collaboration_comments["+operation.slice(8)+"]");}return clear;
}
async function submitCollaboration(instance,submitter){
	var state=instance.collaboration;if(!state||state.mutating){announce(instance,state?"A review change is already being saved.":"Live collaboration is unavailable.");return;}state.mutating=true;var section=state.root;if(section){section.setAttribute("aria-busy","true");}submitter.disabled=true;collaborationStatus(instance,"Saving review change...","syncing");var operation=submitter.value;if(operation.startsWith("comment:")){stopTyping(instance,operation.slice(8),true);}
	try{var response=await collaborationRequest(instance,"mutate",collaborationMutationPayload(instance,submitter));applyCollaboration(instance,response,collaborationClearNames(operation));collaborationStatus(instance,"Review workspace synchronized.","live");announce(instance,"Review workspace updated.");}
	catch(error){collaborationStatus(instance,"Change not confirmed. Reconciling workspace.","reconnecting");announce(instance,error&&error.message?error.message:"The review change could not be confirmed.");scheduleCollaborationPoll(instance,0);}
	finally{state.mutating=false;section=state.root;if(section){section.removeAttribute("aria-busy");}if(submitter.isConnected){submitter.disabled=false;}}
}
function setupCollaboration(instance,model,summary){
	if(!model){return false;}try{var state=collaborationConfig(model);state.cursor=summary&&Number.isSafeInteger(summary.cursor)?summary.cursor:Number(instance.root.querySelector("[data-dp-studio-collaboration]")?.dataset.dpStudioCollaborationCursor||0);state.root=instance.root.querySelector("[data-dp-studio-collaboration]");if(!state.root){throw new Error("studio_collaboration_workspace_missing");}instance.collaboration=state;instance.cleanups.push(function(){clearCollaborationTimer(state,"pollTimer");clearCollaborationTimer(state,"presenceTimer");clearCollaborationTimer(state,"typingTimer");state.controllers.forEach(function(controller){controller.abort();});state.controllers.clear();});listen(instance,document,"visibilitychange",function(){if(document.visibilityState!=="hidden"){scheduleCollaborationPoll(instance,0);schedulePresence(instance,0);}});listen(instance,window,"online",function(){collaborationStatus(instance,"Reconnecting...","reconnecting");scheduleCollaborationPoll(instance,0);schedulePresence(instance,0);});listen(instance,window,"offline",function(){collaborationStatus(instance,"Offline. Local drafts are preserved.","offline");});collaborationStatus(instance,collaborationOnline()?"Connecting...":"Offline. Local drafts are preserved.",collaborationOnline()?"syncing":"offline");scheduleCollaborationPoll(instance,0);schedulePresence(instance,0);return true;}catch(error){instance.root.dataset.dpStudioCollaborationState="unavailable";var status=instance.root.querySelector("[data-dp-studio-collaboration-live-status]");if(status){status.textContent="Live transport unavailable";status.dataset.state="error";}return false;}
}
function onSubmit(instance,event){
	instance.hiddenDefinition.value=stableStringify(instance.model);instance.hiddenSelection.value=instance.selection;var submitter=event.submitter;if(submitter&&submitter.name==="studio_collaboration_operation"&&instance.collaboration){event.preventDefault();submitCollaboration(instance,submitter);return;}var action=submitter&&submitter.value;if(action==="preview"&&(instance.root.dataset.dpStudioDirty==="true"||instance.conflicted)){event.preventDefault();announce(instance,"Save and resolve conflicts before opening a signed preview.");return;}if(action==="save"&&validate(instance).some(function(issue){return issue.severity!=="info";})){event.preventDefault();announce(instance,"Resolve trusted-schema diagnostics before saving.");}
}
function create(root){
	if(instances.has(root)){return instances.get(root);}var script=root.querySelector("[data-dp-studio-model]");if(!script){throw new Error("studio_editor_model_missing");}var payload=JSON.parse(script.textContent);if(!payload||payload.contract_version!==3||!payload.definition||!payload.schema_registry){throw new Error("studio_editor_model_invalid");}
	var instance={root:root,id:root.id,model:clone(payload.definition),registry:payload.schema_registry,selection:payload.selected_path,baseRevision:payload.base_revision,initialDirty:payload.dirty===true,initialSnapshot:stableStringify(payload.definition),serverDiagnostics:Array.isArray(payload.diagnostics)?payload.diagnostics:[],conflicted:payload.conflicted===true,direction:getComputedStyle(root).direction,history:[],future:[],cleanups:[],tree:root.querySelector("[data-dp-studio-tree]"),canvas:root.querySelector("[data-dp-studio-canvas]"),properties:root.querySelector("[data-dp-studio-properties]"),diagnostics:root.querySelector("[data-dp-studio-diagnostics-list]"),status:root.querySelector("[data-dp-studio-status]"),state:root.querySelector("[data-dp-studio-state]"),form:root.querySelector("[data-dp-studio-command-form]"),hiddenDefinition:root.querySelector("[name='studio_definition_json']"),hiddenSelection:root.querySelector("[name='studio_selected_path']"),csrfField:payload.csrf_field,collaboration:null,dragSource:"",pointerSource:""};
	if(!instance.tree||!instance.canvas||!instance.properties||!instance.diagnostics||!instance.status||!instance.state||!instance.form||!instance.hiddenDefinition||!instance.hiddenSelection){throw new Error("studio_editor_surface_incomplete");}
	root.dataset.dpStudioEnhanced="true";listen(instance,root,"click",function(event){onClick(instance,event);});listen(instance,root,"keydown",function(event){onKeydown(instance,event);});listen(instance,root,"change",function(event){var control=event.target.closest("[data-dp-studio-property-input],[data-dp-studio-key]");if(control){onPropertyChange(instance,control);}var zoom=event.target.closest("[data-dp-studio-zoom-control]");if(zoom){root.dataset.dpStudioZoom=zoom.value;announce(instance,"Canvas zoom set to "+zoom.options[zoom.selectedIndex].text+".");}var reflow=event.target.closest("[data-dp-studio-reflow-control]");if(reflow){root.dataset.dpStudioReflow=reflow.value;announce(instance,"Canvas reflow set to "+reflow.options[reflow.selectedIndex].text+".");}});listen(instance,instance.form,"submit",function(event){onSubmit(instance,event);});
	listen(instance,root,"input",function(event){onCollaborationInput(instance,event);});
	listen(instance,root,"dragstart",function(event){var item=event.target.closest("[data-dp-studio-treeitem]");if(!item){return;}instance.dragSource=item.dataset.dpStudioPath;event.dataTransfer.effectAllowed="move";event.dataTransfer.setData("text/plain",instance.dragSource);});listen(instance,root,"dragover",function(event){var row=event.target.closest(".dp-studio-tree-row");if(!row||!instance.dragSource){return;}event.preventDefault();clearDrop(instance);row.dataset.dpStudioDrop=dropPlacement(row,event.clientY,instance,instance.dragSource);event.dataTransfer.dropEffect="move";});listen(instance,root,"drop",function(event){var row=event.target.closest(".dp-studio-tree-row");if(!row||!instance.dragSource){return;}event.preventDefault();var placement=row.dataset.dpStudioDrop||"after";var target=row.dataset.dpStudioPath;clearDrop(instance);movePlacement(instance,instance.dragSource,target,placement);instance.dragSource="";});listen(instance,root,"dragend",function(){instance.dragSource="";clearDrop(instance);});
	listen(instance,root,"pointerdown",function(event){if(event.pointerType==="mouse"){return;}var item=event.target.closest("[data-dp-studio-treeitem]");instance.pointerSource=item?item.dataset.dpStudioPath:"";});listen(instance,root,"pointerup",function(event){if(!instance.pointerSource){return;}var row=document.elementFromPoint(event.clientX,event.clientY)?.closest(".dp-studio-tree-row");if(row&&root.contains(row)){movePlacement(instance,instance.pointerSource,row.dataset.dpStudioPath,dropPlacement(row,event.clientY,instance,instance.pointerSource));}instance.pointerSource="";clearDrop(instance);});listen(instance,root,"pointercancel",function(){instance.pointerSource="";clearDrop(instance);});
	instances.set(root,instance);mounted.add(root);render(instance,"node:"+instance.selection);syncMobilePanel(instance);setupCollaboration(instance,payload.collaboration_transport,payload.collaboration);return instance;
}
function boot(scope){var root=scope&&scope.querySelectorAll?scope:document;var candidates=[];if(root.matches&&root.matches("[data-dp-studio-editor]")){candidates.push(root);}root.querySelectorAll("[data-dp-studio-editor]").forEach(function(candidate){candidates.push(candidate);});candidates.forEach(function(candidate){if(!instances.has(candidate)){create(candidate);}});return candidates.length;}
function start(){boot(document);if(mobileMedia&&!mobileListener){mobileListener=syncMountedPanels;mobileMedia.addEventListener("change",mobileListener);}if(typeof MutationObserver==="function"&&document.documentElement&&!observer){observer=new MutationObserver(function(records){records.forEach(function(record){record.removedNodes.forEach(scheduleRemoval);record.addedNodes.forEach(function(node){if(node.nodeType===1){boot(node);}});});});observer.observe(document.documentElement,{childList:true,subtree:true});}}
function stop(){if(observer){observer.disconnect();observer=null;}if(mobileMedia&&mobileListener){mobileMedia.removeEventListener("change",mobileListener);mobileListener=null;}Array.from(mounted).forEach(dispose);}

window.DataphyrePanelStudioEditor={version:2,start:start,boot:boot,create:create,dispose:dispose,count:function(){return mounted.size;},validate:function(root){var instance=instances.get(root)||create(root);return validate(instance);},stableStringify:stableStringify,findNode:findNode,movePlacement:function(root,source,target,placement){var instance=instances.get(root)||create(root);return movePlacement(instance,source,target,placement);},syncCollaboration:function(root){var instance=instances.get(root)||create(root);if(instance.collaboration){scheduleCollaborationPoll(instance,0);return true;}return false;},stop:stop};
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start,{once:true});}else{start();}
window.addEventListener("pagehide",stop,{once:true});
})();
