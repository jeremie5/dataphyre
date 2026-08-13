<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the accessibility policy and field boot runtime.
 */
trait PanelRendererAssetsAccessibilityRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function accessibilityRuntimeScript(): string {
		return <<<'JS'
function dpPanelFieldHasAccessibilityPolicy(field){
	return !!(field&&field.dataset&&field.dataset.dpPanelA11yDisabled!=="1"&&(field.dataset.dpPanelA11yPolicy==="1"||field.closest("[data-dp-panel-a11y-default='1']")));
}
var dpPanelA11yResizeObserver=null;
var dpPanelA11yResizeFrame=0;
var dpPanelA11yInputRefreshTimer=0;
var dpPanelA11yRefreshOptions=null;
var dpPanelA11yDeferredFullRefresh=false;
var dpPanelFieldMutationObserver=null;
var dpPanelFieldMutationFrame=0;
var dpPanelFieldMutationRoots=[];
/**
 * Detects whether the user is actively editing a form control.
 *
 * @returns {boolean} Whether focus is inside an editable control.
 */
function dpPanelA11yUserIsEditing(){
	var active=document.activeElement;
	return !!(active&&active.closest&&active.closest("input,select,textarea,[contenteditable='true']"));
}
/**
 * Schedules an accessibility policy refresh on the next animation frame.
 *
 * Active editing preserves adaptive layout to avoid moving focused controls during
 * typing, while a deferred full refresh is recorded for a later stable pass.
 *
 * @param {ParentNode|null} root Refresh scope.
 * @param {Object} options Refresh options.
 * @returns {void}
 */
function dpPanelScheduleAccessibilityPolicyRefresh(root,options){
	options=options||{};
	if(dpPanelA11yUserIsEditing()&&!options.preserveAdaptive){
		options=Object.assign({},options,{preserveAdaptive:true});
		dpPanelA11yDeferredFullRefresh=true;
	}
	if(!dpPanelA11yRefreshOptions){
		dpPanelA11yRefreshOptions={preserveAdaptive:!!options.preserveAdaptive};
	}
	else if(!options.preserveAdaptive&&!dpPanelA11yUserIsEditing()){
		dpPanelA11yRefreshOptions.preserveAdaptive=false;
	}
	if(dpPanelA11yResizeFrame){return;}
	dpPanelA11yResizeFrame=requestAnimationFrame(function(){
		var refreshOptions=dpPanelA11yRefreshOptions||{};
		dpPanelA11yRefreshOptions=null;
		dpPanelA11yResizeFrame=0;
		dpPanelApplyAccessibilityPolicies(root||document,refreshOptions);
	});
}
/**
 * Debounces accessibility refreshes triggered by input-driven size changes.
 *
 * @param {ParentNode|null} root Refresh scope.
 * @returns {void}
 */
function dpPanelScheduleAccessibilityInputRefresh(root){
	if(dpPanelA11yInputRefreshTimer){clearTimeout(dpPanelA11yInputRefreshTimer);}
	dpPanelA11yDeferredFullRefresh=true;
	dpPanelA11yInputRefreshTimer=setTimeout(function(){
		dpPanelA11yInputRefreshTimer=0;
		dpPanelScheduleAccessibilityPolicyRefresh(root||document,{preserveAdaptive:true});
	},360);
}
/**
 * Observes accessibility policy containers for resize-driven refreshes.
 *
 * Each node is observed once and marked with dataset state to prevent duplicate
 * ResizeObserver registrations as dynamic content is initialized.
 *
 * @param {ParentNode|null} root Search scope for policy containers.
 * @returns {void}
 */
function dpPanelObserveAccessibilityPolicies(root){
	if(typeof ResizeObserver==="undefined"){return;}
	if(!dpPanelA11yResizeObserver){
		dpPanelA11yResizeObserver=new ResizeObserver(function(){
			dpPanelScheduleAccessibilityPolicyRefresh(document,{preserveAdaptive:true});
		});
	}
	var observed=[];
	var rootNode=root||document;
	var observerSelector=".dp-panel,.dp-panel-modal-root,.dp-panel-main-region,.dp-panel-relation,[data-dp-panel-a11y-policy='1'],[data-dp-panel-a11y-default='1'],form.dp-panel-form";
	if(rootNode.nodeType===1&&rootNode.matches&&rootNode.matches(observerSelector)){
		observed.push(rootNode);
	}
	rootNode.querySelectorAll(observerSelector).forEach(function(node){
		observed.push(node);
	});
	observed.forEach(function(node){
		if(node.dataset&&node.dataset.dpPanelA11yObserved!=="1"){
			node.dataset.dpPanelA11yObserved="1";
			dpPanelA11yResizeObserver.observe(node);
		}
	});
}
/**
 * Schedules enhancement initialization for newly inserted field markup.
 *
 * Multiple mutation roots are batched into one animation frame and followed by an
 * accessibility refresh when any root was processed.
 *
 * @param {Element|null} root Newly added field or container.
 * @returns {void}
 */
function dpPanelScheduleFieldMutationEnhancements(root){
	if(root&&dpPanelFieldMutationRoots.indexOf(root)===-1){
		dpPanelFieldMutationRoots.push(root);
	}
	if(dpPanelFieldMutationFrame){return;}
	dpPanelFieldMutationFrame=requestAnimationFrame(function(){
		var roots=dpPanelFieldMutationRoots.splice(0);
		dpPanelFieldMutationFrame=0;
		roots.forEach(function(node){
			if(node&&node.querySelectorAll){
				dpPanelInitFieldEnhancements(node);
			}
		});
		if(roots.length){
			dpPanelScheduleAccessibilityPolicyRefresh(document);
		}
	});
}
/**
 * Observes a root for dynamically inserted Panel fields and widgets.
 *
 * Matching additions are queued for field enhancement initialization so masks,
 * formatters, editors, uploaders, and accessibility policies activate after AJAX
 * or client-side rendering.
 *
 * @param {ParentNode|null} root Mutation observation root.
 * @returns {void}
 */
function dpPanelObserveFieldMutations(root){
	if(typeof MutationObserver==="undefined"){return;}
	var target=root&&root.nodeType===1 ? root : document.body;
	if(!target||target.dataset&&target.dataset.dpPanelFieldMutationObserved==="1"){return;}
	if(!dpPanelFieldMutationObserver){
		dpPanelFieldMutationObserver=new MutationObserver(function(mutations){
			mutations.forEach(function(mutation){
				mutation.addedNodes.forEach(function(node){
					if(node.nodeType!==1||node.dataset&&node.dataset.dpPanelFieldMutationObserved==="1"){return;}
					if(node.matches&&node.matches(".dp-panel-field,[data-dp-panel-a11y-policy='1'],[data-dp-panel-a11y-default='1'],[data-dp-panel-mask],[data-dp-panel-format],[data-dp-panel-uploader],[data-dp-panel-editor]")){
						dpPanelScheduleFieldMutationEnhancements(node);
					}
					else if(node.querySelector&&node.querySelector(".dp-panel-field,[data-dp-panel-a11y-policy='1'],[data-dp-panel-a11y-default='1'],[data-dp-panel-mask],[data-dp-panel-format],[data-dp-panel-uploader],[data-dp-panel-editor]")){
						dpPanelScheduleFieldMutationEnhancements(node);
					}
				});
			});
		});
	}
	if(target.dataset){target.dataset.dpPanelFieldMutationObserved="1";}
	dpPanelFieldMutationObserver.observe(target,{childList:true,subtree:true});
}
/**
 * Applies width, adornment, label, contrast, and touch-target policy to one field.
 *
 * The policy may expand a field across the grid, stack label/adornment content,
 * record measurement datasets for diagnostics, and toggle failure classes. When
 * skipReset is false, prior adaptive state is restored before measuring.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {boolean} skipReset Whether to preserve prior adaptive state before measuring.
 * @returns {void}
 */
function dpPanelApplyFieldAccessibilityPolicy(field,skipReset){
	if(!field||!field.dataset){return;}
	if(field.dataset.dpPanelA11yDisabled==="1"){
		field.classList.remove("dp-panel-a11y-expanded");
		field.classList.remove("dp-panel-a11y-constrained");
		field.classList.remove("dp-panel-a11y-contrast-fail");
		field.classList.remove("dp-panel-a11y-touch-fail");
		field.classList.remove("dp-panel-a11y-adornment-pressure");
		field.classList.remove("dp-panel-a11y-adornment-expanded");
		field.classList.remove("dp-panel-a11y-adornment-stacked");
		field.classList.remove("dp-panel-a11y-label-expanded");
		field.classList.remove("dp-panel-a11y-label-stacked");
		dpPanelA11yApplyLabelStack(field,false);
		delete field.dataset.dpPanelA11yWidthStatus;
		delete field.dataset.dpPanelA11yContrastStatus;
		delete field.dataset.dpPanelA11yTouchTargetStatus;
		delete field.dataset.dpPanelA11yTouchTargetFailures;
		delete field.dataset.dpPanelA11yUsableWidth;
		delete field.dataset.dpPanelA11yRequiredWidth;
		delete field.dataset.dpPanelA11yRequiredWidthSource;
		delete field.dataset.dpPanelA11yCharacterWidth;
		delete field.dataset.dpPanelA11yControlPadding;
		delete field.dataset.dpPanelA11yAdornmentStatus;
		delete field.dataset.dpPanelA11yAdornmentRatio;
		delete field.dataset.dpPanelA11yAdornmentWidth;
		delete field.dataset.dpPanelA11yLabelStatus;
		delete field.dataset.dpPanelA11yLabelRatio;
		delete field.dataset.dpPanelA11yLabelWidth;
		dpPanelA11yUpdateFieldStatus(field,{issue_messages:[],action_messages:[]});
		return;
	}
	if(!skipReset){dpPanelA11yResetAdaptiveField(field);}
	var target=dpPanelA11yPolicyTarget(field);
	field.dataset.dpPanelA11yUsableTarget=dpPanelA11yPolicyTargetKind(field,target);
	var minWidth=parseFloat(dpPanelA11yInheritedValue(field,"dpPanelA11yMinUsableWidth")||"0");
	var unit=dpPanelA11yInheritedValue(field,"dpPanelA11yMinUsableWidthUnit")||"px";
	var minChars=parseFloat(dpPanelA11yInheritedValue(field,"dpPanelA11yMinUsableChars")||"0");
	var charWidth=dpPanelA11yCharacterWidth(target);
	var padding=dpPanelA11yControlPadding(target);
	var requiredPx=0;
	var requiredSource="";
	if(minWidth>0){
		requiredPx=unit==="ch" ? (minWidth*charWidth)+padding : minWidth;
		requiredSource=unit==="ch" ? "ch" : "px";
	}
	if(minChars>0){
		var charRequired=(minChars*charWidth)+padding;
		if(charRequired>requiredPx){
			requiredPx=charRequired;
			requiredSource="chars";
		}
	}
	if(requiredPx>0){
		var usableTarget=dpPanelA11yEffectiveControlTarget(target);
		var usable=usableTarget.getBoundingClientRect().width;
		field.dataset.dpPanelA11yUsableWidth=String(Math.round(usable));
		field.dataset.dpPanelA11yRequiredWidth=String(Math.round(requiredPx));
		field.dataset.dpPanelA11yRequiredWidthSource=requiredSource;
		field.dataset.dpPanelA11yCharacterWidth=charWidth.toFixed(2);
		field.dataset.dpPanelA11yControlPadding=String(Math.round(padding));
		if(usable>0&&usable+0.5<requiredPx){
			dpPanelA11ySetGridColumn(field,"1 / -1");
			field.classList.add("dp-panel-a11y-expanded");
			var expandedUsable=dpPanelA11yEffectiveControlTarget(target).getBoundingClientRect().width;
			field.dataset.dpPanelA11yUsableWidth=String(Math.round(expandedUsable));
			if(expandedUsable+0.5<requiredPx){
				field.classList.add("dp-panel-a11y-constrained");
				field.dataset.dpPanelA11yWidthStatus="constrained";
			}
			else {
				field.classList.remove("dp-panel-a11y-constrained");
				field.dataset.dpPanelA11yWidthStatus="expanded";
			}
		}
		else {
			if(field.classList.contains("dp-panel-a11y-expanded")){
				dpPanelA11yRestoreGridColumns(field);
			}
			field.classList.remove("dp-panel-a11y-expanded");
			field.classList.remove("dp-panel-a11y-constrained");
			field.dataset.dpPanelA11yWidthStatus="pass";
		}
	}
	var maxAdornmentRatio=parseFloat(dpPanelA11yInheritedValue(field,"dpPanelA11yMaxAdornmentRatio")||"0");
	if(maxAdornmentRatio>0){
		var pressure=dpPanelA11yAdornmentPressure(field,target);
		if(pressure.ratio>maxAdornmentRatio&&!field.classList.contains("dp-panel-a11y-adornment-expanded")){
			dpPanelA11ySetGridColumn(field,"1 / -1");
			field.classList.add("dp-panel-a11y-adornment-expanded");
			pressure=dpPanelA11yAdornmentPressure(field,target);
		}
		if(pressure.ratio>maxAdornmentRatio&&!field.classList.contains("dp-panel-a11y-adornment-stacked")){
			field.classList.add("dp-panel-a11y-adornment-stacked");
			pressure=dpPanelA11yAdornmentPressure(field,target);
		}
		field.dataset.dpPanelA11yAdornmentWidth=String(Math.round(pressure.width));
		field.dataset.dpPanelA11yAdornmentRatio=pressure.ratio.toFixed(2);
		if(pressure.ratio>maxAdornmentRatio){
			field.dataset.dpPanelA11yAdornmentStatus="pressured";
			field.classList.add("dp-panel-a11y-adornment-pressure");
		}
		else {
			field.dataset.dpPanelA11yAdornmentStatus=field.classList.contains("dp-panel-a11y-adornment-stacked") ? "stacked" : (field.classList.contains("dp-panel-a11y-adornment-expanded") ? "expanded" : "pass");
			field.classList.remove("dp-panel-a11y-adornment-pressure");
		}
	}
	var maxLabelRatio=parseFloat(dpPanelA11yInheritedValue(field,"dpPanelA11yMaxLabelRatio")||"0");
	if(maxLabelRatio>0){
		var labelPressure=dpPanelA11yLabelPressure(field);
		if(labelPressure.ratio>maxLabelRatio&&!field.classList.contains("dp-panel-a11y-label-expanded")){
			dpPanelA11ySetGridColumn(field,"1 / -1");
			field.classList.add("dp-panel-a11y-label-expanded");
			labelPressure=dpPanelA11yLabelPressure(field);
		}
		if(labelPressure.ratio>maxLabelRatio&&!field.classList.contains("dp-panel-a11y-label-stacked")){
			field.classList.add("dp-panel-a11y-label-stacked");
			dpPanelA11yApplyLabelStack(field,true);
			labelPressure=dpPanelA11yLabelPressure(field);
		}
		field.dataset.dpPanelA11yLabelWidth=String(Math.round(labelPressure.width));
		field.dataset.dpPanelA11yLabelRatio=labelPressure.ratio.toFixed(2);
		field.dataset.dpPanelA11yLabelStatus=field.classList.contains("dp-panel-a11y-label-stacked") ? "stacked" : (field.classList.contains("dp-panel-a11y-label-expanded") ? "expanded" : "pass");
	}
	var minContrast=parseFloat(dpPanelA11yInheritedValue(field,"dpPanelA11yContrastMin")||"0");
	if(minContrast>0){
		var contrastTarget=dpPanelA11yContrastTarget(field);
		var style=getComputedStyle(contrastTarget);
		var foreground=dpPanelCssColorToRgb(style.color);
		var background=dpPanelCssColorToRgb(style.backgroundColor)||dpPanelElementBackgroundColor(contrastTarget.parentElement||field);
		if(foreground&&background){
			var ratio=dpPanelContrastRatio(foreground,background);
			field.dataset.dpPanelA11yContrastRatio=ratio.toFixed(2);
			field.dataset.dpPanelA11yContrastStatus=ratio+0.005>=minContrast ? "pass" : "fail";
			field.classList.toggle("dp-panel-a11y-contrast-fail",ratio+0.005<minContrast);
		}
	}
	var minTouchTarget=parseFloat(dpPanelA11yInheritedValue(field,"dpPanelA11yMinTouchTarget")||"0");
	if(minTouchTarget>0){
		var failures=0;
		/**
		 * Resolves the visible rectangle used for a touch target check.
		 *
		 * @param {HTMLElement} target Candidate touch target.
		 * @returns {DOMRect} Measured rectangle.
		 */
		function effectiveTouchRect(target){
			return dpPanelA11yEffectiveControlTarget(target).getBoundingClientRect();
		}
		var touchTargets=Array.from(field.querySelectorAll("input:not([type='hidden']),textarea,select,button,a[href],[role='button'],[tabindex]:not([tabindex='-1']),[data-dp-panel-field-button]")).filter(function(target){
			var style=getComputedStyle(target);
			var rect=effectiveTouchRect(target);
			return style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0&&rect.height>0;
		});
		touchTargets.forEach(function(target){
			var rect=effectiveTouchRect(target);
			if(rect.width+0.5<minTouchTarget||rect.height+0.5<minTouchTarget){failures++;}
		});
		field.dataset.dpPanelA11yTouchTargetFailures=String(failures);
		field.dataset.dpPanelA11yTouchTargetStatus=failures>0 ? "fail" : "pass";
		field.classList.toggle("dp-panel-a11y-touch-fail",failures>0);
	}
}
/**
 * Counts active CSS grid columns for a Panel field grid.
 *
 * @param {HTMLElement|null} grid Grid container.
 * @returns {number} Column count, falling back to one.
 */
function dpPanelA11yGridColumnCount(grid){
	if(!grid||!window.getComputedStyle){return 1;}
	var style=getComputedStyle(grid);
	var template=(style.gridTemplateColumns||"").trim();
	if(template&&template!=="none"){
		var columns=template.split(/\s+/).filter(Boolean).length;
		if(columns>0){return columns;}
	}
	var value=parseInt(style.getPropertyValue("--dp-grid-cols")||"1",10);
	return isFinite(value) ? Math.max(1,value) : 1;
}
/**
 * Checks whether a field is visible and measurable.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {boolean} Whether the field has rendered dimensions.
 */
function dpPanelA11yVisibleField(field){
	if(!field||!field.getBoundingClientRect){return false;}
	var style=getComputedStyle(field);
	var rect=field.getBoundingClientRect();
	return style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0&&rect.height>0;
}
/**
 * Captures row groupings for visible fields inside Panel form grids.
 *
 * Rows are inferred from rendered top positions so later adaptive row reflow can
 * operate on the actual browser layout rather than static grid metadata.
 *
 * @param {HTMLElement[]} fields Fields participating in policy processing.
 * @returns {Object[]} Grid snapshots with column counts and row field groups.
 */
function dpPanelA11ySnapshotRows(fields){
	var grids=[];
	fields.forEach(function(field){
		var grid=field.closest&&field.closest(".dp-panel-form-grid");
		if(grid&&grids.indexOf(grid)===-1){grids.push(grid);}
	});
	return grids.map(function(grid){
		var rows=[];
		Array.from(grid.children).filter(function(child){
			return child.classList&&child.classList.contains("dp-panel-field")&&fields.indexOf(child)!==-1&&dpPanelA11yVisibleField(child);
		}).forEach(function(field){
			var rect=field.getBoundingClientRect();
			var row=rows.find(function(item){return Math.abs(item.top-rect.top)<3;});
			if(!row){
				row={top:rect.top,fields:[]};
				rows.push(row);
			}
			row.fields.push(field);
		});
		return {grid:grid,columns:dpPanelA11yGridColumnCount(grid),rows:rows};
	});
}
/**
 * Checks whether a field was expanded by a field-level accessibility policy.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {boolean} Whether the field has adaptive expansion classes.
 */
function dpPanelA11yFieldMovedForPolicy(field){
	return !!(field&&field.classList&&(field.classList.contains("dp-panel-a11y-expanded")||field.classList.contains("dp-panel-a11y-adornment-expanded")||field.classList.contains("dp-panel-a11y-label-expanded")));
}
/**
 * Forces a field to a grid column span while preserving original values.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {string} value Grid column value to apply.
 * @returns {void}
 */
function dpPanelA11ySetGridColumn(field,value){
	if(!field||!field.dataset){return;}
	dpPanelA11yRememberGridColumns(field);
	field.style.setProperty("grid-column",value,"important");
	dpPanelA11yGridColumnProps().forEach(function(prop){
		field.style.setProperty(prop,value,"important");
	});
}
/**
 * Reflows rows when expanded fields would otherwise share a crowded row.
 *
 * @param {Object[]} rowSnapshots Grid row snapshots captured before field policies.
 * @returns {number} Number of fields marked as row-reflowed.
 */
function dpPanelA11yReflowRows(rowSnapshots){
	var reflowed=0;
	rowSnapshots.forEach(function(snapshot){
		var columns=Math.max(1,snapshot.columns||1);
		snapshot.rows.forEach(function(row){
			var moved=row.fields.filter(dpPanelA11yFieldMovedForPolicy);
			var remaining=row.fields.filter(function(field){
				return moved.indexOf(field)===-1&&dpPanelA11yVisibleField(field);
			});
			if(!moved.length||!remaining.length||columns<=1){return;}
			row.fields.filter(dpPanelA11yVisibleField).forEach(function(field){
				dpPanelA11ySetGridColumn(field,"1 / -1");
				field.classList.add("dp-panel-a11y-row-reflowed");
				field.dataset.dpPanelA11yRowReflowStatus="reflowed";
				field.dataset.dpPanelA11yRowReflowSpan=String(columns);
				field.dataset.dpPanelA11yRowReflowSource=moved.map(dpPanelA11yFieldName).filter(Boolean).join(" ");
				reflowed++;
			});
		});
	});
	return reflowed;
}
/**
 * Returns the selector set for card-like Panel containers.
 *
 * @returns {string} CSS selector matching Panel surfaces that may be flattened.
 */
function dpPanelA11yCardSelector(){
	return ".dp-panel-card,.dp-panel-custom-page>section,.dp-panel-custom-page>article,.dp-panel-form-section,.dp-panel-show,.dp-panel-record-heading,.dp-panel-relation,.dp-panel-page-table,.dp-panel-table-shell,.dp-panel-summary,.dp-panel-widget";
}
/**
 * Checks whether a node is treated as a card-like Panel surface.
 *
 * @param {Node|null} node Candidate node.
 * @returns {boolean} Whether the node matches the card selector set.
 */
function dpPanelA11yIsCardLike(node){
	return !!(node&&node.nodeType===1&&node.matches&&node.matches(dpPanelA11yCardSelector()));
}
/**
 * Filters a container's children down to meaningful visible content.
 *
 * Whitespace text, scripts, templates, hidden content, and generated a11y helper
 * controls are ignored so redundant wrapper-card detection does not count its own
 * diagnostics as content.
 *
 * @param {Node} node Container to inspect.
 * @returns {Node[]} Meaningful child nodes.
 */
function dpPanelA11yMeaningfulChildren(node){
	var children=[];
	Array.from(node.childNodes||[]).forEach(function(child){
		if(child.nodeType===3){
			if(String(child.textContent||"").trim()!==""){children.push(child);}
			return;
		}
		if(child.nodeType!==1){return;}
		if(child.matches&&child.matches("script,style,template,[hidden],[data-dp-panel-a11y-status-message],[data-dp-panel-a11y-dev-badge],[data-dp-panel-a11y-dev-popup]")){return;}
		children.push(child);
	});
	return children;
}
/**
 * Removes redundant-card flattening state below a root.
 *
 * @param {ParentNode|null} root Search scope.
 * @returns {void}
 */
function dpPanelA11yResetCardFlattening(root){
	(root||document).querySelectorAll("[data-dp-panel-a11y-card-flattened='1']").forEach(function(card){
		card.classList.remove("dp-panel-a11y-card-flattened");
		delete card.dataset.dpPanelA11yCardFlattened;
		delete card.dataset.dpPanelA11yCardFlattenedChild;
	});
}
/**
 * Marks wrapper cards that contain exactly one card-like child as flattened.
 *
 * The operation records diagnostics only; CSS performs the visual flattening so
 * markup structure remains available to the framework.
 *
 * @param {ParentNode|null} root Search scope.
 * @returns {number} Number of card wrappers marked as flattened.
 */
function dpPanelA11yFlattenRedundantCards(root){
	var flattened=0;
	(root||document).querySelectorAll(dpPanelA11yCardSelector()).forEach(function(card){
		if(card.closest&&card.closest("[data-dp-panel-a11y-disabled='1']")){return;}
		var children=dpPanelA11yMeaningfulChildren(card);
		if(children.length!==1||!dpPanelA11yIsCardLike(children[0])){return;}
		card.classList.add("dp-panel-a11y-card-flattened");
		card.dataset.dpPanelA11yCardFlattened="1";
		card.dataset.dpPanelA11yCardFlattenedChild=children[0].className||children[0].tagName.toLowerCase();
		flattened++;
	});
	return flattened;
}
/**
 * Checks whether a table is visible and measurable.
 *
 * @param {HTMLTableElement|null} table Candidate table.
 * @returns {boolean} Whether the table has rendered dimensions.
 */
function dpPanelA11yVisibleTable(table){
	if(!table||!table.getBoundingClientRect){return false;}
	var style=getComputedStyle(table);
	var rect=table.getBoundingClientRect();
	return style.display!=="none"&&style.visibility!=="hidden"&&rect.width>0&&rect.height>0;
}
/**
 * Builds the effective header-cell list for a table.
 *
 * Multi-row headers with rowspans and colspans are expanded into a grid and the
 * final header row is used as the column label source.
 *
 * @param {HTMLTableElement} table Table to inspect.
 * @returns {HTMLTableCellElement[]} Effective header cells by column index.
 */
function dpPanelA11yTableHeaderCells(table){
	var rows=Array.from(table.tHead ? table.tHead.rows : []);
	if(!rows.length){return [];}
	var grid=[];
	rows.forEach(function(row,rowIndex){
		grid[rowIndex]=grid[rowIndex]||[];
		var colIndex=0;
		Array.from(row.cells||[]).forEach(function(cell){
			while(grid[rowIndex][colIndex]){colIndex++;}
			var colspan=Math.max(1,cell.colSpan||1);
			var rowspan=Math.max(1,cell.rowSpan||1);
			for(var rowOffset=0;rowOffset<rowspan;rowOffset++){
				var targetRow=rowIndex+rowOffset;
				grid[targetRow]=grid[targetRow]||[];
				for(var colOffset=0;colOffset<colspan;colOffset++){
					grid[targetRow][colIndex+colOffset]=cell;
				}
			}
			colIndex+=colspan;
		});
	});
	return (grid[grid.length-1]||[]).filter(Boolean);
}
/**
 * Collects table body rows that are safe for column optimization sampling.
 *
 * Rows with hidden state, one cell, or colspans are skipped because they do not
 * represent a regular column grid.
 *
 * @param {HTMLTableElement} table Table to inspect.
 * @returns {HTMLTableRowElement[]} Body rows with regular cells.
 */
function dpPanelA11yTableBodyRows(table){
	return Array.from(table.tBodies||[]).reduce(function(rows,tbody){
		return rows.concat(Array.from(tbody.rows||[]));
	},[]).filter(function(row){
		if(row.hidden){return false;}
		var cells=Array.from(row.cells||[]);
		return cells.length>1&&cells.every(function(cell){return (cell.colSpan||1)===1;});
	});
}
/**
 * Detects whether a table is already in responsive card layout.
 *
 * @param {HTMLTableElement|null} table Table to inspect.
 * @returns {boolean} Whether column optimization should be skipped for card mode.
 */
function dpPanelA11yTableInCardMode(table){
	if(!table||!table.getBoundingClientRect){return false;}
	var style=getComputedStyle(table);
	if(style.display&&style.display!=="table"){return true;}
	var row=dpPanelA11yTableBodyRows(table)[0]||null;
	if(row){
		var rowDisplay=getComputedStyle(row).display;
		if(rowDisplay&&rowDisplay!=="table-row"){return true;}
		var firstCell=row.cells&&row.cells[0] ? row.cells[0] : null;
		var cellDisplay=firstCell ? getComputedStyle(firstCell).display : "";
		if(cellDisplay==="grid"||cellDisplay==="flex"||cellDisplay==="block"){return true;}
	}
	return !!(window.matchMedia&&window.matchMedia("(max-width: 1024px)").matches&&table.closest(".dp-panel[data-dp-panel-kind='index'],.dp-panel[data-dp-panel-kind='board']"));
}
/**
 * Produces a bounded text-length measure for table column sizing.
 *
 * The measure accounts for both total text and longest word so compact columns do
 * not collapse around unbreakable values.
 *
 * @param {string} value Cell or header text.
 * @returns {number} Bounded sizing measure.
 */
function dpPanelA11yTextMeasure(value){
	value=String(value||"").replace(/\s+/g," ").trim();
	if(value===""){return 0;}
	var longWord=value.split(/\s+/).reduce(function(max,word){return Math.max(max,word.length);},0);
	return Math.min(32,Math.max(Math.min(value.length,24),longWord));
}
/**
 * Extracts visible text from a table cell for sizing heuristics.
 *
 * Generated screen-reader-only and hidden diagnostic content is removed before
 * measuring so accessibility helpers do not distort column widths.
 *
 * @param {HTMLTableCellElement|null} cell Table cell.
 * @returns {string} Normalized visible text.
 */
function dpPanelA11yCellText(cell){
	if(!cell){return "";}
	var clone=cell.cloneNode(true);
	Array.from(clone.querySelectorAll("script,style,template,.dp-panel-sr-only,[aria-hidden='true']")).forEach(function(node){node.remove();});
	return (clone.textContent||"").replace(/\s+/g," ").trim();
}
/**
 * Classifies a table column for width bounds and compact styling.
 *
 * @param {HTMLTableCellElement|null} header Header cell for the column.
 * @param {HTMLTableCellElement[]} cells Sample body cells.
 * @param {number} index Column index.
 * @param {number} count Total column count.
 * @returns {string} Column kind token.
 */
function dpPanelA11yColumnKind(header,cells,index,count){
	if(header&&header.classList&&header.classList.contains("dp-panel-select")){return "select";}
	if(header&&header.classList&&header.classList.contains("dp-panel-actions")){return "actions";}
	if(index===count-1&&cells.some(function(cell){return cell&&cell.classList&&cell.classList.contains("dp-panel-actions");})){return "actions";}
	var sample=cells.map(dpPanelA11yCellText).filter(Boolean).slice(0,8).join(" ");
	if(/^\s*[$€£¥]?\s*[\d,]+(?:\.\d+)?\s*%?\s*$/.test(sample)){return "numeric";}
	if(sample.length&&sample.length<80&&cells.some(function(cell){return cell&&cell.querySelector&&cell.querySelector(".dp-panel-badge,.dp-panel-status,.dp-panel-tag");})){return "status";}
	return "text";
}
/**
 * Provides min, max, and reduction weight for a table column kind.
 *
 * @param {string} kind Column kind token.
 * @param {number} index Column index.
 * @param {number} count Total column count.
 * @returns {Object} Width bounds and compression weight.
 */
function dpPanelA11yColumnBounds(kind,index,count){
	if(kind==="select"){return {min:44,max:54,weight:.1};}
	if(kind==="actions"){return {min:132,max:178,weight:.25};}
	if(kind==="numeric"){return {min:92,max:148,weight:.8};}
	if(kind==="status"){return {min:104,max:174,weight:.65};}
	if(index===1){return {min:172,max:300,weight:.35};}
	if(index===2){return {min:172,max:320,weight:.45};}
	return {min:124,max:260,weight:1};
}
/**
 * Clears a11y table optimization while preserving explicit user column widths.
 *
 * @param {ParentNode|null} root Search scope.
 * @returns {void}
 */
function dpPanelA11yResetTableOptimization(root){
	(root||document).querySelectorAll(".dp-panel-table[data-dp-panel-a11y-table-optimized='1']").forEach(function(table){
		if(table.dataset.dpPanelUserColumnWidths==="1"||dpPanelHasSavedColumnWidths(table)){
			dpPanelApplyColumnWidths(table);
			table.dataset.dpPanelA11yTableSkipped="user_column_widths";
			return;
		}
		dpPanelClearTableColumnSizing(table);
	});
}
/**
 * Recomputes optimized table sizing when its actual container changes.
 *
 * Resize-driven policy passes preserve adaptive field placement, but table widths
 * must still follow embedded Panel and relation containers. Card-mode tables have
 * generated desktop widths removed immediately so their rows can reflow safely.
 *
 * @param {ParentNode|null} root Search scope.
 * @returns {number} Number of tables re-optimized.
 */
function dpPanelA11yRefreshTableOptimizations(root){
	var scope=root||document;
	var needsOptimization=false;
	scope.querySelectorAll(".dp-panel-table").forEach(function(table){
		if(table.dataset.dpPanelUserColumnWidths==="1"||dpPanelHasSavedColumnWidths(table)){
			dpPanelApplyColumnWidths(table);
			table.dataset.dpPanelA11yTableSkipped="user_column_widths";
			return;
		}
		if(dpPanelA11yTableInCardMode(table)){
			if(table.dataset.dpPanelA11yTableOptimized==="1"){dpPanelClearTableColumnSizing(table);}
			table.dataset.dpPanelA11yTableSkipped="card_mode";
			return;
		}
		if(table.dataset.dpPanelA11yTableSkipped==="card_mode"){delete table.dataset.dpPanelA11yTableSkipped;}
		if(!dpPanelA11yVisibleTable(table)){return;}
		var wrapper=table.closest(".dp-panel-table-scroll,.dp-panel-page-table,.dp-panel-relation")||table.parentElement;
		var available=Math.max(260,wrapper ? wrapper.clientWidth : table.getBoundingClientRect().width);
		var previous=parseFloat(table.dataset.dpPanelA11yTableAvailableWidth||"0")||0;
		if(table.dataset.dpPanelA11yTableOptimized==="1"){
			if(Math.abs(previous-available)<=2){return;}
			dpPanelClearTableColumnSizing(table);
		}
		needsOptimization=true;
	});
	return needsOptimization ? dpPanelA11yOptimizeTableColumns(scope) : 0;
}
/**
 * Marks a header or data cell with optimized column metadata.
 *
 * @param {HTMLTableCellElement|null} cell Cell to mark.
 * @param {string} kind Column kind token.
 * @param {number} width Applied width in pixels.
 * @param {boolean} compact Whether the column was compressed below desired width.
 * @returns {void}
 */
function dpPanelA11yMarkColumnCell(cell,kind,width,compact){
	if(!cell||cell.colSpan>1){return;}
	cell.dataset.dpPanelA11yColumnOptimized="1";
	cell.dataset.dpPanelA11yColumnWidth=String(Math.round(width));
	cell.dataset.dpPanelA11yColumnKind=kind;
	cell.classList.toggle("dp-panel-a11y-column-compact",!!compact);
	cell.classList.toggle("dp-panel-a11y-column-actions",kind==="actions");
	cell.classList.toggle("dp-panel-a11y-column-select",kind==="select");
	cell.classList.toggle("dp-panel-a11y-column-numeric",kind==="numeric");
	cell.classList.toggle("dp-panel-a11y-column-status",kind==="status");
}
/**
 * Applies optimized column metadata to header, body, and footer cells.
 *
 * @param {HTMLTableElement} table Table being optimized.
 * @param {number} index Column index.
 * @param {string} kind Column kind token.
 * @param {number} width Applied width in pixels.
 * @param {boolean} compact Whether the column was compressed.
 * @param {HTMLTableCellElement|null} header Header cell.
 * @returns {void}
 */
function dpPanelA11yApplyColumnClasses(table,index,kind,width,compact,header){
	dpPanelA11yMarkColumnCell(header,kind,width,compact);
	Array.from(table.tBodies||[]).forEach(function(tbody){
		Array.from(tbody.rows||[]).forEach(function(row){
			var cell=row.cells&&row.cells[index] ? row.cells[index] : null;
			dpPanelA11yMarkColumnCell(cell,kind,width,compact);
		});
	});
	Array.from(table.tFoot ? table.tFoot.rows : []).forEach(function(row){
		var cell=row.cells&&row.cells[index] ? row.cells[index] : null;
		dpPanelA11yMarkColumnCell(cell,kind,width,compact);
	});
}
/**
 * Chooses the final table width after column optimization.
 *
 * @param {number} applied Total applied column width.
 * @param {number} available Available wrapper width.
 * @returns {number} Target table width in pixels.
 */
function dpPanelA11yTableTargetWidth(applied,available){
	applied=Math.max(0,Math.round(applied||0));
	available=Math.max(0,Math.round(available||0));
	if(!available||applied>=available){return applied;}
	return available;
}
/**
 * Stretches the final column to fill available space when optimization undershoots.
 *
 * @param {Object[]} columns Optimized column descriptors.
 * @param {number} available Available wrapper width.
 * @returns {Object[]} Copied column descriptors with final-column stretch applied.
 */
function dpPanelA11yColumnsWithLastStretch(columns,available){
	var next=(columns||[]).map(function(column){return Object.assign({},column);});
	available=Math.max(0,Math.round(available||0));
	if(!next.length||!available){return next;}
	var total=next.reduce(function(sum,column){return sum+(Math.round(column.width)||0);},0);
	if(total>=available){return next;}
	next[next.length-1].desired=Math.round(next[next.length-1].desired||next[next.length-1].width)||0;
	next[next.length-1].width=(Math.round(next[next.length-1].width)||0)+(available-total);
	return next;
}
/**
 * Optimizes Panel table column widths using visible header and sample cell content.
 *
 * Manual column widths and card-mode tables are preserved. Eligible tables receive
 * a generated colgroup, per-cell metadata, compression diagnostics, and scroll
 * preservation markers for policy summaries.
 *
 * @param {ParentNode|null} root Search scope.
 * @returns {number} Number of tables optimized.
 */
function dpPanelA11yOptimizeTableColumns(root){
	var optimized=0;
	(root||document).querySelectorAll(".dp-panel-table").forEach(function(table){
		if(!dpPanelA11yVisibleTable(table)||table.closest("[data-dp-panel-a11y-disabled='1']")){return;}
		if(table.dataset.dpPanelA11yTableOptimized==="1"){return;}
		if(table.dataset.dpPanelUserColumnWidths==="1"||dpPanelHasSavedColumnWidths(table)){
			dpPanelApplyColumnWidths(table);
			table.dataset.dpPanelA11yTableSkipped="user_column_widths";
			return;
		}
		if(dpPanelA11yTableInCardMode(table)){
			table.dataset.dpPanelA11yTableSkipped="card_mode";
			return;
		}
		var headers=dpPanelA11yTableHeaderCells(table);
		var bodyRows=dpPanelA11yTableBodyRows(table).slice(0,12);
		var count=Math.max(headers.length,bodyRows.reduce(function(max,row){return Math.max(max,(row.cells||[]).length);},0));
		if(count<2){return;}
		var wrapper=table.closest(".dp-panel-table-scroll,.dp-panel-page-table,.dp-panel-relation")||table.parentElement;
		var available=Math.max(260,wrapper ? wrapper.clientWidth : table.getBoundingClientRect().width);
		var savedWidths=dpPanelReadColumnWidths(table);
		var columns=[];
		for(var index=0;index<count;index++){
			var header=headers[index]||null;
			var cells=bodyRows.map(function(row){return row.cells&&row.cells[index] ? row.cells[index] : null;}).filter(Boolean);
			var kind=dpPanelA11yColumnKind(header,cells,index,count);
			var bounds=dpPanelA11yColumnBounds(kind,index,count);
			var headerMeasure=dpPanelA11yTextMeasure(dpPanelA11yCellText(header));
			var cellMeasure=cells.reduce(function(max,cell){return Math.max(max,dpPanelA11yTextMeasure(dpPanelA11yCellText(cell)));},0);
			var desired=Math.max(bounds.min,Math.min(bounds.max,Math.round(Math.max(headerMeasure*8+30,cellMeasure*7+28))));
			var saved=header ? parseInt(savedWidths[dpPanelTableColumnKey(header,index)],10) : 0;
			if(saved>0){desired=Math.max(bounds.min,Math.min(520,saved));}
			columns.push({index:index,kind:kind,min:saved>0 ? Math.min(desired,520) : bounds.min,max:bounds.max,weight:saved>0 ? 0 : bounds.weight,desired:desired,width:desired,saved:saved>0});
		}
		var desiredTotal=columns.reduce(function(total,col){return total+col.desired;},0);
		var target=Math.max(available,Math.min(desiredTotal,available*1.28));
		if(desiredTotal>target){
			var reducible=columns.reduce(function(total,col){return total+Math.max(0,(col.desired-col.min)*col.weight);},0);
			columns.forEach(function(col){
				var capacity=Math.max(0,col.desired-col.min);
				var reduction=reducible>0 ? ((desiredTotal-target)*(capacity*col.weight)/reducible) : 0;
				col.width=Math.max(col.min,Math.round(col.desired-reduction));
			});
		}
		columns=dpPanelA11yColumnsWithLastStretch(columns,available);
		var appliedTotal=columns.reduce(function(total,col){return total+col.width;},0);
		var measuredTotal=columns.reduce(function(total,col,index){return total+(index===columns.length-1 ? Math.min(col.width,col.desired||col.width) : col.width);},0);
		var colgroup=document.createElement("colgroup");
		colgroup.dataset.dpPanelA11yColgroup="1";
		columns.forEach(function(col){
			var node=document.createElement("col");
			node.style.width=Math.round(col.width)+"px";
			node.dataset.dpPanelA11yColumnKind=col.kind;
			node.dataset.dpPanelA11yColumnDesired=String(Math.round(col.desired||col.width));
			colgroup.appendChild(node);
		});
		table.insertBefore(colgroup,table.firstChild);
		table.classList.add("dp-panel-a11y-table-optimized");
		table.classList.toggle("dp-panel-a11y-table-compressed",desiredTotal>target);
		var targetWidth=dpPanelA11yTableTargetWidth(appliedTotal,available);
		table.classList.toggle("dp-panel-a11y-table-scroll-preserved",targetWidth>available+2);
		table.style.setProperty("min-width",Math.round(targetWidth)+"px","important");
		table.style.setProperty("width","100%","important");
		var compactCount=0;
		columns.forEach(function(col){
			var compact=col.width+8<col.desired;
			if(compact){compactCount++;}
			dpPanelA11yApplyColumnClasses(table,col.index,col.kind,col.width,compact,headers[col.index]||null);
		});
		table.dataset.dpPanelA11yTableOptimized="1";
		table.dataset.dpPanelA11yTableColumnCount=String(count);
		table.dataset.dpPanelA11yTableAvailableWidth=String(Math.round(available));
		table.dataset.dpPanelA11yTableDesiredWidth=String(Math.round(desiredTotal));
		table.dataset.dpPanelA11yTableAppliedWidth=String(Math.round(appliedTotal));
		table.dataset.dpPanelA11yTableCompactColumns=String(compactCount);
		table.dataset.dpPanelA11yTableScrollPreserved=targetWidth>available+2 ? "1" : "0";
		table.dataset.dpPanelA11yLastColumnStretched=appliedTotal>measuredTotal+2 ? "1" : "0";
		optimized++;
	});
	return optimized;
}
/**
 * Runs the full accessibility policy pass for a root.
 *
 * The pass can reset adaptive state, apply field policies, reflow rows, flatten
 * redundant cards, optimize tables, and publish container summaries. Active
 * editing defers disruptive layout work.
 *
 * @param {ParentNode|null} root Policy root.
 * @param {Object} options Refresh options.
 * @returns {void}
 */
function dpPanelApplyAccessibilityPolicies(root,options){
	root=root||document;
	options=options||{};
	var preserveAdaptive=!!options.preserveAdaptive;
	if(dpPanelA11yUserIsEditing()){
		dpPanelA11yDeferredFullRefresh=true;
		return;
	}
	if(!preserveAdaptive){
		dpPanelA11yResetCardFlattening(root);
		dpPanelA11yResetTableOptimization(root);
	}
	var fields=[];
	root.querySelectorAll("[data-dp-panel-a11y-policy='1']").forEach(function(field){
		if(fields.indexOf(field)===-1){fields.push(field);}
	});
	root.querySelectorAll("[data-dp-panel-a11y-default='1'] .dp-panel-field").forEach(function(field){
		if(fields.indexOf(field)===-1){fields.push(field);}
	});
	if(!preserveAdaptive){
		fields.forEach(function(field){
			if(dpPanelFieldHasAccessibilityPolicy(field)){dpPanelA11yResetAdaptiveField(field);}
		});
	}
	var rowSnapshots=preserveAdaptive ? [] : dpPanelA11ySnapshotRows(fields);
	if(!preserveAdaptive){
		fields.forEach(function(field){
			if(dpPanelFieldHasAccessibilityPolicy(field)){dpPanelApplyFieldAccessibilityPolicy(field,true);}
		});
	}
	if(!preserveAdaptive){dpPanelA11yReflowRows(rowSnapshots);}
	if(preserveAdaptive){dpPanelA11yRefreshTableOptimizations(root);}
	if(!preserveAdaptive){
		dpPanelA11yFlattenRedundantCards(root);
		dpPanelA11yOptimizeTableColumns(root);
	}
	root.querySelectorAll(".dp-panel,[data-dp-panel-a11y-default='1'],form.dp-panel-form").forEach(function(container){
		dpPanelRefreshAccessibilityPolicySummary(container);
	});
	if(root.nodeType===1){
		var container=root.matches&&root.matches(".dp-panel,[data-dp-panel-a11y-default='1'],form.dp-panel-form") ? root : root.closest&&root.closest(".dp-panel,[data-dp-panel-a11y-default='1'],form.dp-panel-form");
		if(container){dpPanelRefreshAccessibilityPolicySummary(container);}
	}
}
/**
 * Resolves a stable field name for accessibility diagnostics.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {string} Explicit field name, control name, label text, or empty string.
 */
function dpPanelA11yFieldName(field){
	if(!field){return "";}
	var explicit=(field.dataset&&field.dataset.dpPanelFieldName)||field.getAttribute("data-dp-panel-field-name")||"";
	if(explicit){return explicit;}
	var control=field.querySelector("input[name]:not([type='hidden']),textarea[name],select[name],[name]");
	if(control&&control.name){return String(control.name).replace(/\[\]$/,"");}
	var label=field.querySelector(".dp-panel-field-label-text");
	return label ? (label.textContent||"").replace(/\s+/g," ").trim() : "";
}
/**
 * Resolves the human label text for a field.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {string} Normalized label text.
 */
function dpPanelA11yFieldLabel(field){
	var label=field ? field.querySelector(".dp-panel-field-label-text") : null;
	if(label){return (label.textContent||"").replace(/\s+/g," ").trim();}
	return "";
}
/**
 * Parses a nullable numeric diagnostic value.
 *
 * @param {*} value Dataset value.
 * @returns {number|null} Parsed number, or null when absent or invalid.
 */
function dpPanelA11yNumber(value){
	if(value==null||value===""){return null;}
	var number=parseFloat(value);
	return isFinite(number) ? number : null;
}
/**
 * Parses an integer diagnostic value with zero fallback.
 *
 * @param {*} value Dataset value.
 * @returns {number} Parsed integer or zero.
 */
function dpPanelA11yInteger(value){
	if(value==null||value===""){return 0;}
	var number=parseInt(value,10);
	return isFinite(number) ? number : 0;
}
/**
 * Rounds a numeric diagnostic value for localized messages.
 *
 * @param {number|null} value Numeric value.
 * @returns {string} Rounded display value.
 */
function dpPanelA11yRound(value){
	return value==null ? "" : String(Math.round(value*100)/100);
}
/**
 * Converts an accessibility token and summary item into localized message text.
 *
 * @param {string} token Issue or action token.
 * @param {Object} item Field, card, or table summary item.
 * @returns {string} Human-readable policy message.
 */
function dpPanelA11yTokenMessage(token,item){
	if(token==="width_expanded"){return dpPanelText("client.a11y_width_expanded","Field expanded to satisfy usable width policy.");}
	if(token==="width_constrained"){
		var source=item.required_width_source==="chars" ? dpPanelText("client.a11y_char_policy_source"," from the configured character policy") : "";
		return dpPanelText("client.a11y_width_constrained","Usable width {usable}px is below required {required}px{source}.",{usable:dpPanelA11yRound(item.usable_width),required:dpPanelA11yRound(item.required_width),source:source});
	}
	if(token==="contrast_fail"){return dpPanelText("client.a11y_contrast_fail","Contrast ratio {ratio} is below the configured contrast policy.",{ratio:dpPanelA11yRound(item.contrast_ratio)});}
	if(token==="touch_target_fail"){return dpPanelText(item.touch_target_failures===1 ? "client.a11y_touch_target_one" : "client.a11y_touch_target_many",item.touch_target_failures===1 ? "{count} touch target is below the configured minimum size." : "{count} touch targets are below the configured minimum size.",{count:item.touch_target_failures});}
	if(token==="adornment_expanded"){return dpPanelText("client.a11y_adornment_expanded","Field expanded to reduce adornment pressure.");}
	if(token==="adornment_stacked"){return dpPanelText("client.a11y_adornment_stacked","Input adornments stacked to preserve usable control width.");}
	if(token==="adornment_pressure"){return dpPanelText("client.a11y_adornment_pressure","Input adornments still exceed the configured pressure ratio.");}
	if(token==="label_expanded"){return dpPanelText("client.a11y_label_expanded","Field expanded to reduce label pressure.");}
	if(token==="label_stacked"){return dpPanelText("client.a11y_label_stacked","Label stacked to preserve usable control width.");}
	if(token==="row_reflowed"){return dpPanelText("client.a11y_row_reflowed","Affected row stacked cleanly after a field expanded for accessibility.");}
	if(token==="dom_reordered"){return dpPanelText("client.a11y_dom_reordered","Field moved in DOM order after the siblings it displaced.");}
	if(token==="card_flattened"){return dpPanelText("client.a11y_card_flattened","Redundant wrapper card flattened because it only contained another card surface.");}
	if(token==="table_columns_optimized"){return dpPanelText("client.a11y_table_columns_optimized","Table column widths optimized from measured header and cell content.");}
	if(token==="table_columns_user_preserved"){return dpPanelText("client.a11y_table_columns_user_preserved","Manual table column widths preserved; automatic table optimization is paused for this table.");}
	if(token==="table_columns_compacted"){return dpPanelText("client.a11y_table_columns_compacted","Some table columns were compacted so high-value columns keep usable width.");}
	if(token==="table_scroll_preserved"){return dpPanelText("client.a11y_table_scroll_preserved","Horizontal table scrolling was preserved because the table cannot fit without losing readability.");}
	return token.replace(/_/g," ");
}
/**
 * Converts accessibility tokens into localized messages.
 *
 * @param {string[]} tokens Issue or action tokens.
 * @param {Object} item Field, card, or table summary item.
 * @returns {string[]} Localized token messages.
 */
function dpPanelA11yTokenMessages(tokens,item){
	return tokens.map(function(token){return dpPanelA11yTokenMessage(token,item);});
}
/**
 * Ensures a field has a stable status element id for aria-describedby links.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {string} Status element id.
 */
function dpPanelA11yStatusId(field){
	if(!field){return "";}
	if(!field.id){
		var name=dpPanelA11yFieldName(field)||"field";
		field.id="dp-panel-a11y-field-"+name.replace(/[^a-zA-Z0-9_-]+/g,"-")+"-"+Math.random().toString(36).slice(2,8);
	}
	return field.id+"-policy-status";
}
/**
 * Finds controls that should reference a field's accessibility status message.
 *
 * @param {HTMLElement} field Panel field wrapper.
 * @returns {HTMLElement[]} Describable controls in the field.
 */
function dpPanelA11yDescribedControls(field){
	return Array.from(field.querySelectorAll("input:not([type='hidden']),textarea,select,button,[role='button'],[tabindex]:not([tabindex='-1'])"));
}
/**
 * Removes one aria-describedby token from a control.
 *
 * @param {HTMLElement} control Described control.
 * @param {string} id Description element id.
 * @returns {void}
 */
function dpPanelA11yRemoveDescriptionToken(control,id){
	var tokens=(control.getAttribute("aria-describedby")||"").split(/\s+/).filter(Boolean).filter(function(token){return token!==id;});
	if(tokens.length){control.setAttribute("aria-describedby",tokens.join(" "));}
	else {control.removeAttribute("aria-describedby");}
}
/**
 * Adds one aria-describedby token to a control if missing.
 *
 * @param {HTMLElement} control Described control.
 * @param {string} id Description element id.
 * @returns {void}
 */
function dpPanelA11yEnsureDescriptionToken(control,id){
	var tokens=(control.getAttribute("aria-describedby")||"").split(/\s+/).filter(Boolean);
	if(tokens.indexOf(id)===-1){tokens.push(id);}
	control.setAttribute("aria-describedby",tokens.join(" "));
}
/**
 * Checks whether developer accessibility badges should be shown.
 *
 * Production state is inherited from the nearest Panel, modal root, or global
 * Panel marker, keeping production pages quiet while dev pages expose guidance.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @returns {boolean} Whether dev badges are enabled.
 */
function dpPanelA11yDevBadgesEnabled(field){
	var panel=field&&field.closest ? field.closest(".dp-panel") : null;
	if(panel&&panel.dataset&&panel.dataset.dpPanelProduction!==undefined){return panel.dataset.dpPanelProduction==="0";}
	var modal=field&&field.closest ? field.closest(".dp-panel-modal-root") : null;
	if(modal&&modal.dataset&&modal.dataset.dpPanelProduction!==undefined){return modal.dataset.dpPanelProduction==="0";}
	var source=document.querySelector(".dp-panel[data-dp-panel-production]");
	return !!(source&&source.dataset&&source.dataset.dpPanelProduction==="0");
}
/**
 * Chooses the primary issue or action token for a diagnostic item.
 *
 * @param {Object} item Field, card, or table summary item.
 * @returns {string} Primary token.
 */
function dpPanelA11yPrimaryToken(item){
	var issues=item&&item.issues ? item.issues : [];
	var actions=item&&item.actions ? item.actions : [];
	return issues[0]||actions[0]||"accessibility_policy";
}
/**
 * Resolves a short localized title for an accessibility token.
 *
 * @param {string} token Issue or action token.
 * @returns {string} Localized token title.
 */
function dpPanelA11yTokenTitle(token){
	var map={
		width_constrained:dpPanelText("client.a11y_title_width_constrained","Width constrained"),
		width_expanded:dpPanelText("client.a11y_title_width_expanded","Width expanded"),
		contrast_fail:dpPanelText("client.a11y_title_contrast_fail","Contrast failed"),
		touch_target_fail:dpPanelText("client.a11y_title_touch_target_fail","Touch target failed"),
		adornment_expanded:dpPanelText("client.a11y_title_adornment_expanded","Adornment expanded"),
		adornment_stacked:dpPanelText("client.a11y_title_adornment_stacked","Adornment stacked"),
		adornment_pressure:dpPanelText("client.a11y_title_adornment_pressure","Adornment pressure"),
		label_expanded:dpPanelText("client.a11y_title_label_expanded","Label expanded"),
		label_stacked:dpPanelText("client.a11y_title_label_stacked","Label stacked"),
		row_reflowed:dpPanelText("client.a11y_title_row_reflowed","Row reflowed"),
		dom_reordered:dpPanelText("client.a11y_title_dom_reordered","DOM reordered"),
		card_flattened:dpPanelText("client.a11y_title_card_flattened","Card flattened"),
		table_columns_optimized:dpPanelText("client.a11y_title_table_columns_optimized","Table columns optimized"),
		table_columns_user_preserved:dpPanelText("client.a11y_title_table_columns_user_preserved","Manual table widths preserved"),
		table_columns_compacted:dpPanelText("client.a11y_title_table_columns_compacted","Table columns compacted"),
		table_scroll_preserved:dpPanelText("client.a11y_title_table_scroll_preserved","Table scroll preserved")
	};
	return map[token]||token.replace(/_/g," ");
}
/**
 * Resolves developer remediation guidance for an accessibility token.
 *
 * @param {string} token Issue or action token.
 * @returns {string} Remediation guidance.
 */
function dpPanelA11yRemediation(token){
	var map={
		width_constrained:dpPanelText("client.a11y_fix_width_constrained","Code fix: increase this field column span, reduce label/adornment/button pressure, or lower the configured min usable width/chars if the policy is too strict."),
		width_expanded:dpPanelText("client.a11y_fix_width_expanded","Code fix: give this field more planned column span in the schema if this expansion is expected."),
		contrast_fail:dpPanelText("client.a11y_fix_contrast_fail","Code fix: adjust theme tokens, field colors, or contrast policy so text and control backgrounds meet the configured ratio."),
		touch_target_fail:dpPanelText("client.a11y_fix_touch_target_fail","Code fix: increase icon button/control padding, use larger controls, or mark a deliberate compact-control exception."),
		adornment_expanded:dpPanelText("client.a11y_fix_adornment_expanded","Code fix: plan a wider field span or simplify prepend/append controls."),
		adornment_stacked:dpPanelText("client.a11y_fix_adornment_stacked","Code fix: replace secondary text buttons with icons or reduce adornments if the stacked layout feels noisy."),
		adornment_pressure:dpPanelText("client.a11y_fix_adornment_pressure","Code fix: reduce adornments/buttons or increase the field span; the automatic recovery still could not satisfy the policy."),
		label_expanded:dpPanelText("client.a11y_fix_label_expanded","Code fix: give this field more span or shorten/split the label/hint."),
		label_stacked:dpPanelText("client.a11y_fix_label_stacked","Code fix: shorten the label/hint or accept stacked label layout for this field."),
		row_reflowed:dpPanelText("client.a11y_fix_row_reflowed","Code fix: rebalance the row in the schema so sibling fields have room after expanded fields move out."),
		dom_reordered:dpPanelText("client.a11y_fix_dom_reordered","Code fix: move this field to its own row or later schema position if that order is preferred permanently."),
		card_flattened:dpPanelText("client.a11y_fix_card_flattened","Code fix: remove the extra wrapper card or move meaningful heading/actions/content into it."),
		table_columns_optimized:dpPanelText("client.a11y_fix_table_columns_optimized","Code fix: define table column width priorities, min/max widths, or responsive visibility rules if this optimized layout should become the default."),
		table_columns_user_preserved:dpPanelText("client.a11y_fix_table_columns_user_preserved","Code fix: save these column width preferences as table defaults if the manual layout should be shared."),
		table_columns_compacted:dpPanelText("client.a11y_fix_table_columns_compacted","Code fix: shorten table labels/cell copy, hide lower-priority columns, or configure tighter min widths for compact columns."),
		table_scroll_preserved:dpPanelText("client.a11y_fix_table_scroll_preserved","Code fix: reduce visible columns or add responsive column hiding if this table should fit without horizontal scroll.")
	};
	return map[token]||dpPanelText("client.a11y_fix_default","Code fix: encode the preferred layout or exception in the field/schema policy.");
}
/**
 * Creates, updates, or removes the developer-facing accessibility badge for a field.
 *
 * Badges expose why automatic policy action happened and what should be carried
 * back into schema, CSS, or field configuration. They are suppressed in production.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {Object} item Field summary item.
 * @param {string} id Status element id.
 * @param {string[]} messages Issue and action messages.
 * @returns {void}
 */
function dpPanelA11yUpsertDevBadge(field,item,id,messages){
	if(!field){return;}
	var visibleTokens=(item.issues||[]).concat(item.actions||[]).filter(function(token){
		return token!=="row_reflowed";
	});
	if(!visibleTokens.length){messages=[];}
	var slot=field.querySelector("[data-dp-panel-a11y-dev-slot]");
	var old=slot ? slot.querySelector("[data-dp-panel-a11y-dev-badge]") : null;
	var oldPopup=slot ? slot.querySelector("[data-dp-panel-a11y-dev-popup]") : null;
	if(!messages.length||!dpPanelA11yDevBadgesEnabled(field)){
		if(old){old.remove();}
		if(oldPopup){oldPopup.remove();}
		if(slot&&!slot.children.length){slot.remove();}
		return;
	}
	if(!slot){
		slot=document.createElement("div");
		slot.className="dp-panel-a11y-dev-slot";
		slot.dataset.dpPanelA11yDevSlot="1";
		field.appendChild(slot);
	}
	var token=visibleTokens[0]||dpPanelA11yPrimaryToken(item);
	var title=dpPanelA11yTokenTitle(token);
	var detail=messages.join(" ");
	var remediation=dpPanelA11yRemediation(token);
	var popupId=id+"-dev-popup";
	var badge=old||document.createElement("button");
	badge.type="button";
	badge.className="dp-panel-a11y-dev-badge dp-panel-row-link";
	badge.dataset.dpPanelA11yDevBadge="1";
	badge.setAttribute("aria-expanded","false");
	badge.setAttribute("aria-controls",popupId);
	badge.title=title+": "+detail+" "+remediation;
	badge.innerHTML="<span aria-hidden=\"true\">🤖</span><strong>"+title+"</strong>";
	var popup=oldPopup||document.createElement("div");
	popup.id=popupId;
	popup.className="dp-panel-a11y-dev-popup dp-panel-action-menu";
	popup.dataset.dpPanelA11yDevPopup="1";
	popup.setAttribute("role","dialog");
	popup.setAttribute("aria-label",title+" accessibility policy detail");
	popup.hidden=true;
	popup.innerHTML="<h4>"+dpPanelEscape(dpPanelText("client.accessibility_title","Dataphyre Panel Accessibility"))+"</h4><strong>"+dpPanelEscape(dpPanelText("client.accessibility_why","Why this happened"))+"</strong><p></p><strong>"+dpPanelEscape(dpPanelText("client.accessibility_carry_code","Carry into code"))+"</strong><p></p><small>"+dpPanelEscape(dpPanelText("client.accessibility_production","Accessibility remains silently enforced in production."))+"</small>";
	var paragraphs=popup.querySelectorAll("p");
	if(paragraphs[0]){paragraphs[0].textContent=detail;}
	if(paragraphs[1]){paragraphs[1].textContent=remediation;}
	badge.onmousedown=function(event){event.stopPropagation();};
	badge.onpointerdown=function(event){event.stopPropagation();};
	badge.ontouchstart=function(event){event.stopPropagation();};
	badge.onclick=function(event){
		event.preventDefault();
		event.stopPropagation();
		var open=popup.hidden;
		document.querySelectorAll("[data-dp-panel-a11y-dev-popup]").forEach(function(node){
			if(node!==popup){node.hidden=true;}
		});
		document.querySelectorAll("[data-dp-panel-a11y-dev-badge]").forEach(function(node){
			if(node!==badge){node.setAttribute("aria-expanded","false");}
		});
		popup.hidden=!open;
		badge.setAttribute("aria-expanded",open ? "true" : "false");
	};
	popup.addEventListener("click",function(event){event.stopPropagation();});
	if(!old){slot.appendChild(badge);}
	if(!oldPopup){slot.appendChild(popup);}
}
/**
 * Closes every open accessibility developer popup.
 *
 * @returns {void}
 */
function dpPanelA11yCloseDevPopups(){
	document.querySelectorAll("[data-dp-panel-a11y-dev-popup]").forEach(function(node){node.hidden=true;});
	document.querySelectorAll("[data-dp-panel-a11y-dev-badge]").forEach(function(node){node.setAttribute("aria-expanded","false");});
}
/**
 * Synchronizes a field's hidden status message, aria links, and dev badge.
 *
 * @param {HTMLElement|null} field Panel field wrapper.
 * @param {Object} item Field summary item.
 * @returns {void}
 */
function dpPanelA11yUpdateFieldStatus(field,item){
	if(!field||!field.dataset){return;}
	var id=dpPanelA11yStatusId(field);
	var messages=(item.issue_messages||[]).concat(item.action_messages||[]);
	var status=field.querySelector("#"+dpPanelCssEscape(id));
	if(!messages.length){
		if(status){status.remove();}
		dpPanelA11yUpsertDevBadge(field,item,id,messages);
		dpPanelA11yDescribedControls(field).forEach(function(control){dpPanelA11yRemoveDescriptionToken(control,id);});
		return;
	}
	if(!status){
		status=document.createElement("small");
		status.id=id;
		status.className="dp-panel-sr-only";
		status.setAttribute("data-dp-panel-a11y-status-message","1");
		status.setAttribute("role","status");
		status.setAttribute("aria-live","polite");
		field.appendChild(status);
	}
	status.textContent=messages.join(" ");
	dpPanelA11yUpsertDevBadge(field,item,id,messages);
	dpPanelA11yDescribedControls(field).forEach(function(control){dpPanelA11yEnsureDescriptionToken(control,id);});
}
/**
 * Builds the diagnostic summary item for one accessibility-managed field.
 *
 * Dataset measurements collected by policy application are converted into issue
 * and action tokens, localized messages, status counters, and assistive status UI.
 *
 * @param {HTMLElement} field Panel field wrapper.
 * @returns {Object} Field accessibility summary item.
 */
function dpPanelA11yFieldSummary(field){
	var data=field.dataset||{};
	var item={
		name:dpPanelA11yFieldName(field),
		label:dpPanelA11yFieldLabel(field),
		width_status:data.dpPanelA11yWidthStatus||"",
		usable_width:dpPanelA11yNumber(data.dpPanelA11yUsableWidth),
		required_width:dpPanelA11yNumber(data.dpPanelA11yRequiredWidth),
		required_width_source:data.dpPanelA11yRequiredWidthSource||"",
		character_width:dpPanelA11yNumber(data.dpPanelA11yCharacterWidth),
		control_padding:dpPanelA11yNumber(data.dpPanelA11yControlPadding),
		usable_target:data.dpPanelA11yUsableTarget||"",
		contrast_status:data.dpPanelA11yContrastStatus||"",
		contrast_ratio:dpPanelA11yNumber(data.dpPanelA11yContrastRatio),
		touch_target_status:data.dpPanelA11yTouchTargetStatus||"",
		touch_target_failures:dpPanelA11yInteger(data.dpPanelA11yTouchTargetFailures),
		adornment_status:data.dpPanelA11yAdornmentStatus||"",
		adornment_ratio:dpPanelA11yNumber(data.dpPanelA11yAdornmentRatio),
		adornment_width:dpPanelA11yNumber(data.dpPanelA11yAdornmentWidth),
		label_status:data.dpPanelA11yLabelStatus||"",
		label_ratio:dpPanelA11yNumber(data.dpPanelA11yLabelRatio),
		label_width:dpPanelA11yNumber(data.dpPanelA11yLabelWidth),
		row_reflow_status:data.dpPanelA11yRowReflowStatus||"",
		row_reflow_span:dpPanelA11yNumber(data.dpPanelA11yRowReflowSpan),
		row_reflow_source:data.dpPanelA11yRowReflowSource||"",
		dom_reflow_status:data.dpPanelA11yDomReflowStatus||""
	};
	var actions=[];
	var issues=[];
	if(item.width_status==="expanded"){actions.push("width_expanded");}
	if(item.width_status==="constrained"){issues.push("width_constrained");}
	if(item.contrast_status==="fail"){issues.push("contrast_fail");}
	if(item.touch_target_failures>0){issues.push("touch_target_fail");}
	if(item.adornment_status==="expanded"){actions.push("adornment_expanded");}
	if(item.adornment_status==="stacked"){actions.push("adornment_stacked");}
	if(item.adornment_status==="pressured"){issues.push("adornment_pressure");}
	if(item.label_status==="expanded"){actions.push("label_expanded");}
	if(item.label_status==="stacked"){actions.push("label_stacked");}
	if(item.row_reflow_status==="reflowed"){actions.push("row_reflowed");}
	if(item.dom_reflow_status==="moved"){actions.push("dom_reordered");}
	item.actions=actions;
	item.issues=issues;
	item.action_messages=dpPanelA11yTokenMessages(actions,item);
	item.issue_messages=dpPanelA11yTokenMessages(issues,item);
	if(field&&field.dataset){
		field.dataset.dpPanelA11yIssues=issues.join(" ");
		field.dataset.dpPanelA11yActions=actions.join(" ");
		field.dataset.dpPanelA11yIssueMessages=item.issue_messages.join(" | ");
		field.dataset.dpPanelA11yActionMessages=item.action_messages.join(" | ");
		field.dataset.dpPanelA11yIssueCount=String(issues.length);
		field.dataset.dpPanelA11yActionCount=String(actions.length);
		field.dataset.dpPanelA11yStatus=issues.length ? "needs_attention" : "pass";
	}
	dpPanelA11yUpdateFieldStatus(field,item);
	return item;
}
/**
 * Builds a diagnostic summary item for a flattened card wrapper.
 *
 * @param {HTMLElement} card Flattened card-like container.
 * @returns {Object} Card accessibility summary item.
 */
function dpPanelA11yCardSummary(card){
	var child=dpPanelA11yMeaningfulChildren(card)[0]||null;
	var label=(card.getAttribute("aria-label")||card.querySelector("h1,h2,h3,[data-dp-panel-card-title]")?.textContent||dpPanelText("client.accessibility_nested_card","Nested card wrapper")).replace(/\s+/g," ").trim();
	var item={
		name:"nested_card",
		label:label,
		width_status:"",
		usable_width:null,
		required_width:null,
		required_width_source:"",
		character_width:null,
		control_padding:null,
		usable_target:"surface",
		contrast_status:"",
		contrast_ratio:null,
		touch_target_status:"",
		touch_target_failures:0,
		adornment_status:"",
		adornment_ratio:null,
		adornment_width:null,
		label_status:"",
		label_ratio:null,
		label_width:null,
		row_reflow_status:"",
		row_reflow_span:null,
		row_reflow_source:"",
		dom_reflow_status:"",
		card_child:child ? (child.className||child.tagName.toLowerCase()) : ""
	};
	item.actions=["card_flattened"];
	item.issues=[];
	item.action_messages=dpPanelA11yTokenMessages(item.actions,item);
	item.issue_messages=[];
	return item;
}
/**
 * Builds a diagnostic summary item for a table column optimization result.
 *
 * @param {HTMLTableElement} table Optimized or user-preserved table.
 * @param {number} index Table index within the summary container.
 * @returns {Object} Table accessibility summary item.
 */
function dpPanelA11yTableSummary(table,index){
	var label=(table.getAttribute("aria-label")||table.closest(".dp-panel-relation,.dp-panel-page-table,.dp-panel-table-shell")?.querySelector("h1,h2,h3")?.textContent||dpPanelText("client.accessibility_table","Table")).replace(/\s+/g," ").trim();
	var compact=dpPanelA11yInteger(table.dataset.dpPanelA11yTableCompactColumns);
	var scroll=table.dataset.dpPanelA11yTableScrollPreserved==="1";
	var userPreserved=table.dataset.dpPanelUserColumnWidths==="1"||table.dataset.dpPanelA11yTableSkipped==="user_column_widths";
	var actions=[userPreserved ? "table_columns_user_preserved" : "table_columns_optimized"];
	if(compact>0){actions.push("table_columns_compacted");}
	if(scroll){actions.push("table_scroll_preserved");}
	var item={
		name:"table:"+(label||("table_"+(index+1))).toLowerCase().replace(/[^a-z0-9]+/g,"_").replace(/^_|_$/g,""),
		label:label,
		width_status:"",
		usable_width:dpPanelA11yNumber(table.dataset.dpPanelA11yTableAppliedWidth),
		required_width:dpPanelA11yNumber(table.dataset.dpPanelA11yTableDesiredWidth),
		required_width_source:"table_columns",
		character_width:null,
		control_padding:null,
		usable_target:"table",
		contrast_status:"",
		contrast_ratio:null,
		touch_target_status:"",
		touch_target_failures:0,
		adornment_status:"",
		adornment_ratio:null,
		adornment_width:null,
		label_status:"",
		label_ratio:null,
		label_width:null,
		row_reflow_status:"",
		row_reflow_span:null,
		row_reflow_source:"",
		dom_reflow_status:"",
		table_columns:dpPanelA11yInteger(table.dataset.dpPanelA11yTableColumnCount),
		table_available_width:dpPanelA11yNumber(table.dataset.dpPanelA11yTableAvailableWidth),
		table_desired_width:dpPanelA11yNumber(table.dataset.dpPanelA11yTableDesiredWidth),
		table_applied_width:dpPanelA11yNumber(table.dataset.dpPanelA11yTableAppliedWidth),
		table_compact_columns:compact,
		table_scroll_preserved:scroll,
		table_user_preserved:userPreserved
	};
	item.actions=actions;
	item.issues=[];
	item.action_messages=dpPanelA11yTokenMessages(item.actions,item);
	item.issue_messages=[];
	return item;
}
/**
 * Publishes aggregate accessibility policy diagnostics for a container.
 *
 * The summary is stored on dataset counters, cached on the element for consumers,
 * and emitted as a bubbling CustomEvent for Panel integrations.
 *
 * @param {HTMLElement|null} container Panel, form, or policy default container.
 * @returns {void}
 */
function dpPanelRefreshAccessibilityPolicySummary(container){
	if(!container||!container.querySelectorAll){return;}
	var fields=Array.from(container.querySelectorAll(".dp-panel-field")).filter(function(field){
		return field.dataset&&field.dataset.dpPanelA11yDisabled!=="1"&&(field.dataset.dpPanelA11yWidthStatus||field.dataset.dpPanelA11yContrastStatus||field.dataset.dpPanelA11yTouchTargetStatus||field.dataset.dpPanelA11yAdornmentStatus||field.dataset.dpPanelA11yLabelStatus);
	});
	var flattenedCards=Array.from(container.querySelectorAll("[data-dp-panel-a11y-card-flattened='1']"));
	var optimizedTables=Array.from(container.querySelectorAll(".dp-panel-table[data-dp-panel-a11y-table-optimized='1'],.dp-panel-table[data-dp-panel-user-column-widths='1']")).filter(function(table,index,array){return array.indexOf(table)===index;});
	var summary={checked:fields.length,expanded:0,constrained:0,contrast_failures:0,touch_target_failures:0,adornment_pressure:0,adornment_expanded:0,adornment_stacked:0,label_expanded:0,label_stacked:0,row_reflowed:0,card_flattened:0,table_columns_optimized:0,table_columns_user_preserved:0,table_columns_compacted:0,table_scroll_preserved:0,issue_count:0,adjustment_count:0,fields:[],issues:[],adjustments:[]};
	fields.forEach(function(field){
		var detail=dpPanelA11yFieldSummary(field);
		summary.fields.push(detail);
		if(detail.issues.length){
			summary.issue_count++;
			summary.issues.push(detail);
		}
		if(detail.actions.length){
			summary.adjustment_count++;
			summary.adjustments.push(detail);
		}
		if(field.dataset.dpPanelA11yWidthStatus==="expanded"){summary.expanded++;}
		if(field.dataset.dpPanelA11yWidthStatus==="constrained"){summary.constrained++;}
		if(field.dataset.dpPanelA11yContrastStatus==="fail"){summary.contrast_failures++;}
		summary.touch_target_failures+=parseInt(field.dataset.dpPanelA11yTouchTargetFailures||"0",10)||0;
		if(field.dataset.dpPanelA11yAdornmentStatus==="pressured"){summary.adornment_pressure++;}
		if(field.dataset.dpPanelA11yAdornmentStatus==="expanded"){summary.adornment_expanded++;}
		if(field.dataset.dpPanelA11yAdornmentStatus==="stacked"){summary.adornment_stacked++;}
		if(field.dataset.dpPanelA11yLabelStatus==="expanded"){summary.label_expanded++;}
		if(field.dataset.dpPanelA11yLabelStatus==="stacked"){summary.label_stacked++;}
		if(field.dataset.dpPanelA11yRowReflowStatus==="reflowed"){summary.row_reflowed++;}
	});
	flattenedCards.forEach(function(card){
		var detail=dpPanelA11yCardSummary(card);
		summary.fields.push(detail);
		summary.adjustments.push(detail);
		summary.adjustment_count++;
		summary.card_flattened++;
	});
	optimizedTables.forEach(function(table,index){
		var detail=dpPanelA11yTableSummary(table,index);
		summary.fields.push(detail);
		summary.adjustments.push(detail);
		summary.adjustment_count++;
		if(detail.table_user_preserved){summary.table_columns_user_preserved++;}
		else {summary.table_columns_optimized++;}
		if(detail.table_compact_columns>0){summary.table_columns_compacted++;}
		if(detail.table_scroll_preserved){summary.table_scroll_preserved++;}
	});
	summary.checked+=optimizedTables.length;
	container.dataset.dpPanelA11yChecked=String(summary.checked);
	container.dataset.dpPanelA11yExpanded=String(summary.expanded);
	container.dataset.dpPanelA11yConstrained=String(summary.constrained);
	container.dataset.dpPanelA11yContrastFailures=String(summary.contrast_failures);
	container.dataset.dpPanelA11yTouchTargetFailures=String(summary.touch_target_failures);
	container.dataset.dpPanelA11yAdornmentPressure=String(summary.adornment_pressure);
	container.dataset.dpPanelA11yAdornmentExpanded=String(summary.adornment_expanded);
	container.dataset.dpPanelA11yAdornmentStacked=String(summary.adornment_stacked);
	container.dataset.dpPanelA11yLabelExpanded=String(summary.label_expanded);
	container.dataset.dpPanelA11yLabelStacked=String(summary.label_stacked);
	container.dataset.dpPanelA11yRowReflowed=String(summary.row_reflowed);
	container.dataset.dpPanelA11yCardFlattened=String(summary.card_flattened);
	container.dataset.dpPanelA11yTableColumnsOptimized=String(summary.table_columns_optimized);
	container.dataset.dpPanelA11yTableColumnsUserPreserved=String(summary.table_columns_user_preserved);
	container.dataset.dpPanelA11yTableColumnsCompacted=String(summary.table_columns_compacted);
	container.dataset.dpPanelA11yTableScrollPreserved=String(summary.table_scroll_preserved);
	container.dataset.dpPanelA11yIssueCount=String(summary.issue_count);
	container.dataset.dpPanelA11yAdjustmentCount=String(summary.adjustment_count);
	summary.status=(summary.constrained||summary.contrast_failures||summary.touch_target_failures||summary.adornment_pressure) ? "needs_attention" : (summary.adjustment_count>0 ? "adjusted" : "pass");
	container.dataset.dpPanelA11yStatus=summary.status;
	container._dpPanelA11ySummary=summary;
	container.dispatchEvent(new CustomEvent("DataphyrePanelAccessibilityPolicy",{bubbles:true,detail:summary}));
}
/**
 * Initializes Panel client-side field enhancements under a root.
 *
 * This pass wires switch labels, masks, formatters, counters, swatches, sliders,
 * tags, key-value editors, searchable selects, auto-resizing textareas, rich
 * editors, uploaders, packed grids, mutation observers, and accessibility policy
 * evaluation for dynamic Panel content.
 *
 * @param {ParentNode|null} root Initialization scope, defaulting to document.
 * @returns {void}
 */
function dpPanelInitFieldEnhancements(root){
	(root||document).querySelectorAll("[data-dp-panel-switch]").forEach(function(switchControl){
		var checkbox=switchControl.querySelector("input[type='checkbox']");
		var label=switchControl.querySelector("[data-dp-panel-switch-label]");
		if(!checkbox||!label){return;}
		label.textContent=checkbox.checked ? (switchControl.dataset.dpPanelSwitchOn||"Enabled") : (switchControl.dataset.dpPanelSwitchOff||"Disabled");
	});
	(root||document).querySelectorAll("[data-dp-panel-mask],[data-dp-panel-format]").forEach(function(input){
		dpPanelRefreshFormatLocale(input);
		dpPanelApplyInputFormatting(input,"init");
		dpPanelPrimeFormatFromSource(input);
	});
	(root||document).querySelectorAll("[data-dp-panel-character-counter]").forEach(function(counter){
		var shell=counter.closest("[data-dp-panel-input-shell]");
		var input=shell ? shell.querySelector("input:not([type='hidden']),textarea,select") : null;
		if(input){dpPanelRefreshCharacterCounter(input);}
	});
	(root||document).querySelectorAll("[data-dp-panel-color-swatch]").forEach(function(swatch){
		var shell=swatch.closest("[data-dp-panel-input-shell]");
		var input=shell ? shell.querySelector("input:not([type='hidden']),textarea,select") : null;
		if(input){dpPanelRefreshColorSwatch(input);}
	});
	(root||document).querySelectorAll("[data-dp-panel-slider]").forEach(function(input){
		dpPanelRefreshSliderValue(input);
	});
	(root||document).querySelectorAll("[data-dp-panel-tags]").forEach(function(input){
		dpPanelRefreshTags(input);
	});
	(root||document).querySelectorAll("[data-dp-panel-key-value]").forEach(function(textarea){
		dpPanelRefreshKeyValue(textarea);
	});
	if(typeof dpPanelInitSearchableSelects==="function"){dpPanelInitSearchableSelects(root);}
	(root||document).querySelectorAll("textarea[data-dp-panel-auto-resize='1']").forEach(function(textarea){
		dpPanelAutoResizeTextarea(textarea);
	});
	if(typeof dpPanelInitRichEditors==="function"){dpPanelInitRichEditors(root);}
	if(typeof dpPanelInitUploaders==="function"){dpPanelInitUploaders(root);}
	dpPanelInitPackedGrids(root);
	dpPanelObserveAccessibilityPolicies(root);
	dpPanelObserveFieldMutations(root);
	dpPanelApplyAccessibilityPolicies(root);
}
/**
 * Moves a relation reorder item up or down among sibling items.
 *
 * @param {HTMLElement} button Reorder control inside the relation item.
 * @param {number} direction Negative for up, positive for down.
 * @returns {void}
 */
function dpPanelMoveRelationReorderItem(button,direction){
	var item=button.closest("[data-dp-panel-relation-reorder-item]");
	if(!item){return;}
	if(direction<0&&item.previousElementSibling){
		item.parentNode.insertBefore(item,item.previousElementSibling);
	}
	else if(direction>0&&item.nextElementSibling){
		item.parentNode.insertBefore(item.nextElementSibling,item);
	}
}
dpPanelListen(document,"input",function(event){
	var input=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-mask],[data-dp-panel-format]") : null;
	if(input){dpPanelApplyInputFormatting(input,"input");}
	var source=event.target&&event.target.closest ? event.target.closest("input,select,textarea") : null;
	if(source){
		dpPanelRefreshCharacterCounter(source);
		dpPanelRefreshColorSwatch(source);
		dpPanelRefreshSliderValue(source);
		dpPanelRefreshTags(source);
		dpPanelRefreshKeyValue(source);
		dpPanelAutoResizeTextarea(source);
		dpPanelRefreshLocaleFormatsForSource(source);
		dpPanelScheduleAccessibilityInputRefresh(document);
	}
});
dpPanelListen(document,"change",function(event){
	var input=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-mask],[data-dp-panel-format]") : null;
	if(input){dpPanelApplyInputFormatting(input,"change");}
	var source=event.target&&event.target.closest ? event.target.closest("input,select,textarea") : null;
	if(source){
		var switchControl=source.closest("[data-dp-panel-switch]");
		if(switchControl){
			var switchLabel=switchControl.querySelector("[data-dp-panel-switch-label]");
			if(switchLabel){
				switchLabel.textContent=source.checked ? (switchControl.dataset.dpPanelSwitchOn||"Enabled") : (switchControl.dataset.dpPanelSwitchOff||"Disabled");
			}
		}
		dpPanelRefreshCharacterCounter(source);
		dpPanelRefreshColorSwatch(source);
		dpPanelRefreshSliderValue(source);
		dpPanelRefreshTags(source);
		dpPanelRefreshKeyValue(source);
		dpPanelAutoResizeTextarea(source);
		dpPanelRefreshLocaleFormatsForSource(source);
		dpPanelScheduleAccessibilityPolicyRefresh(document,{preserveAdaptive:dpPanelA11yUserIsEditing()});
	}
});
dpPanelListen(window,"resize",function(){
	dpPanelScheduleAccessibilityPolicyRefresh(document);
	document.querySelectorAll(".dp-panel[data-dp-panel-kind='board'] .dp-panel-board[data-dp-panel-packed-grid]").forEach(dpPanelSchedulePackedGrid);
});
dpPanelListen(document,"keydown",function(event){
	var input=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-tags]") : null;
	if(!input||event.key!=="Enter"){return;}
	event.preventDefault();
	dpPanelNormalizeTagsInput(input);
});
dpPanelListen(document,"invalid",function(event){
	var input=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-mask],[data-dp-panel-format]") : null;
	if(input){dpPanelRefreshPatternValidity(input);}
},true);
dpPanelListen(document,"focusout",function(event){
	var input=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-mask],[data-dp-panel-format]") : null;
	if(input){dpPanelApplyInputFormatting(input,"blur");}
	var tags=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-tags]") : null;
	if(tags){dpPanelNormalizeTagsInput(tags);}
	var keyValue=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-key-value]") : null;
	if(keyValue){dpPanelNormalizeKeyValueInput(keyValue);}
	if(dpPanelA11yDeferredFullRefresh){
		setTimeout(function(){
			if(dpPanelA11yUserIsEditing()){return;}
			dpPanelA11yDeferredFullRefresh=false;
			dpPanelScheduleAccessibilityPolicyRefresh(document,{preserveAdaptive:true});
		},90);
	}
});
dpPanelListen(document,"paste",function(event){
	var input=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-mask],[data-dp-panel-format]") : null;
	if(!input||input.readOnly||input.disabled){return;}
	var clipboard=event.clipboardData||window.clipboardData;
	if(!clipboard||typeof clipboard.getData!=="function"){return;}
	var text=clipboard.getData("text");
	if(text===""){return;}
	event.preventDefault();
	dpPanelApplyPastedValue(input,text);
});
dpPanelListen(document,"submit",function(event){
	var form=event.target&&event.target.closest ? event.target.closest("form") : null;
	if(!form){return;}
	form.querySelectorAll("[data-dp-panel-editor]").forEach(dpPanelEditorCommit);
	var uploading=form.querySelector("[data-dp-panel-uploader-tone='queued'],[data-dp-panel-uploader-tone='uploading'],[data-dp-panel-uploader-tone='retrying']");
	if(uploading){
		event.preventDefault();
		var uploader=uploading.closest("[data-dp-panel-uploader]");
		if(uploader){dpPanelUploaderSetStatus(uploader,dpPanelUploaderText(uploader,"wait_uploads",dpPanelText("client.wait_uploads","Wait for uploads to finish before saving.")),"error");}
		return;
	}
	var invalidUploader=Array.prototype.slice.call(form.querySelectorAll("[data-dp-panel-uploader='1']")).find(function(uploader){
		return !dpPanelUploaderRefreshConstraints(uploader,true);
	});
	if(invalidUploader){
		event.preventDefault();
		invalidUploader.scrollIntoView({block:"center",behavior:"smooth"});
		return;
	}
	form.querySelectorAll("[data-dp-panel-mask],[data-dp-panel-format]").forEach(function(input){
		dpPanelApplyInputFormatting(input,"submit");
	});
});
dpPanelListen(document,"formdata",function(event){
	if(event.target&&event.target.querySelectorAll){
		event.target.querySelectorAll("[data-dp-panel-editor]").forEach(dpPanelEditorCommit);
	}
	dpPanelNormalizeFormData(event.target,event.formData);
});
dpPanelListen(document,"mousedown",function(event){
	var editorButton=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-editor-command]") : null;
	if(editorButton){event.preventDefault();}
});
dpPanelListen(document,"selectionchange",function(){
	var node=window.getSelection&&window.getSelection().anchorNode ? window.getSelection().anchorNode : null;
	if(node&&node.nodeType===3){node=node.parentElement;}
	var editor=node&&node.closest ? node.closest("[data-dp-panel-editor]") : null;
	if(editor){dpPanelEditorRefreshCommandState(editor);}
});
dpPanelListen(document,"click",function(event){
	if(!(event.target&&event.target.closest&&event.target.closest("[data-dp-panel-a11y-dev-badge],[data-dp-panel-a11y-dev-popup]"))){
		dpPanelA11yCloseDevPopups();
	}
	var editorButton=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-editor-command],[data-dp-panel-editor-view]") : null;
	if(editorButton){
		var editor=editorButton.closest("[data-dp-panel-editor]");
		if(!editor){return;}
		event.preventDefault();
		if(editorButton.dataset.dpPanelEditorView){
			dpPanelSetEditorMode(editor,editorButton.dataset.dpPanelEditorView);
			return;
		}
		var command=editorButton.dataset.dpPanelEditorCommand;
		if(typeof dpPanelEditorDispatchCommand==="function"&&!dpPanelEditorDispatchCommand(editor,editorButton,command)){return;}
		var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase();
		var source=editor.querySelector("[data-dp-panel-editor-source]");
		if(editor.dataset.dpPanelEditorMode==="preview"){dpPanelSetEditorMode(editor,"write");}
		if(editor.dataset.dpPanelEditorMode==="write"&&editor.querySelector("[data-dp-panel-editor-visual]")){
			dpPanelEditorApplyVisualCommand(editor,command);
		}
		else if(source){
			dpPanelEditorApplySourceCommand(source,mode,command);
			dpPanelEditorRenderPreview(editor);
			dpPanelEditorRefreshCommandState(editor);
		}
		return;
	}
	var up=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-relation-reorder-up]") : null;
	if(up){
		event.preventDefault();
		dpPanelMoveRelationReorderItem(up,-1);
		return;
	}
	var down=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-relation-reorder-down]") : null;
	if(down){
		event.preventDefault();
		dpPanelMoveRelationReorderItem(down,1);
		return;
	}
	var button=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-field-button]") : null;
	if(!button){return;}
	event.preventDefault();
	dpPanelRunFieldButton(button);
});
dpPanelListen(document,"DOMContentLoaded",function(){
	dpPanelRememberVisit();
	dpPanelRefreshDependencies();
	dpPanelInitFieldEnhancements();
	dpPanelRefreshLiveControls();
	dpPanelInitTabs();
	dpPanelInitSteps();
	dpPanelInitRepeaters();
	dpPanelRefreshPanelUi();
	dpPanelBootNotifications();
	dpPanelAjaxScheduleLiveRefresh();
	dpPanelScheduleRegionRefreshes();
	dpPanelLoadLazyRefreshRegions();
});
JS;
	}

}
