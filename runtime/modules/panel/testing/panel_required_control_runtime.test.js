"use strict";

const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");
const vm=require("node:vm");

const panelRoot=path.resolve(__dirname,"..");
const scriptFile=path.join(panelRoot,"Framework","Rendering","Assets","PanelRendererAssetsScripts.php");
const php=fs.readFileSync(scriptFile,"utf8");
const nowdoc=php.match(/return <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(nowdoc,"Panel runtime nowdoc is extractable");
const source=nowdoc[1];
const helper=source.match(/function dpPanelSetFieldControlRequired\(input,required\)\{[\s\S]*?\r?\n\}/);
assert.ok(helper,"submitted-control required helper is present");

const sandbox={};
vm.createContext(sandbox);
vm.runInContext(helper[0]+"\nthis.applyRequired=dpPanelSetFieldControlRequired;",sandbox,{filename:"panel-required-control-runtime.js"});

function control({name="",type="text",searchable=false}={}){
	const attributes={};
	return {
		name,type,required:null,attributes,
		hasAttribute(attribute){return attribute==="data-dp-panel-searchable-select-input"&&searchable;},
		setAttribute(attribute,value){attributes[attribute]=String(value);},
	};
}

let assertions=0;
function same(actual,expected,message){assert.equal(actual,expected,message);assertions++;}

const submittedSelect=control({name:"selected_item_keys[]",type:"select-multiple"});
sandbox.applyRequired(submittedSelect,true);
same(submittedSelect.required,true,"the actual named multi-select remains natively required");
same(submittedSelect.attributes["aria-required"],"true","the actual select exposes required semantics");

const searchInput=control({type:"search",searchable:true});
sandbox.applyRequired(searchInput,true);
same(searchInput.required,false,"the unnamed searchable-select input never blocks submission");
same(searchInput.attributes["aria-required"],"false","the search helper is not announced as a required value");

const accidentallyNamedSearch=control({name:"selected_item_search",type:"search",searchable:true});
sandbox.applyRequired(accidentallyNamedSearch,true);
same(accidentallyNamedSearch.required,false,"the explicit searchable-select marker wins even if an integration adds a name");

const unnamedAuxiliary=control({type:"text"});
sandbox.applyRequired(unnamedAuxiliary,true);
same(unnamedAuxiliary.required,false,"other unnamed enhancement controls remain outside native validation");

const hiddenSubmitted=control({name:"change_uuid",type:"hidden"});
sandbox.applyRequired(hiddenSubmitted,true);
same(hiddenSubmitted.required,false,"hidden submitted metadata is never natively required");

sandbox.applyRequired(submittedSelect,false);
same(submittedSelect.required,false,"dependency refresh clears native required state when the condition no longer matches");
same(submittedSelect.attributes["aria-required"],"false","dependency refresh clears required accessibility state");

same((source.match(/dpPanelSetFieldControlRequired\(input,visible&&required\);/g)||[]).length,2,"both dependency refresh paths share the submitted-control helper");
same(source.includes("input.required=visible&&required"),false,"no dependency path can directly require every enhancement input");

process.stdout.write(JSON.stringify({ok:true,assertions})+"\n");
