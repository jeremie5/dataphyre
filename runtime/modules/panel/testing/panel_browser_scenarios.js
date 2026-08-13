'use strict';

/**
 * Contract-aware browser scenario catalog shared by Panel regression runners.
 *
 * Scenario bodies stay readable in their owning runner; identity, tags,
 * watched sources, stable IDs, and conservative changed-path selection live in
 * this catalog so browser partials can explain why they are safe.
 */

const RENDERING='runtime/modules/panel/Framework/Rendering/**';
const ASSETS='runtime/modules/panel/Framework/Rendering/Assets/**';
const MODALS='runtime/modules/panel/Framework/Rendering/Assets/PanelRendererAssetsFeatureCss.php';
const AJAX='runtime/modules/panel/Framework/Rendering/Assets/PanelRendererAssetsAjaxRuntimeScripts.php';
const TABLES='runtime/modules/panel/Framework/Rendering/PanelRendererTables.php';
const HTTP='runtime/modules/panel/Framework/Http/**';
const WIDGETS='runtime/modules/panel/Framework/Widgets/**';
const WIDGET_RENDERING='runtime/modules/panel/Framework/Rendering/Assets/PanelRendererAssetsWidgetRuntime*';
const DATA_SURFACES='runtime/modules/panel/Framework/Data/Surface/**';
const DATA_SURFACE_RENDERING='runtime/modules/panel/Framework/Rendering/Panel*DataSurface*';
const DATA_SURFACE_ASSETS='runtime/modules/panel/Framework/Rendering/Assets/PanelRendererAssetsDataSurface*';
const CAPABILITIES='runtime/modules/panel/Framework/Assets/PanelAssetCapabilityManifest.php';
const STUDIO='runtime/modules/panel/Framework/Studio/**';
const STUDIO_BROWSER='runtime/modules/panel/testing/panel_studio_editor*';
const EDITORS='runtime/modules/panel/Framework/Editors/**';
const OPERATIONS_OS='runtime/modules/panel/Framework/OperationsOs/**';
const PLATFORM='runtime/modules/panel/Framework/Platform/**';
const PLATFORM_TEMPLATES='runtime/modules/panel/Framework/Templates/PanelPlatform*';
const BROWSER_SHOWROOM='runtime/modules/panel/testing/fixtures/panel_browser_showroom.php';

function scenario(name,contract,tags,watches=[RENDERING]){
	return {name,contract,tags:[...new Set(['panel','browser',...tags])],watches:[...new Set(watches)]};
}

const interactionScenarios=[
	scenario('mobile drawer opens, traps shell state, and closes with Escape','panel.navigation.mobile-drawer',['navigation','mobile','keyboard'],[ASSETS]),
	scenario('fixed-fit brick collections collapse to one safe mobile track','panel.layout.fixed-fit-bricks',['layout','mobile','responsive']),
	scenario('row masonry fills incomplete rows without reordering or overflow','panel.layout.row-masonry',['layout','responsive','ordering','item-presentation'],[RENDERING,'runtime/modules/panel/Framework/Support/PanelCollection*']),
	scenario('flat minima widget seams are owned by the collection boundary','panel.layout.collection-boundary',['layout','widgets']),
	scenario('masonry toolbar stretches mixed links and form-wrapped controls','panel.layout.masonry-toolbar',['layout','toolbar','forms']),
	scenario('edit modal aligns nested sections and contains required dirty markers','panel.modal.edit-form',['modal','forms','dirty-state'],[MODALS,AJAX]),
	scenario('adaptive form spans and semantic switches prevent intrinsic control clipping','panel.form.adaptive-controls',['forms','layout','responsive','controls','accessibility'],[RENDERING,ASSETS]),
	scenario('form and infolist grids retain real tracks at intermediate container widths','panel.layout.form-container-tracks',['forms','infolist','layout','responsive','container'],[RENDERING,ASSETS,BROWSER_SHOWROOM]),
	scenario('dark native selects keep a dark popup color contract','panel.controls.native-select-dark',['controls','theme','dark']),
	scenario('system-dark native controls preserve popup and selected-option contrast','panel.controls.system-dark',['controls','theme','contrast']),
	scenario('blocking modal and command surfaces dismiss transient chrome','panel.modal.blocking-surface',['modal','commandbar','layering'],[MODALS,ASSETS]),
	scenario('unsaved confirmation traps focus and restores its opener','panel.modal.unsaved-focus',['modal','focus','dirty-state'],[MODALS]),
	scenario('commandbar controls share normalized geometry','panel.commandbar.geometry',['commandbar','layout']),
	scenario('commandbar Ops and Columns disclosures hit-test above sibling controls','panel.commandbar.hit-testing',['commandbar','layering','interaction'],[ASSETS]),
	scenario('ajax navigation activates capability-scoped assets before revealing a surface','panel.assets.ajax-capability-handoff',['assets','ajax','navigation','capabilities','lifecycle'],[AJAX,CAPABILITIES,ASSETS]),
	scenario('density views and groups wrap without scroll containers','panel.table.density-groups',['table','layout','responsive'],[TABLES,ASSETS]),
	scenario('relation search joins controls and empty state spans the table','panel.relation.search-empty',['relation','table','search'],[TABLES,RENDERING]),
	scenario('dark file checkbox and select controls remain readable','panel.controls.dark-native',['controls','dark','accessibility']),
	scenario('glass separators metrics and board menus stay normalized','panel.theme.glass-structure',['theme','glass','board']),
	scenario('board action labels inherit readable control colors across shipped themes','panel.board.action-contrast',['board','theme','contrast']),
	scenario('light glass row and nested menu actions keep WCAG text contrast','panel.theme.glass-action-contrast',['theme','contrast','accessibility']),
	scenario('in-place actions preserve the viewport around their result','panel.action.viewport-preservation',['actions','ajax','scroll'],[AJAX,RENDERING]),
	scenario('runtime controllers replace global listeners without duplication','panel.runtime.controller-ownership',['runtime','listeners','hot-reload'],[ASSETS]),
	scenario('signed Data Surface windows page exactly once, preserve roving focus, and release removed roots','panel.data-surface.lifecycle',['data-surface','signed-window','pagination','focus','lifecycle'],[DATA_SURFACES,DATA_SURFACE_RENDERING,DATA_SURFACE_ASSETS,CAPABILITIES,ASSETS]),
	scenario('Studio Editor keyboard edits preserve focus, submit same-origin commands, and dispose remounted roots','panel.studio-editor.lifecycle',['studio-editor','keyboard','undo','focus','requests','lifecycle','accessibility','reduced-motion','collaboration','csrf','responsive'],[STUDIO,STUDIO_BROWSER,BROWSER_SHOWROOM]),
	scenario('Studio live collaboration converges across tabs without losing drafts or replaying mutations','panel.studio-collaboration.live-convergence',['studio-editor','collaboration','live','multi-tab','drafts','focus','requests','csrf','presence'],[STUDIO,STUDIO_BROWSER,BROWSER_SHOWROOM]),
	scenario('Studio visual runtime keeps actual Panel surfaces sandboxed themed accessible and overflow-free','panel.studio-visual-runtime.browser',['studio-editor','visual-runtime','sandbox','theme','responsive','accessibility','security'],[STUDIO,STUDIO_BROWSER,BROWSER_SHOWROOM]),
	scenario('Operations OS console stays redacted accessible touch-safe and overflow-free','panel.operations-os.console.browser',['operations-os','console','responsive','accessibility','security','theme','touch'],[OPERATIONS_OS,PLATFORM,PLATFORM_TEMPLATES,BROWSER_SHOWROOM]),
	scenario('browser editor adapters mount asynchronously, synchronize canonical values, fall back, and release detached roots','panel.editor-adapter.lifecycle',['editor','adapter','commands','syntax','fallback','lifecycle','accessibility'],[EDITORS,ASSETS,BROWSER_SHOWROOM]),
	scenario('editor asset providers browse, upload, insert, delete, verify requests, and release accessible pickers','panel.editor-assets.lifecycle',['editor','assets','upload','media','requests','responsive','focus','lifecycle','accessibility'],[EDITORS,ASSETS,BROWSER_SHOWROOM]),
	scenario('interactive widget actions refresh once, restore focus, and unmount cleanly','panel.widget.lifecycle',['widget-runtime','actions','refresh','focus','hot-reload','unmount'],[WIDGETS,WIDGET_RENDERING,ASSETS]),
	scenario('modal runtime hot reload preserves nested history and releases orphaned busy UI','panel.modal.hot-reload',['modal','runtime','history','busy-state'],[MODALS,AJAX]),
	scenario('filter modal has dialog semantics and closes with Escape','panel.modal.filter-dialog',['modal','filters','keyboard','accessibility'],[MODALS,TABLES]),
	scenario('create slide-over stays inside the viewport and restores focus','panel.modal.create-slide-over',['modal','create','focus','viewport'],[MODALS]),
	scenario('selected order edit transition and CSV export keep their distinct request contracts','panel.bulk.transport-contracts',['bulk','route','ajax','native-navigation'],[TABLES,MODALS,AJAX,HTTP]),
	scenario('nested modal exits restore live parent value, listener, focus, and scroll','panel.modal.nested-exit',['modal','nested','focus','scroll'],[MODALS,AJAX]),
	scenario('browser Back traverses daughter modals before leaving the page','panel.modal.browser-history',['modal','nested','history'],[MODALS]),
	scenario('nested modal stack honors explicit replace and clear plus stay, back, and close exits','panel.modal.navigation-strategies',['modal','nested','navigation'],[MODALS,AJAX]),
	scenario('422 and 500 modal failures never report success or navigate','panel.modal.failure-contracts',['modal','failure','http'],[MODALS,AJAX,HTTP]),
	scenario('successful stay-in-modal save advances the dirty baseline','panel.modal.stay-baseline',['modal','save','dirty-state'],[MODALS,AJAX]),
	scenario('discard confirmation never carries between parent and daughter modals','panel.modal.discard-scope',['modal','nested','dirty-state'],[MODALS]),
	scenario('multi-select, dynamic-control, and repeater edits all require discard confirmation','panel.modal.dynamic-dirty-controls',['modal','forms','dirty-state'],[MODALS,ASSETS]),
	scenario('workspace shortcuts are gated while a modal owns focus','panel.modal.shortcut-ownership',['modal','keyboard','focus'],[MODALS,ASSETS]),
	scenario('stale out-of-order modal fetch cannot replace the newer flow','panel.modal.request-order',['modal','ajax','race'],[MODALS,AJAX]),
	scenario('Save current view returns to its live parent modal on cancel, Back, and save','panel.modal.saved-view-parent',['modal','views','nested'],[MODALS,TABLES]),
	scenario('keyboard traversal exposes visible focus treatment','panel.accessibility.keyboard-focus',['accessibility','keyboard','focus'],[ASSETS]),
	scenario('reduced-motion preference collapses every Panel animation and transition duration','panel.accessibility.reduced-motion',['accessibility','motion','preference'],[ASSETS]),
	scenario('required form validation exposes invalid controls without navigation','panel.validation.required-controls',['validation','forms','navigation'],[ASSETS,HTTP]),
	scenario('record actions keep a bounded primary set and keyboard-operable overflow','panel.action.record-overflow',['actions','responsive','keyboard','accessibility','container'],[TABLES,ASSETS,'runtime/modules/panel/Framework/Resources/Action.php','runtime/modules/panel/Framework/Resources/ActionGroup.php','runtime/modules/panel/Framework/Resources/Resource.php']),
	scenario('copyable show entries reserve a collision-free logical action column','panel.infolist.copy-action',['infolist','copy','responsive','accessibility','rtl'],[RENDERING,ASSETS]),
	scenario('mobile relation and order actions stay bounded and touch-safe','panel.mobile.relation-actions',['mobile','relation','table','responsive','actions','selection','geometry','rtl','touch','accessibility'],[TABLES,ASSETS]),
	scenario('feature showcase renders and remains bounded at the page tail','panel.showcase.page-bounds',['showcase','layout','mobile']),
];

class PanelBrowserScenarioRegistry {
	constructor(entries){
		this.entries=[];
		this.byName=new Map();
		for(const source of entries){
			const name=String(source.name||'').trim();
			if(!name){throw new Error('Browser scenario names must not be empty.');}
			if(this.byName.has(name)){throw new Error('Duplicate browser scenario: '+name);}
			const entry=Object.freeze({
				id:'panel.browser.'+PanelBrowserScenarioRegistry.slug(source.contract||name),
				name,
				contract:String(source.contract||name),
				tags:Object.freeze([...(source.tags||[])]),
				watches:Object.freeze([...(source.watches||[])]),
			});
			this.entries.push(entry);
			this.byName.set(name,entry);
		}
		Object.freeze(this.entries);
	}

	static slug(value){
		return String(value||'scenario').toLowerCase().replace(/[^a-z0-9]+/g,'.').replace(/^\.+|\.+$/g,'')||'scenario';
	}

	entry(name){
		const entry=this.byName.get(String(name));
		if(!entry){throw new Error('Unregistered Panel browser scenario: '+name);}
		return entry;
	}

	list(){
		return this.entries.map(entry=>({...entry,tags:[...entry.tags],watches:[...entry.watches]}));
	}

	select(filters={}){
		const names=(filters.names||[]).map(String).filter(Boolean);
		const tags=(filters.tags||[]).map(tag=>String(tag).toLowerCase()).filter(Boolean);
		const changed=(filters.changedPaths||[]).map(PanelBrowserScenarioRegistry.normalizePath).filter(Boolean);
		const changedMatches=new Map(changed.map(path=>[path,[]]));
		for(const entry of this.entries){
			for(const path of changed){
				if(entry.watches.some(pattern=>PanelBrowserScenarioRegistry.pathMatches(pattern,path))){changedMatches.get(path).push(entry.name);}
			}
		}
		const unknownChanged=[...changedMatches].filter(([,matches])=>matches.length===0).map(([path])=>path);
		const conservativeFallback=unknownChanged.length>0;
		const selected=[];
		for(const entry of this.entries){
			const reasons=[];
			if(names.length&&names.some(selector=>PanelBrowserScenarioRegistry.textMatches(entry.name+' '+entry.contract,selector))){reasons.push('scenario selector');}
			if(tags.length&&tags.every(tag=>entry.tags.map(value=>value.toLowerCase()).includes(tag))){reasons.push('tag selector');}
			const paths=changed.filter(path=>entry.watches.some(pattern=>PanelBrowserScenarioRegistry.pathMatches(pattern,path)));
			if(paths.length){reasons.push(...paths.map(path=>'changed '+path));}
			const hasExplicit=names.length>0||tags.length>0||changed.length>0;
			const namePass=names.length===0||names.some(selector=>PanelBrowserScenarioRegistry.textMatches(entry.name+' '+entry.contract,selector));
			const tagPass=tags.length===0||tags.every(tag=>entry.tags.map(value=>value.toLowerCase()).includes(tag));
			const changedPass=changed.length===0||paths.length>0||conservativeFallback;
			if(namePass&&tagPass&&changedPass){
				if(!hasExplicit){reasons.push('full interaction suite');}
				if(conservativeFallback){reasons.push('conservative fallback for '+unknownChanged.join(', '));}
				selected.push({...entry,reasons});
			}
		}
		return {selected,unknownChanged,conservativeFallback};
	}

	static textMatches(text,selector){
		if(selector.startsWith('/')&&selector.lastIndexOf('/')>0){
			const end=selector.lastIndexOf('/');
			try{return new RegExp(selector.slice(1,end),selector.slice(end+1)||'i').test(text);}catch{return false;}
		}
		return text.toLowerCase().includes(selector.toLowerCase());
	}

	static normalizePath(value){return String(value||'').trim().replace(/\\/g,'/').replace(/^\.\//,'');}

	static pathMatches(pattern,path){
		pattern=PanelBrowserScenarioRegistry.normalizePath(pattern);
		path=PanelBrowserScenarioRegistry.normalizePath(path);
		const escaped=pattern.replace(/[.+?^${}()|[\]\\]/g,'\\$&').replace(/\*\*/g,'\u0000').replace(/\*/g,'[^/]*').replace(/\u0000/g,'.*');
		return new RegExp('^'+escaped+'$','i').test(path);
	}
}

const interactionRegistry=new PanelBrowserScenarioRegistry(interactionScenarios);

module.exports={PanelBrowserScenarioRegistry,interactionRegistry,interactionScenarios};
