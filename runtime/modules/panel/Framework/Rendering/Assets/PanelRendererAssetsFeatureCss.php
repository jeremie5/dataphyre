<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits feature-specific Panel styles layered on top of core CSS.
 *
 * Feature CSS covers authentication, relation, record, table, and package
 * surfaces that are only needed by specific Panel views.
 */
trait PanelRendererAssetsFeatureCss {
	/**
	 * Supplies authentication page styles for panel login and account flows.
	 *
	 * this asset owns the standalone auth card surface, form spacing,
	 * remember/check controls, recovery links, and dark/glass/brutalist theme
	 * adaptations before an operator has entered the main panel shell.
	 */
	private static function authCss(): string {
		return <<<'CSS'
.dp-panel-auth-card{
	display:grid;
	gap:18px;
	width:min(100%,520px);
	margin:clamp(24px,5vw,72px) auto;
	border:1px solid var(--dp-border);
	border-radius:calc(var(--dp-radius) + 4px);
	background:var(--dp-surface);
	color:var(--dp-text);
	padding:clamp(18px,2.2vw,28px);
	box-shadow:0 22px 60px rgba(15,23,42,.12);
}
.dp-panel-auth-card .dp-panel-heading-row{
	display:grid!important;
	gap:5px;
	align-items:start!important;
	padding:0!important;
	background:transparent!important;
}
.dp-panel-auth-card h2{
	margin:0;
	font-size:clamp(24px,3vw,34px);
	line-height:1.05;
	letter-spacing:0;
}
.dp-panel-auth-card p{
	margin:0;
	color:var(--dp-text_muted);
	font-weight:720;
	line-height:1.45;
}
.dp-panel-auth-form{
	display:grid;
	gap:14px;
}
.dp-panel-auth-form .dp-panel-field{
	margin:0;
}
.dp-panel-auth-form .dp-panel-button{
	width:100%;
	min-height:46px;
	justify-content:center;
}
.dp-panel-auth-check{
	display:flex;
	align-items:center;
	gap:10px;
	min-height:42px;
	border:1px solid var(--dp-border_soft);
	border-radius:14px;
	background:color-mix(in srgb,var(--dp-surface_muted) 72%,var(--dp-surface));
	padding:10px 12px;
	color:var(--dp-text);
	font-weight:820;
}
.dp-panel-auth-check input{
	width:18px;
	height:18px;
	accent-color:var(--dp-primary-600);
}
.dp-panel-auth-links{
	display:flex;
	flex-wrap:wrap;
	gap:9px;
	justify-content:center;
	padding-top:2px;
}
.dp-panel-auth-links a{
	display:inline-flex;
	align-items:center;
	justify-content:center;
	min-height:34px;
	border:1px solid var(--dp-border_soft);
	border-radius:999px;
	background:var(--dp-surface_muted);
	color:var(--dp-primary-700);
	padding:7px 11px;
	font-size:12px;
	font-weight:850;
	text-decoration:none;
}
.dp-panel-auth-links a:hover{
	border-color:color-mix(in srgb,var(--dp-primary-600) 35%,var(--dp-border));
	background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 72%,var(--dp-surface));
	text-decoration:none;
}
.dp-panel-auth-card .dp-panel-notice{
	margin:0;
}
body[data-dp-theme-mode="dark"] .dp-panel-auth-card{
	background:#151f2e;
	border-color:#2c3a4f;
	box-shadow:0 24px 70px rgba(0,0,0,.34);
}
body[data-dp-theme-mode="dark"] .dp-panel-auth-check,
body[data-dp-theme-mode="dark"] .dp-panel-auth-links a{
	background:#111827;
	border-color:#34445d;
}
body[data-dp-theme-mode="dark"] .dp-panel-auth-links a{
	color:#b9d3ff;
}
body[data-dp-theme-effects~="glass"] .dp-panel-auth-card{
	background:var(--dp-glass_surface_bg)!important;
	border-color:var(--dp-glass_border)!important;
	box-shadow:var(--dp-glass_shadow)!important;
	backdrop-filter:var(--dp-glass_blur)!important;
	-webkit-backdrop-filter:var(--dp-glass_blur)!important;
}
body[data-dp-theme-effects~="brutalist"] .dp-panel-auth-card,
body[data-dp-theme-effects~="brutalist"] .dp-panel-auth-check,
body[data-dp-theme-effects~="brutalist"] .dp-panel-auth-links a{
	border-radius:0!important;
	box-shadow:5px 5px 0 var(--dp-brutalist-shadow,#111)!important;
}
@media(max-width:640px){
	.dp-panel-auth-card{
		margin:12px auto;
		padding:16px;
		border-radius:18px;
	}
	.dp-panel-auth-card h2{
		font-size:24px;
	}
	.dp-panel-auth-links{
		display:grid;
		grid-template-columns:1fr;
	}
}
CSS;
	}

	/**
	 * Supplies legacy attachment list and upload form styles.
	 *
	 * attachment markup can include file links, author/time/type/size
	 * metadata, and a guarded multipart upload form. This compact asset keeps older
	 * record attachment surfaces readable when component CSS is not the active skin.
	 */
	private static function attachmentsCss(): string {
		return '.dp-panel-attachments{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-attachments>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-attachments>header h2{margin:0;font-size:16px}.dp-panel-attachments>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-attachment-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;padding:12px}.dp-panel-attachment{display:grid;gap:6px;border:1px solid #e7ecf2;border-radius:8px;background:#f8fafc;padding:11px}.dp-panel-attachment strong,.dp-panel-attachment a{color:#18202a;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.dp-panel-attachment a:hover{color:#1f6feb;text-decoration:underline}.dp-panel-attachment small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-attachment small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-attachment-form{display:grid;gap:10px;padding:13px 14px;border-top:1px solid #e7ecf2;background:#f8fafc}.dp-panel-attachment-form label{display:grid;gap:6px}.dp-panel-attachment-form span{font-weight:700}.dp-panel-attachment-form input[type=file]{border:1px solid #cad3df;border-radius:6px;padding:9px;background:#fff;color:#18202a}.dp-panel-attachment-form button{justify-self:start}';
	}

	/**
	 * Supplies keyboard, refresh, lazy-loading, shortcut, preview, and relation tools styles.
	 *
	 * this feature asset binds runtime row focus, column resizing,
	 * refresh state, lazy placeholders, shortcut references, row previews, and
	 * relation reorder/pivot controls to generated data attributes and classes.
	 */
	private static function tableKeyboardCss(): string {
		return <<<'CSS'
.dp-panel-table [data-dp-panel-row]{outline:0}.dp-panel-table [data-dp-panel-row]:focus-visible td,.dp-panel-table .dp-panel-row-focused td{background:color-mix(in srgb,var(--dp-primary-50,#eff6ff) 62%,var(--dp-surface))!important;box-shadow:inset 0 1px 0 color-mix(in srgb,var(--dp-primary-600,#2563eb) 14%,transparent),inset 0 -1px 0 color-mix(in srgb,var(--dp-primary-600,#2563eb) 14%,transparent)}.dp-panel-table [data-dp-panel-row]:focus-visible td:first-child,.dp-panel-table .dp-panel-row-focused td:first-child{border-left-color:var(--dp-primary-600,#2563eb)!important;box-shadow:inset 3px 0 0 var(--dp-primary-600,#2563eb)}.dp-panel-table [data-dp-panel-row]{cursor:default}.dp-panel-table [data-dp-panel-row][data-dp-panel-row-url],.dp-panel-table [data-dp-panel-row]:has(.dp-panel-row-link[href],.dp-panel-cell-link[href]){cursor:pointer}.dp-panel-table [data-dp-panel-row] :is(a[href],button,summary,[role="button"],[role="link"],.dp-panel-action,.dp-panel-row-link,.dp-panel-cell-copy,[data-dp-panel-copy-entry]){cursor:pointer!important}.dp-panel-table [data-dp-panel-row] input[type="checkbox"],.dp-panel-table [data-dp-panel-row] input[type="radio"],.dp-panel-table [data-dp-panel-row] select{cursor:pointer!important}.dp-panel-table [data-dp-panel-row] input:not([type="checkbox"]):not([type="radio"]),.dp-panel-table [data-dp-panel-row] textarea{cursor:text}.dp-panel-column-resizer{position:absolute;top:0;right:0;bottom:0;z-index:9;width:10px;cursor:col-resize;touch-action:none}.dp-panel-column-resizer:after{content:"";position:absolute;top:9px;right:3px;bottom:9px;width:2px;border-radius:999px;background:transparent;transition:background .12s ease,box-shadow .12s ease}.dp-panel-table th:hover>.dp-panel-column-resizer:after,.dp-panel-column-resizer:hover:after,body.dp-panel-column-resizing .dp-panel-column-resizer:after{background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 72%,transparent);box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#2563eb) 10%,transparent)}body.dp-panel-column-resizing{cursor:col-resize!important;user-select:none}body.dp-panel-column-resizing *{cursor:col-resize!important}body[data-dp-theme-mode="dark"] .dp-panel-table [data-dp-panel-row]:focus-visible td,body[data-dp-theme-mode="dark"] .dp-panel-table .dp-panel-row-focused td{background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,var(--dp-surface))!important}@media(max-width:900px){.dp-panel-table [data-dp-panel-row]:focus-visible,.dp-panel-table .dp-panel-row-focused{box-shadow:0 0 0 3px color-mix(in srgb,var(--dp-primary-600,#2563eb) 22%,transparent)!important}.dp-panel-column-resizer{display:none}}
.dp-panel-table .dp-panel-row-focused .dp-panel-actions:before{content:none!important;display:none!important}body[data-dp-theme-mode="dark"] .dp-panel-table .dp-panel-row-focused .dp-panel-actions:before{content:none!important;display:none!important}@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-table [data-dp-panel-row]:focus-visible td,body[data-dp-theme-mode="system"] .dp-panel-table .dp-panel-row-focused td{background:color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,var(--dp-surface))!important}body[data-dp-theme-mode="system"] .dp-panel-table .dp-panel-row-focused .dp-panel-actions:before{content:none!important;display:none!important}}@media(max-width:900px){.dp-panel-table .dp-panel-row-focused .dp-panel-actions:before{content:none!important;display:none!important}}
.dp-panel-refresh-controls{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}.dp-panel-refresh-controls>[data-dp-panel-refresh-status]{display:inline-flex;align-items:center;min-height:32px;border:1px solid var(--dp-border_soft,rgba(226,232,240,.9));border-radius:999px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-text_muted,#667085);padding:5px 10px;font-size:11px;font-weight:900}.dp-panel-refresh-controls[data-dp-panel-refresh-tone=syncing]>[data-dp-panel-refresh-status]{color:var(--dp-info-700,#026aa2)}.dp-panel-refresh-controls[data-dp-panel-refresh-tone=success]>[data-dp-panel-refresh-status]{color:var(--dp-success-700,#067647)}.dp-panel-refresh-controls[data-dp-panel-refresh-tone=error]>[data-dp-panel-refresh-status]{color:var(--dp-danger-700,#b42318)}.dp-panel-refresh-controls-paused>[data-dp-panel-refresh-status]{background:var(--dp-warning-100,#fef0c7);color:var(--dp-warning-800,#93370d)}@media(max-width:760px){.dp-panel-refresh-controls{width:100%}}
.dp-panel-lazy-placeholder{position:relative;min-height:180px;overflow:hidden}.dp-panel-lazy-placeholder h2,.dp-panel-lazy-placeholder p,.dp-panel-lazy-placeholder .dp-panel-button{position:relative;z-index:1}.dp-panel-lazy-placeholder p{color:var(--dp-text_muted);font-weight:750}.dp-panel-lazy-placeholder .dp-panel-button{justify-self:start}.dp-panel-lazy-shimmer{position:absolute;inset:0;background:linear-gradient(110deg,transparent 0,color-mix(in srgb,var(--dp-primary-50,#eff6ff) 58%,transparent) 42%,transparent 74%);animation:dp-panel-lazy-shimmer 1.6s ease-in-out infinite;opacity:.88}@keyframes dp-panel-lazy-shimmer{0%{transform:translateX(-64%)}100%{transform:translateX(64%)}}body[data-dp-theme-mode="dark"] .dp-panel-lazy-shimmer{background:linear-gradient(110deg,transparent 0,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent) 42%,transparent 74%)}@media(prefers-color-scheme:dark){body[data-dp-theme-mode="system"] .dp-panel-lazy-shimmer{background:linear-gradient(110deg,transparent 0,color-mix(in srgb,var(--dp-primary-600,#2563eb) 18%,transparent) 42%,transparent 74%)}}
.dp-panel-shortcuts{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.dp-panel-shortcut-group{display:grid;gap:10px;border:1px solid var(--dp-border_soft);border-radius:14px;background:var(--dp-surface);padding:14px}.dp-panel-shortcut-group h3{margin:0;font-size:13px;color:var(--dp-text);font-weight:900}.dp-panel-shortcut-group dl{display:grid;gap:7px;margin:0}.dp-panel-shortcut-group div{display:grid;grid-template-columns:minmax(96px,auto) minmax(0,1fr);gap:10px;align-items:center}.dp-panel-shortcut-group dt{display:inline-flex;align-items:center;justify-content:center;min-height:28px;border:1px solid var(--dp-border);border-radius:9px;background:var(--dp-neutral_bg);color:var(--dp-neutral_text);padding:4px 8px;font-size:12px;font-weight:900;white-space:nowrap}.dp-panel-shortcut-group dd{margin:0;color:var(--dp-text_muted);font-size:13px;font-weight:750}.dp-panel-row-preview{display:grid;gap:12px}.dp-panel-row-preview-nav{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;margin-bottom:2px}.dp-panel-row-preview-nav span{justify-self:center;color:var(--dp-text_muted);font-size:12px;font-weight:900}.dp-panel-row-preview-nav button[disabled]{opacity:.45;pointer-events:none}.dp-panel-row-preview-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.dp-panel-row-preview-tools .dp-panel-button{min-height:34px!important}.dp-panel-row-preview dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin:0}.dp-panel-row-preview dl div{display:grid;gap:5px;border:1px solid var(--dp-border_soft);border-radius:13px;background:var(--dp-surface);padding:11px}.dp-panel-row-preview dt{color:var(--dp-text_muted);font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.dp-panel-row-preview dd{margin:0;color:var(--dp-text);font-size:14px;font-weight:760;overflow-wrap:anywhere}.dp-panel-row-preview-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid var(--dp-border_soft)}.dp-panel-row-preview-actions .dp-panel-inline-action{display:inline-flex}.dp-panel-row-preview-actions .dp-panel-row-more{display:inline-grid}.dp-panel-row-preview-actions .dp-panel-action,.dp-panel-row-preview-actions .dp-panel-row-link{min-height:38px!important}@media(max-width:560px){.dp-panel-row-preview-nav{grid-template-columns:1fr}.dp-panel-row-preview-nav span{order:-1}}
.dp-panel-relation-reorder-form{display:grid;gap:12px}.dp-panel-relation-reorder-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.dp-panel-relation-reorder-item{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:center;border:1px solid var(--dp-border_soft);border-radius:8px;background:var(--dp-surface_muted);padding:9px}.dp-panel-relation-reorder-item span{min-width:0;color:var(--dp-text);font-weight:850;overflow-wrap:anywhere}.dp-panel-relation-pivot-form .dp-panel-form-section{margin:0}.dp-panel-actions .dp-panel-inline-action{display:inline-flex}.dp-panel-actions .dp-panel-action-neutral{border-color:var(--dp-border_soft);background:var(--dp-surface_muted);color:var(--dp-text)}@media(max-width:560px){.dp-panel-relation-reorder-item{grid-template-columns:1fr 1fr}.dp-panel-relation-reorder-item span{grid-column:1/-1}}
CSS;
	}

	/**
	 * Supplies legacy message history and send-message form styles.
	 *
	 * messages can render status tones, channel/sender/recipient/time
	 * metadata, and an ability-gated send form. This asset provides the older
	 * compact presentation used outside the newer component CSS bundle.
	 */
	private static function messagesCss(): string {
		return '.dp-panel-messages{display:grid;gap:0;margin:18px 0;border:1px solid #d9e0ea;border-radius:8px;background:#fff;overflow:hidden}.dp-panel-messages>header{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;padding:13px 14px;border-bottom:1px solid #e7ecf2}.dp-panel-messages>header h2{margin:0;font-size:16px}.dp-panel-messages>header span{color:#667085;font-size:12px;font-weight:700}.dp-panel-message-list{display:grid;gap:0}.dp-panel-message{display:grid;gap:6px;padding:13px 14px;border-left:3px solid #98a2b3}.dp-panel-message+.dp-panel-message{border-top:1px solid #f0f3f7}.dp-panel-message-primary{border-left-color:#1f6feb}.dp-panel-message-success{border-left-color:#079455}.dp-panel-message-warning{border-left-color:#dc6803}.dp-panel-message-danger{border-left-color:#d92d20}.dp-panel-message-info{border-left-color:#026aa2}.dp-panel-message strong{color:#18202a}.dp-panel-message p{margin:0;color:#344054;white-space:pre-wrap;overflow-wrap:anywhere}.dp-panel-message small{display:flex;flex-wrap:wrap;gap:7px;color:#667085}.dp-panel-message small span+span:before{content:"";display:inline-block;width:4px;height:4px;margin:0 7px 2px 0;border-radius:999px;background:#cad3df}.dp-panel-message-form{display:grid;gap:10px;padding:13px 14px;border-top:1px solid #e7ecf2;background:#f8fafc}.dp-panel-message-form label{display:grid;gap:6px}.dp-panel-message-form span{font-weight:700}.dp-panel-message-form input,.dp-panel-message-form select,.dp-panel-message-form textarea{border:1px solid #cad3df;border-radius:6px;padding:10px;font-size:14px;background:#fff;color:#18202a}.dp-panel-message-form textarea{resize:vertical}.dp-panel-message-form-row{display:grid;grid-template-columns:180px minmax(0,1fr);gap:10px}.dp-panel-message-form button{justify-self:start}@media(max-width:760px){.dp-panel-message-form-row{grid-template-columns:1fr}}';
	}

	/**
	 * Supplies the panel modal JavaScript runtime.
	 *
	 * the script manages focus restoration, dirty-form detection,
	 * confirmation dialogs, partial fetches, Ajax fragment application, modal
	 * history stacks, form submission, filter templates, and safe fallback to full
	 * page navigation when modal or Ajax contracts cannot be satisfied.
	 */
	private static function modalScript(): string {
		return <<<'JS'
var dpPanelModalLastFocus=null;
var dpPanelModalDiscardArmedUntil=0;
var dpPanelModalStack=[];
var dpPanelModalTriggerCounter=0;
/**
 * Escapes modal-injected text for HTML fragments.
 *
 * protects labels, translated copy, and confirmation text before they
 * are interpolated into string-built modal markup.
 */
function dpPanelEscape(value){
	return String(value||"").replace(/[&<>]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;"}[c];});
}
/**
 * Captures a comparable value for modal form controls.
 *
 * dirty-form checks ignore inert controls, normalize checkbox/radio
 * state, summarize files by count, and compare all other controls as strings.
 */
function dpPanelModalControlValue(control){
	if(!control||control.disabled||control.type==="button"||control.type==="submit"||control.type==="reset"){return "";}
	if(control.type==="checkbox"||control.type==="radio"){return control.checked?"1":"0";}
	if(control.type==="file"){return control.files&&control.files.length ? "files:"+control.files.length : "";}
	return String(control.value||"");
}
/**
 * Stores initial modal form values for dirty-state detection.
 *
 * baselines are written to control data attributes when a fetched form is
 * prepared, allowing later discard checks to stay independent from server state.
 */
function dpPanelRememberModalFormState(scope){
	if(!scope){return;}
	scope.querySelectorAll("form input,form select,form textarea").forEach(function(control){
		control.dataset.dpPanelModalInitial=dpPanelModalControlValue(control);
	});
}
/**
 * Detects unsaved changes inside the active modal form.
 *
 * only visible modal content is inspected, and controls without a stored
 * baseline are ignored so freshly injected widgets do not produce false warnings.
 */
function dpPanelModalHasDirtyForm(){
	var root=document.querySelector(".dp-panel-modal-root");
	if(!root||root.hidden){return false;}
	var dirty=false;
	root.querySelectorAll(".dp-panel-modal-body form input,.dp-panel-modal-body form select,.dp-panel-modal-body form textarea").forEach(function(control){
		if(dirty||control.dataset.dpPanelModalInitial===undefined){return;}
		if(dpPanelModalControlValue(control)!==control.dataset.dpPanelModalInitial){dirty=true;}
	});
	return dirty;
}
/**
 * Requests modal closure with busy and dirty-form safeguards.
 *
 * busy modals refuse closure, dirty forms require a second close inside
 * a discard window, and accepted closes route through the shared close routine.
 */
function dpPanelRequestCloseModal(force){
	var root=document.querySelector(".dp-panel-modal-root");
	if(!root){return true;}
	if(root.classList.contains("dp-panel-modal-busy")){return false;}
	if(!force&&dpPanelModalHasDirtyForm()&&Date.now()>dpPanelModalDiscardArmedUntil){
		dpPanelModalDiscardArmedUntil=Date.now()+3200;
		root.classList.add("dp-panel-modal-discard-warn");
		if(typeof dpPanelToast==="function"){dpPanelToast(typeof dpPanelText==="function" ? dpPanelText("modal.unsaved_close","Unsaved modal changes. Close again to discard.") : "Unsaved modal changes. Close again to discard.","warning");}
		setTimeout(function(){root.classList.remove("dp-panel-modal-discard-warn");},340);
		return false;
	}
	dpPanelCloseModal();
	return true;
}
/**
 * Creates or returns the singleton modal root element.
 *
 * the root owns dialog ARIA wiring, header actions, backdrop behavior,
 * expand controls, copy/open-full controls, and reusable event listeners for all
 * modal actions.
 */
function dpPanelModalRoot(){
	var root=document.querySelector(".dp-panel-modal-root");
	if(root){return root;}
	root=document.createElement("div");
	root.className="dp-panel-modal-root";
	root.hidden=true;
	root.innerHTML="<section class=\"dp-panel-modal dp-panel-modal-md\" role=\"dialog\" aria-modal=\"true\" aria-labelledby=\"dp-panel-modal-title\" aria-describedby=\"dp-panel-modal-description\"><header class=\"dp-panel-modal-header\"><div class=\"dp-panel-modal-title\"><h2 id=\"dp-panel-modal-title\"></h2><p id=\"dp-panel-modal-description\"></p></div><div class=\"dp-panel-modal-header-actions\"><button class=\"dp-panel-modal-back\" type=\"button\" hidden>"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("modal.back","Back") : "Back")+"</button><a class=\"dp-panel-modal-open-full\" href=\"#\" hidden>"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("modal.open_full_page","Open full page") : "Open full page")+"</a><button class=\"dp-panel-modal-copy-link\" type=\"button\" hidden>"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("modal.copy_link","Copy link") : "Copy link")+"</button><button class=\"dp-panel-modal-refresh\" type=\"button\" hidden>"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("common.refresh","Refresh") : "Refresh")+"</button><button class=\"dp-panel-modal-expand\" type=\"button\" aria-pressed=\"false\">"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand")+"</button><button class=\"dp-panel-modal-close\" type=\"button\" aria-label=\""+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("common.close","Close") : "Close")+"\">&times;</button></div></header><div class=\"dp-panel-modal-body\"></div></section>";
	document.body.appendChild(root);
	root.addEventListener("click",function(event){
		if(root.classList.contains("dp-panel-modal-busy")){return;}
		if(event.target.closest(".dp-panel-modal-back")){
			dpPanelRequestBackModal(false);
			return;
		}
		if(event.target.closest(".dp-panel-modal-copy-link")){
			var href=root.dataset.dpPanelModalUrl||"";
			if(href){dpPanelCopyText(href,typeof dpPanelText==="function" ? dpPanelText("modal.link_copied","Modal link copied") : "Modal link copied");}
			return;
		}
		if(event.target.closest(".dp-panel-modal-refresh")){
			if(dpPanelModalLastFocus){dpPanelFetchAction(dpPanelModalLastFocus);}
			return;
		}
		if(event.target.closest(".dp-panel-modal-expand")){
			var expanded=root.dataset.dpPanelModalExpanded==="1";
			root.dataset.dpPanelModalExpanded=expanded?"0":"1";
			var button=root.querySelector(".dp-panel-modal-expand");
			if(button){
				button.setAttribute("aria-pressed",expanded?"false":"true");
				button.textContent=expanded?(typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand"):(typeof dpPanelText==="function" ? dpPanelText("modal.normal","Normal") : "Normal");
			}
			dpPanelUpdateModalScrollState(root.querySelector(".dp-panel-modal-body"));
			return;
		}
		if(event.target===root||event.target.closest(".dp-panel-modal-close")){
			dpPanelRequestCloseModal(false);
		}
	});
	return root;
}
/**
 * Assigns a stable token to a modal trigger element.
 *
 * trigger tokens let modal history snapshots reconnect with their
 * originating action without depending on DOM identity after Ajax refreshes.
 */
function dpPanelModalTriggerToken(trigger){
	if(!trigger){return "";}
	if(!trigger.dataset.dpPanelModalTriggerToken){
		dpPanelModalTriggerCounter+=1;
		trigger.dataset.dpPanelModalTriggerToken="modal-trigger-"+dpPanelModalTriggerCounter;
	}
	return trigger.dataset.dpPanelModalTriggerToken;
}
/**
 * Reports whether a trigger opts into modal back-stack behavior.
 *
 * the data attribute is the declarative contract for nested modal flows
 * that should preserve prior content instead of replacing it outright.
 */
function dpPanelModalBackAllowed(trigger){
	return trigger&&trigger.dataset&&trigger.dataset.dpPanelModalBack==="1";
}
/**
 * Resolves the stack strategy requested by a modal trigger.
 *
 * trigger data can request push, replace, or clear behavior; otherwise
 * back-enabled triggers push and ordinary modal actions replace the current body.
 */
function dpPanelModalStackStrategy(trigger){
	if(!trigger||!trigger.dataset){return "replace";}
	var strategy=String(trigger.dataset.dpPanelModalStack||"").toLowerCase().replace(/[^a-z_]/g,"");
	if(["push","replace","clear"].indexOf(strategy)!==-1){return strategy;}
	return dpPanelModalBackAllowed(trigger) ? "push" : "replace";
}
/**
 * Resolves whether the current panel permits modal expansion.
 *
 * panel-level data attributes reduce expand behavior to always, never,
 * or surface-only, keeping expansion policy declarative at the panel boundary.
 */
function dpPanelModalExpandMode(trigger){
	var panel=trigger&&trigger.closest ? trigger.closest(".dp-panel[data-dp-panel-modal-expand]") : null;
	var mode=panel&&panel.dataset ? String(panel.dataset.dpPanelModalExpand||"always").toLowerCase().replace(/[^a-z_]/g,"") : "always";
	return ["always","never","surface"].indexOf(mode)!==-1 ? mode : "always";
}
/**
 * Reports whether a specific modal action is enabled by the panel.
 *
 * panels can whitelist modal affordances such as expand; an empty
 * whitelist disables optional actions while preserving the basic dialog contract.
 */
function dpPanelModalActionEnabled(trigger,action){
	var panel=trigger&&trigger.closest ? trigger.closest(".dp-panel[data-dp-panel-modal-actions]") : null;
	if(!panel||!panel.dataset){return true;}
	var actions=String(panel.dataset.dpPanelModalActions||"").toLowerCase().split(/\s+/).filter(Boolean);
	if(actions.length===0){return false;}
	return actions.indexOf(action)!==-1;
}
/**
 * Synchronizes the expand button with root, trigger, and content state.
 *
 * the control hides when policy or width forbids expansion and updates
 * ARIA plus translated labels when normal versus expanded mode changes.
 */
function dpPanelSyncModalExpandButton(root,trigger){
	var expandButton=root ? root.querySelector(".dp-panel-modal-expand") : null;
	if(!expandButton){return;}
	var mode=dpPanelModalExpandMode(trigger);
	var width=trigger&&trigger.dataset ? String(trigger.dataset.dpPanelActionWidth||"md").replace(/[^a-z_]/g,"") : "md";
	var content=root.dataset.dpPanelModalContent||"";
	var hidden=!dpPanelModalActionEnabled(trigger,"expand")||width==="full"||mode==="never"||(mode==="surface"&&content!=="surface");
	if(hidden&&root.dataset.dpPanelModalExpanded==="1"){
		root.dataset.dpPanelModalExpanded="0";
	}
	expandButton.hidden=hidden;
	expandButton.setAttribute("aria-pressed",root.dataset.dpPanelModalExpanded==="1"?"true":"false");
	expandButton.textContent=root.dataset.dpPanelModalExpanded==="1" ? (typeof dpPanelText==="function" ? dpPanelText("modal.normal","Normal") : "Normal") : (typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand");
}
/**
 * Updates the modal back button from the current stack depth.
 *
 * the button is hidden when no snapshots exist and includes depth in its
 * label for nested modal workflows.
 */
function dpPanelUpdateModalBackButton(root){
	root=root||document.querySelector(".dp-panel-modal-root");
	if(!root){return;}
	var back=root.querySelector(".dp-panel-modal-back");
	if(back){
		back.hidden=dpPanelModalStack.length<1;
		back.textContent=dpPanelModalStack.length>1 ? (typeof dpPanelText==="function" ? dpPanelText("modal.back","Back") : "Back")+" ("+dpPanelModalStack.length+")" : (typeof dpPanelText==="function" ? dpPanelText("modal.back","Back") : "Back");
	}
}
/**
 * Captures the current modal body, header, actions, and scroll state.
 *
 * snapshots preserve modal history across nested action forms without
 * serializing business data or depending on server state.
 */
function dpPanelSnapshotModal(root){
	if(!root||root.hidden){return null;}
	var modal=root.querySelector(".dp-panel-modal");
	var title=root.querySelector(".dp-panel-modal-title h2");
	var description=root.querySelector(".dp-panel-modal-title p");
	var body=root.querySelector(".dp-panel-modal-body");
	var openFull=root.querySelector(".dp-panel-modal-open-full");
	var copyLink=root.querySelector(".dp-panel-modal-copy-link");
	var refresh=root.querySelector(".dp-panel-modal-refresh");
	var expand=root.querySelector(".dp-panel-modal-expand");
	if(!modal||!title||!description||!body){return null;}
	return {
		modalClass:modal.className,
		style:root.dataset.dpPanelModalStyle||"dialog",
		tone:root.dataset.dpPanelModalTone||"",
		content:root.dataset.dpPanelModalContent||"",
		expanded:root.dataset.dpPanelModalExpanded||"0",
		url:root.dataset.dpPanelModalUrl||"",
		stack:root.dataset.dpPanelModalStack||"replace",
		triggerToken:root.dataset.dpPanelModalTriggerToken||"",
		title:title.textContent||"",
		description:description.textContent||"",
		descriptionHidden:description.hidden,
		bodyHtml:body.innerHTML,
		scrollTop:body.scrollTop||0,
		openFullHidden:openFull ? openFull.hidden : true,
		openFullHref:openFull ? openFull.getAttribute("href")||"" : "",
		copyHidden:copyLink ? copyLink.hidden : true,
		refreshHidden:refresh ? refresh.hidden : true,
		expandHidden:expand ? expand.hidden : true,
		expandText:expand ? expand.textContent||(typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand") : (typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand"),
		expandPressed:expand ? expand.getAttribute("aria-pressed")||"false" : "false"
	};
}
/**
 * Restores a previously captured modal snapshot.
 *
 * restoration rebuilds header/action attributes, body markup, expansion
 * state, dirty baselines, and scroll position for client-side modal history.
 */
function dpPanelRestoreModalSnapshot(snapshot){
	var root=dpPanelModalRoot();
	if(!snapshot){return false;}
	var modal=root.querySelector(".dp-panel-modal");
	var title=root.querySelector(".dp-panel-modal-title h2");
	var description=root.querySelector(".dp-panel-modal-title p");
	var body=root.querySelector(".dp-panel-modal-body");
	var openFull=root.querySelector(".dp-panel-modal-open-full");
	var copyLink=root.querySelector(".dp-panel-modal-copy-link");
	var refresh=root.querySelector(".dp-panel-modal-refresh");
	var expand=root.querySelector(".dp-panel-modal-expand");
	if(!modal||!title||!description||!body){return false;}
	root.classList.remove("dp-panel-modal-busy","dp-panel-modal-discard-warn");
	modal.className=snapshot.modalClass||"dp-panel-modal dp-panel-modal-md";
	root.dataset.dpPanelModalStyle=snapshot.style||"dialog";
	root.dataset.dpPanelModalTone=snapshot.tone||"";
	root.dataset.dpPanelModalExpanded=snapshot.expanded||"0";
	root.dataset.dpPanelModalTriggerToken=snapshot.triggerToken||"";
	if(snapshot.content){root.dataset.dpPanelModalContent=snapshot.content;}else{delete root.dataset.dpPanelModalContent;}
	if(snapshot.url){root.dataset.dpPanelModalUrl=snapshot.url;}else{delete root.dataset.dpPanelModalUrl;}
	root.dataset.dpPanelModalStack=snapshot.stack||"replace";
	title.textContent=snapshot.title||(typeof dpPanelText==="function" ? dpPanelText("modal.action","Action") : "Action");
	description.textContent=snapshot.description||"";
	description.hidden=!!snapshot.descriptionHidden;
	body.innerHTML=snapshot.bodyHtml||"";
	if(openFull){
		openFull.hidden=!!snapshot.openFullHidden;
		if(snapshot.openFullHref){openFull.href=snapshot.openFullHref;}else{openFull.removeAttribute("href");}
	}
	if(copyLink){copyLink.hidden=!!snapshot.copyHidden;}
	if(refresh){refresh.hidden=!!snapshot.refreshHidden;}
	if(expand){
		expand.hidden=!!snapshot.expandHidden;
		expand.textContent=snapshot.expandText||(typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand");
		expand.setAttribute("aria-pressed", snapshot.expandPressed||"false");
	}
	dpPanelUpdateModalBackButton(root);
	dpPanelClassifyModalContent(body);
	body.onscroll=function(){dpPanelUpdateModalScrollState(body);};
	root.hidden=false;
	document.body.classList.add("dp-panel-modal-open");
	setTimeout(function(){
		body.scrollTop=snapshot.scrollTop||0;
		body.querySelectorAll("form").forEach(function(form){
			if(form.dataset.dpPanelModalPrepared==="1"){delete form.dataset.dpPanelModalPrepared;}
			var fallback=document.querySelector("[data-dp-panel-action-modal=\"1\"]")||root.querySelector(".dp-panel-modal-close");
			dpPanelPrepareModalForm(fallback,form);
		});
		if(typeof dpPanelInitTabs==="function"){dpPanelInitTabs();}
		if(typeof dpPanelInitSteps==="function"){dpPanelInitSteps();}
		if(typeof dpPanelInitRepeaters==="function"){dpPanelInitRepeaters();}
		if(typeof dpPanelRefreshPanelUi==="function"){dpPanelRefreshPanelUi();}
		else if(typeof dpPanelRefreshDependencies==="function"){dpPanelRefreshDependencies();}
		dpPanelRememberModalFormState(body);
		dpPanelUpdateModalScrollState(body);
	},0);
	return true;
}
/**
 * Navigates one step back through the modal stack.
 *
 * back navigation honors dirty-form protection unless forced, then
 * restores the previous snapshot and refreshes stack-dependent controls.
 */
function dpPanelRequestBackModal(force){
	var root=document.querySelector(".dp-panel-modal-root");
	if(!root||root.classList.contains("dp-panel-modal-busy")||dpPanelModalStack.length<1){return false;}
	if(!force&&dpPanelModalHasDirtyForm()&&Date.now()>dpPanelModalDiscardArmedUntil){
		dpPanelModalDiscardArmedUntil=Date.now()+3200;
		root.classList.add("dp-panel-modal-discard-warn");
		if(typeof dpPanelToast==="function"){dpPanelToast(typeof dpPanelText==="function" ? dpPanelText("modal.unsaved_back","Unsaved modal changes. Back again to discard.") : "Unsaved modal changes. Back again to discard.","warning");}
		setTimeout(function(){root.classList.remove("dp-panel-modal-discard-warn");},340);
		return false;
	}
	var snapshot=dpPanelModalStack.pop();
	dpPanelModalDiscardArmedUntil=0;
	return dpPanelRestoreModalSnapshot(snapshot);
}
/**
 * Closes the active modal and restores document focus/state.
 *
 * closing clears busy and expanded state, empties stack history, removes
 * modal-open body classes, and returns focus to the last trigger when possible.
 */
function dpPanelCloseModal(){
	var root=document.querySelector(".dp-panel-modal-root");
	if(!root){return;}
	root.classList.remove("dp-panel-modal-busy");
	root.classList.remove("dp-panel-modal-discard-warn");
	root.hidden=true;
	delete root.dataset.dpPanelModalTone;
	delete root.dataset.dpPanelModalContent;
	delete root.dataset.dpPanelModalExpanded;
	delete root.dataset.dpPanelModalUrl;
	delete root.dataset.dpPanelModalTriggerToken;
	delete root.dataset.dpPanelModalStack;
	dpPanelModalStack=[];
	dpPanelUpdateModalBackButton(root);
	dpPanelModalDiscardArmedUntil=0;
	document.body.classList.remove("dp-panel-modal-open");
	root.querySelector(".dp-panel-modal-body").innerHTML="";
	if(dpPanelModalLastFocus&&document.contains(dpPanelModalLastFocus)){
		dpPanelModalLastFocus.focus();
	}
	dpPanelModalLastFocus=null;
}
/**
 * Classifies modal body content for layout and affordance decisions.
 *
 * form, surface, confirmation, loading, and generic content classes
 * drive root data attributes used by CSS and optional modal actions.
 */
function dpPanelClassifyModalContent(body){
	var kind="content";
	if(body.querySelector(".dp-panel-modal-confirmation")){kind="confirmation";}
	else if(body.querySelector(".dp-panel-form")){kind="form";}
	else if(body.querySelector(".dp-panel-modal-surface")){kind="surface";}
	else if(body.querySelector(".dp-panel-modal-generated")){kind="generated";}
	body.dataset.dpPanelModalContent=kind;
	var root=body.closest(".dp-panel-modal-root");
	if(root){root.dataset.dpPanelModalContent=kind;}
}
/**
 * Updates modal scroll-state attributes for header/footer styling.
 *
 * scroll markers let CSS signal overflow and edge shadows without
 * recalculating layout inside each modal content renderer.
 */
function dpPanelUpdateModalScrollState(body){
	if(!body){return;}
	var canScroll=body.scrollHeight>body.clientHeight+2;
	body.dataset.dpPanelModalScrollable=canScroll?"1":"0";
	body.dataset.dpPanelModalScrolled=body.scrollTop>2?"1":"0";
	body.dataset.dpPanelModalAtEnd=(!canScroll||body.scrollTop+body.clientHeight>=body.scrollHeight-2)?"1":"0";
}
/**
 * Shows a transient modal status message.
 *
 * status messages communicate working, success, warning, or error states
 * during async modal fetch and submit flows without replacing modal content.
 */
function dpPanelModalStatus(message,type){
	var root=document.querySelector(".dp-panel-modal-root");
	if(!root||root.hidden){return;}
	type=type||"info";
	root.dataset.dpPanelModalStatus=type;
	var body=root.querySelector(".dp-panel-modal-body");
	if(!body){return;}
	var status=body.querySelector("[data-dp-panel-modal-status]");
	if(!status){
		status=document.createElement("div");
		status.className="dp-panel-modal-status";
		status.dataset.dpPanelModalStatus="";
		body.prepend(status);
	}
	status.className="dp-panel-modal-status dp-panel-modal-status-"+type;
	status.textContent=message||(typeof dpPanelText==="function" ? dpPanelText("modal.working","Working...") : "Working...");
}
/**
 * Clears the current modal status message.
 *
 * callers use this after async completion so stale mutation state does
 * not remain visible when the modal stays open for review or correction.
 */
function dpPanelClearModalStatus(){
	var root=document.querySelector(".dp-panel-modal-root");
	if(!root){return;}
	delete root.dataset.dpPanelModalStatus;
	root.querySelectorAll("[data-dp-panel-modal-status]").forEach(function(status){status.remove();});
}
/**
 * Opens modal content for an action trigger.
 *
 * this is the central modal composition boundary. It applies stack
 * strategy, width, tone, URL actions, focus trapping, scroll tracking, dirty-form
 * baselines, and trigger metadata before exposing content to the operator.
 */
function dpPanelOpenModal(trigger,content){
	var root=dpPanelModalRoot();
	var modal=root.querySelector(".dp-panel-modal");
	var title=root.querySelector(".dp-panel-modal-title h2");
	var description=root.querySelector(".dp-panel-modal-title p");
	var openFull=root.querySelector(".dp-panel-modal-open-full");
	var copyLink=root.querySelector(".dp-panel-modal-copy-link");
	var refresh=root.querySelector(".dp-panel-modal-refresh");
	var expandButton=root.querySelector(".dp-panel-modal-expand");
	var body=root.querySelector(".dp-panel-modal-body");
	var width=(trigger.dataset.dpPanelActionWidth||"md").replace(/[^a-z_]/g,"");
	var style=(trigger.dataset.dpPanelActionStyle||"").replace(/[^a-z_]/g,"");
	var tone=dpPanelModalActionTone(trigger);
	var actionUrl=dpPanelActionUrl(trigger);
	var actionMethod=dpPanelActionMethod(trigger);
	var triggerToken=dpPanelModalTriggerToken(trigger);
	var stackStrategy=dpPanelModalStackStrategy(trigger);
	if(stackStrategy==="clear"){dpPanelModalStack=[];}
	if(stackStrategy==="push"&&!root.hidden&&root.dataset.dpPanelModalTriggerToken!==triggerToken){
		var snapshot=dpPanelSnapshotModal(root);
		if(snapshot){dpPanelModalStack.push(snapshot);}
	}
	root.dataset.dpPanelModalStack=stackStrategy;
	modal.className="dp-panel-modal dp-panel-modal-"+(width||"md")+(style==="slide_over"?" dp-panel-modal-slide_over":"");
	root.dataset.dpPanelModalStyle=style==="slide_over"?"slide_over":"dialog";
	root.dataset.dpPanelModalTone=tone;
	root.dataset.dpPanelModalExpanded="0";
	root.dataset.dpPanelModalTriggerToken=triggerToken;
	var panel=trigger&&trigger.closest ? trigger.closest(".dp-panel[data-dp-panel-production]") : null;
	root.dataset.dpPanelProduction=(panel&&panel.dataset ? panel.dataset.dpPanelProduction : (document.querySelector(".dp-panel[data-dp-panel-production='0']") ? "0" : "1"));
	root.classList.remove("dp-panel-modal-busy");
	dpPanelClearModalStatus();
	dpPanelUpdateModalBackButton(root);
	if(expandButton){
		expandButton.setAttribute("aria-pressed","false");
		expandButton.textContent=typeof dpPanelText==="function" ? dpPanelText("modal.expand","Expand") : "Expand";
		expandButton.hidden=width==="full";
	}
	title.textContent=trigger.dataset.dpPanelActionHeading||trigger.textContent.trim()||(typeof dpPanelText==="function" ? dpPanelText("modal.action","Action") : "Action");
	description.textContent=trigger.dataset.dpPanelActionDescription||"";
	description.hidden=!description.textContent;
	if(openFull){
		if(dpPanelModalActionEnabled(trigger,"open_full")&&actionUrl&&actionMethod==="GET"){
			openFull.href=actionUrl;
			openFull.hidden=false;
			root.dataset.dpPanelModalUrl=actionUrl;
		}
		else{
			openFull.hidden=true;
			openFull.removeAttribute("href");
			delete root.dataset.dpPanelModalUrl;
		}
	}
	if(copyLink){copyLink.hidden=!(dpPanelModalActionEnabled(trigger,"copy_link")&&actionUrl&&actionMethod==="GET");}
	if(refresh){refresh.hidden=!(dpPanelModalActionEnabled(trigger,"refresh")&&actionUrl&&actionMethod==="GET");}
	body.innerHTML="";
	if(typeof content==="string"){
		body.innerHTML=content;
	}
	else if(content){
		body.appendChild(content);
	}
	dpPanelClassifyModalContent(body);
	dpPanelSyncModalExpandButton(root,trigger);
	dpPanelUpdateModalScrollState(body);
	body.onscroll=function(){dpPanelUpdateModalScrollState(body);};
	root.hidden=false;
	document.body.classList.add("dp-panel-modal-open");
	dpPanelModalLastFocus=trigger;
	setTimeout(function(){
		var focus=body.querySelector("input,select,textarea,button:not(.dp-panel-modal-close),a[href]")||root.querySelector(".dp-panel-modal-close");
		if(focus){focus.focus();}
		body.querySelectorAll("form").forEach(function(form){
			dpPanelPrepareModalForm(trigger,form);
		});
		dpPanelRememberModalFormState(body);
		if(typeof dpPanelInitTabs==="function"){dpPanelInitTabs();}
		if(typeof dpPanelInitSteps==="function"){dpPanelInitSteps();}
		if(typeof dpPanelInitRepeaters==="function"){dpPanelInitRepeaters();}
		if(typeof dpPanelRefreshPanelUi==="function"){dpPanelRefreshPanelUi();}
		else if(typeof dpPanelRefreshDependencies==="function"){dpPanelRefreshDependencies();}
		if(typeof dpPanelInitFieldEnhancements==="function"){dpPanelInitFieldEnhancements(body);}
		else if(typeof dpPanelApplyAccessibilityPolicies==="function"){dpPanelApplyAccessibilityPolicies(body);}
		dpPanelUpdateModalScrollState(body);
	},0);
}
/**
 * Resolves the URL associated with a modal action trigger.
 *
 * supports anchors, submit controls, and form-bound actions while
 * preserving native fallback semantics when no action URL exists.
 */
function dpPanelActionUrl(trigger){
	if(trigger.tagName==="A"){return trigger.href;}
	if(trigger.dataset&&trigger.dataset.dpPanelRowUrl){return trigger.dataset.dpPanelRowUrl;}
	var form=trigger.form;
	return trigger.getAttribute("formaction")||(form?form.action:"");
}
/**
 * Resolves the HTTP method associated with a modal action trigger.
 *
 * method resolution prefers explicit data attributes, then form methods,
 * and finally GET so links and buttons share a predictable modal contract.
 */
function dpPanelActionMethod(trigger){
	if(trigger.dataset&&trigger.dataset.dpPanelActionMethod){return String(trigger.dataset.dpPanelActionMethod||"GET").toUpperCase();}
	if(trigger.tagName==="A"){return "GET";}
	if(trigger.dataset&&trigger.dataset.dpPanelRowUrl){return "GET";}
	var form=trigger.form;
	return (trigger.formMethod||(form?form.method:"GET")||"GET").toUpperCase();
}
/**
 * Builds the return URL submitted by modal forms.
 *
 * return state preserves the current panel location while avoiding modal
 * fragment parameters, so successful mutations can refresh the intended workspace.
 */
function dpPanelModalReturnUrl(trigger,action){
	var panel=document.querySelector("main.dp-panel");
	var source=panel&&panel.dataset ? String(panel.dataset.dpPanelCurrentUrl||"") : "";
	if(!source){source=location.href;}
	try{
		var url=new URL(source,location.href);
		url.searchParams.delete("__panel_partial");
		var actionUrl=new URL(action||"",location.href);
		if(url.pathname===actionUrl.pathname&&trigger&&trigger.href){
			url=new URL(location.href);
			url.searchParams.delete("__panel_partial");
		}
		return url.pathname+url.search+url.hash;
	}
	catch(error){
		return location.pathname+location.search+location.hash;
	}
}
/**
 * Prepares a fetched form for modal submission.
 *
 * the form adapter rewrites return inputs, remembers initial values,
 * intercepts submit, sends fragment-aware requests, disables controls during
 * mutation, handles JSON/HTML payloads, and restores controls after completion.
 */
function dpPanelPrepareModalForm(trigger,form){
	if(form.dataset.dpPanelModalPrepared==="1"){return form;}
	form.dataset.dpPanelModalPrepared="1";
	var root=dpPanelModalRoot();
	form.querySelectorAll(".dp-panel-toolbar a").forEach(function(link){
		link.addEventListener("click",function(event){
			var label=(link.textContent||"").trim().toLowerCase();
			if(label!=="cancel"&&label!=="back"&&link.dataset.dpPanelModalCancel!=="1"){return;}
			event.preventDefault();
			dpPanelRequestCloseModal(false);
		});
	});
	form.addEventListener("submit",function(event){
		event.preventDefault();
		var submitter=event.submitter;
		var action=(submitter&&submitter.getAttribute("formaction"))||form.action;
		var method=((submitter&&submitter.formMethod)||form.method||"POST").toUpperCase();
		var requestUrl=dpPanelAjaxFragmentUrl(action);
		form.querySelectorAll("[data-dp-panel-step-disabled='1']").forEach(function(control){
			control.disabled=false;
			delete control.dataset.dpPanelStepDisabled;
		});
		var body=new FormData(form);
		body.delete("return_to");
		body.append("return_to",dpPanelModalReturnUrl(trigger,action));
		if(submitter&&submitter.name){
			body.append(submitter.name, submitter.value);
		}
		if(method==="GET"){
			body.forEach(function(value,key){
				if(typeof value==="string"){requestUrl.searchParams.append(key,value);}
			});
			body=null;
		}
		else{
			body.append("__panel_partial","fragment");
		}
		var controls=Array.prototype.slice.call(form.querySelectorAll("button,input[type='submit']"));
		controls.forEach(function(control){
			control.dataset.dpPanelModalPreviousDisabled=control.disabled?"1":"0";
			control.disabled=true;
		});
		form.classList.add("dp-panel-form-loading");
		root.classList.add("dp-panel-modal-busy");
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.saving_changes","Saving changes...") : "Saving changes...", "working");
		fetch(requestUrl,{
			method:method,
			body:method==="GET"?null:body,
			credentials:"same-origin",
			headers:{
				"Accept":"application/json",
				"X-Requested-With":"DataphyrePanelModal"
			}
		}).then(function(response){
			return dpPanelFragmentPayload(response);
		}).then(function(payload){
			if(!dpPanelHandleModalPayload(trigger,payload,action)){
				dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.unexpected_response_retry","The action returned an unexpected response. Please retry or open the full page.") : "The action returned an unexpected response. Please retry or open the full page.", "error");
			}
		}).catch(function(){
			dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.submit_failed_retry","The action could not complete in the dialog. Please retry or open the full page.") : "The action could not complete in the dialog. Please retry or open the full page.", "error");
		}).finally(function(){
			form.classList.remove("dp-panel-form-loading");
			root.classList.remove("dp-panel-modal-busy");
			if(!root.hidden){dpPanelClearModalStatus();}
			controls.forEach(function(control){
				if(control.dataset.dpPanelModalPreviousDisabled!=="1"){control.disabled=false;}
				delete control.dataset.dpPanelModalPreviousDisabled;
			});
		});
	});
	return form;
}
/**
 * Applies a modal form response payload.
 *
 * payload handling dispatches declared effects, closes or preserves the
 * dialog based on effect policy, refreshes workspace fragments, reopens invalid
 * forms for correction, and reports unexpected responses as recoverable failures.
 */
function dpPanelHandleModalPayload(trigger,payload,action){
	if(!payload){return false;}
	var effects=typeof dpPanelDispatchEffects==="function" ? dpPanelDispatchEffects(payload,"modal") : {};
	var shouldClose=typeof dpPanelEffectCloseModal==="function" ? dpPanelEffectCloseModal(effects,true) : true;
	if(payload.redirect_to){
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.done_refreshing_workspace","Done. Refreshing the workspace...") : "Done. Refreshing the workspace...", "success");
		if(shouldClose){setTimeout(function(){dpPanelCloseModal();},120);}
		dpPanelAjaxLoad(payload.redirect_to,{
			replace:true,
			preserveScroll:false,
			quiet:false,
			allowDuringMutation:true,
			targetedRefresh:false,
			refreshTargets:[],
			suppressEffects:true
		});
		return true;
	}
	if(!payload.html){return false;}
	var doc=dpPanelPayloadDoc(payload);
	if(!doc){return false;}
	var main=doc.querySelector("main.dp-panel");
	if(main&&document.querySelector("main.dp-panel")){
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.done_updating_workspace","Done. Updating the workspace...") : "Done. Updating the workspace...", "success");
		var applied=dpPanelAjaxApply(payload,action,{replace:true,preserveScroll:true,suppressEffects:true});
		if(applied&&shouldClose){setTimeout(function(){dpPanelCloseModal();},120);}
		return applied;
	}
	var nextForm=doc.querySelector(".dp-panel-form");
	if(nextForm){
		dpPanelPrepareModalForm(trigger,nextForm);
		dpPanelOpenModal(trigger,nextForm);
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.review_highlighted","Review the highlighted fields and try again.") : "Review the highlighted fields and try again.", "warning");
		return true;
	}
	var notice=doc.querySelector(".dp-panel-notice,.dp-panel-alert,.dp-panel-empty,p");
	if(notice){
		dpPanelOpenModal(trigger,notice);
		dpPanelNotifyPayload(payload);
		return true;
	}
	dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.unexpected_response","The action returned an unexpected response.") : "The action returned an unexpected response.", "warning");
	dpPanelToast(typeof dpPanelText==="function" ? dpPanelText("modal.unexpected_response_refresh","The action returned an unexpected response. Refreshing the panel is safest.") : "The action returned an unexpected response. Refreshing the panel is safest.", "warning");
	return false;
}
/**
 * Fetches action content for display inside a modal.
 *
 * action fetches request modal partials with same-origin credentials,
 * adapt field-bearing POST actions to GET where needed, parse returned HTML, and
 * fall back to full-page navigation when the modal contract is unavailable.
 */
function dpPanelFetchAction(trigger){
	var url=dpPanelActionUrl(trigger);
	if(!url){return;}
	dpPanelOpenModal(trigger,"<div class=\"dp-panel-modal-loading\"><span></span><strong>"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("modal.loading_action","Loading action") : "Loading action")+"</strong><small>"+dpPanelEscape(typeof dpPanelText==="function" ? dpPanelText("modal.loading_action_context","Preparing the form and current record context.") : "Preparing the form and current record context.")+"</small></div>");
	dpPanelModalRoot().classList.add("dp-panel-modal-busy");
	var method=dpPanelActionMethod(trigger);
	var hasFields=trigger.dataset&&trigger.dataset.dpPanelActionHasFields==="1";
	if(hasFields&&method!=="GET"&&trigger.form&&!trigger.classList.contains("dp-panel-bulk-action")){
		method="GET";
	}
	var requestUrl=new URL(url,window.location.href);
	requestUrl.searchParams.set("__panel_partial","modal");
	var options={method:method,credentials:"same-origin",headers:{"X-Requested-With":"DataphyrePanelModal"}};
	if(options.method!=="GET"){
		options.body=trigger.form?new FormData(trigger.form):new FormData();
		options.body.append("__panel_partial","modal");
	}
	fetch(requestUrl.toString(),options).then(function(response){
		if(response.redirected){
			window.location.href=response.url;
			return "";
		}
		return response.text();
	}).then(function(html){
		if(!html){return;}
		var doc=new DOMParser().parseFromString(html,"text/html");
		var form=doc.querySelector(".dp-panel-form");
		if(form){
			dpPanelOpenModal(trigger,dpPanelPrepareModalForm(trigger,form));
			return;
		}
		var surface=dpPanelModalSurfaceFromDoc(doc);
		if(surface){
			dpPanelOpenModal(trigger,surface);
			return;
		}
		window.location.href=url;
	}).catch(function(){
		window.location.href=url;
	}).finally(function(){
		var root=document.querySelector(".dp-panel-modal-root");
		if(root){root.classList.remove("dp-panel-modal-busy");}
	});
}
/**
 * Extracts record or panel surfaces from a fetched document.
 *
 * this helper clones known panel fragments into a modal surface so
 * content actions can preview record context without importing unrelated shell
 * markup from the response document.
 */
function dpPanelModalSurfaceFromDoc(doc){
	var nodes=doc.querySelectorAll(".dp-panel-record-heading,.dp-panel-record-pulse,.dp-panel-alerts,.dp-panel-insights,.dp-panel-show,.dp-panel-links,.dp-panel-contacts,.dp-panel-locations,.dp-panel-tags,.dp-panel-tasks,.dp-panel-activity,.dp-panel-changes,.dp-panel-items,.dp-panel-totals,.dp-panel-payments,.dp-panel-shipments,.dp-panel-attachments,.dp-panel-messages,.dp-panel-notes,.dp-panel-relation,.dp-panel-custom-page,.dp-panel-card,.dp-panel-empty-state");
	if(!nodes.length){return null;}
	var wrap=document.createElement("div");
	wrap.className="dp-panel-modal-surface";
	nodes.forEach(function(node){
		wrap.appendChild(node.cloneNode(true));
	});
	return wrap;
}
/**
 * Executes a confirmed modal action.
 *
 * confirmed actions choose between Ajax fragment mutation, native form
 * submission, link navigation, and full-page fallback based on method, action URL,
 * form ownership, and Ajax eligibility.
 */
function dpPanelRunConfirmedAction(trigger,button){
	var url=dpPanelActionUrl(trigger);
	if(!url){return false;}
	var method=dpPanelActionMethod(trigger);
	if(trigger.tagName==="A"){
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.opening","Opening...") : "Opening...", "working");
		setTimeout(function(){dpPanelCloseModal();},80);
		if(method==="GET"&&dpPanelAjaxEnabled()&&dpPanelAjaxAllowedUrl(url)){
			dpPanelAjaxLoad(url);
			return true;
		}
		window.location.href=url;
		return true;
	}
	var form=trigger.form;
	if(!form){
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.running_action","Running action...") : "Running action...", "working");
		if(method!=="GET"&&dpPanelAjaxEnabled()&&dpPanelAjaxAllowedUrl(url)){
			var body=new FormData();
			body.append("__panel_action_confirm","1");
			body.append("__panel_partial","fragment");
			dpPanelAjaxLoad(url,{
				method:method,
				body:body,
				replace:true,
				preserveScroll:false,
				onApplied:function(payload){dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.done_refreshing_workspace","Done. Refreshing the workspace...") : "Done. Refreshing the workspace...", "success");if(typeof dpPanelEffectCloseModal!=="function"||dpPanelEffectCloseModal(dpPanelPayloadEffects(payload),true)){setTimeout(function(){dpPanelCloseModal();},120);}},
				onFailed:function(){dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.refresh_failed","The action could not refresh in place.") : "The action could not refresh in place.", "error");}
			});
			return true;
		}
		setTimeout(function(){dpPanelCloseModal();},80);
		window.location.href=url;
		return true;
	}
	if(!dpPanelAjaxEnabled()||!dpPanelAjaxAllowedUrl(url)||method==="GET"){
		dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.running_action","Running action...") : "Running action...", "working");
		setTimeout(function(){dpPanelCloseModal();},80);
		trigger.dataset.dpPanelConfirmed="1";
		trigger.click();
		return true;
	}
	var body=new FormData(form);
	if(trigger.name){
		body.append(trigger.name,trigger.value||"");
	}
	body.append("__panel_partial","fragment");
	dpPanelSetSubmitLoading(form,trigger);
	dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.running_action","Running action...") : "Running action...", "working");
	dpPanelAjaxLoad(url,{
		method:method,
		body:body,
		replace:true,
		preserveScroll:false,
		form:form,
		onApplied:function(payload){dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.done_refreshing_workspace","Done. Refreshing the workspace...") : "Done. Refreshing the workspace...", "success");if(typeof dpPanelEffectCloseModal!=="function"||dpPanelEffectCloseModal(dpPanelPayloadEffects(payload),true)){setTimeout(function(){dpPanelCloseModal();},120);}},
		onFailed:function(){dpPanelModalStatus(typeof dpPanelText==="function" ? dpPanelText("modal.refresh_failed","The action could not refresh in place.") : "The action could not refresh in place.", "error");}
	});
	return true;
}
/**
 * Infers confirmation tone from trigger data, classes, and action copy.
 *
 * explicit data tone wins; otherwise destructive, success, warning, and
 * informational wording is mapped to safe visual defaults for confirmation UI.
 */
function dpPanelModalActionTone(trigger){
	var explicit=String(trigger.dataset.dpPanelActionTone||"").toLowerCase().replace(/[^a-z_]/g,"");
	if(["neutral","primary","success","warning","danger","info"].indexOf(explicit)!==-1){return explicit;}
	var label=(trigger.dataset.dpPanelActionSubmitLabel||trigger.dataset.dpPanelActionHeading||trigger.textContent||"").toLowerCase();
	var className=String(trigger.className||"").toLowerCase();
	if(className.indexOf("dp-panel-action-danger")!==-1||className.indexOf("danger")!==-1||/\b(delete|remove|destroy|cancel|reject|purge|forever|disable|block)\b/.test(label)){return "danger";}
	if(className.indexOf("dp-panel-action-success")!==-1||/\b(approve|restore|verify|ship|complete|activate|enable|save|create)\b/.test(label)){return "success";}
	if(className.indexOf("dp-panel-action-warning")!==-1||/\b(pause|hold|risk|review|warn|flag|escalate)\b/.test(label)){return "warning";}
	if(className.indexOf("dp-panel-action-info")!==-1||/\b(view|inspect|preview|export|import|copy|download)\b/.test(label)){return "info";}
	if(className.indexOf("dp-panel-action-primary")!==-1){return "primary";}
	return "neutral";
}
/**
 * Maps a modal confirmation tone to a compact text icon.
 *
 * text icons avoid external asset dependencies inside generated
 * confirmation markup while still distinguishing danger, success, info, and
 * neutral prompts.
 */
function dpPanelModalToneIcon(tone){
	if(tone==="danger"){return "!";}
	if(tone==="success"){return "ok";}
	if(tone==="info"||tone==="primary"){return "i";}
	if(tone==="neutral"){return "?";}
	return "!";
}
/**
 * Opens a confirmation dialog for an action trigger.
 *
 * the confirmation surface escapes trigger-provided copy, derives tone,
 * wires cancel/submit controls, and delegates the actual mutation or navigation
 * to the confirmed-action executor.
 */
function dpPanelConfirmAction(trigger){
	var wrap=document.createElement("div");
	var message=trigger.dataset.confirm||trigger.dataset.dpPanelActionDescription||(typeof dpPanelText==="function" ? dpPanelText("modal.run_action","Run this action?") : "Run this action?");
	var tone=dpPanelModalActionTone(trigger);
	var submitClass=tone==="neutral" ? "dp-panel-button" : "dp-panel-button dp-panel-action dp-panel-action-"+tone;
	wrap.className="dp-panel-modal-confirmation dp-panel-modal-confirmation-tone-"+tone;
	wrap.innerHTML="<div class=\"dp-panel-modal-confirmation-icon\">"+dpPanelEscape(dpPanelModalToneIcon(tone))+"</div><div class=\"dp-panel-modal-confirmation-copy\"><strong>"+dpPanelEscape(trigger.dataset.dpPanelActionHeading||(typeof dpPanelText==="function" ? dpPanelText("modal.confirm_action","Confirm action") : "Confirm action"))+"</strong><p>"+dpPanelEscape(message)+"</p></div><div class=\"dp-panel-modal-actions\"><button class=\"dp-panel-button dp-panel-button-secondary\" type=\"button\" data-dp-panel-modal-cancel>"+dpPanelEscape(trigger.dataset.dpPanelActionCancelLabel||(typeof dpPanelText==="function" ? dpPanelText("common.cancel","Cancel") : "Cancel"))+"</button><button class=\""+submitClass+"\" type=\"button\" data-dp-panel-modal-submit>"+dpPanelEscape(trigger.dataset.dpPanelActionSubmitLabel||trigger.textContent.trim()||(typeof dpPanelText==="function" ? dpPanelText("common.run","Run") : "Run"))+"</button></div>";
	wrap.querySelector("[data-dp-panel-modal-cancel]").addEventListener("click",dpPanelCloseModal);
	wrap.querySelector("[data-dp-panel-modal-submit]").addEventListener("click",function(){
		this.disabled=true;
		this.classList.add("dp-panel-action-loading");
		dpPanelRunConfirmedAction(trigger,this);
	});
	dpPanelOpenModal(trigger,wrap);
}
/**
 * Opens a table filter template inside the modal shell.
 *
 * filter triggers point at inert template content. The helper applies
 * modal heading and sizing metadata, clones the template, and keeps filter editing
 * local to the dialog until the form submits.
 */
function dpPanelOpenFilterModal(trigger){
	var templateId=trigger.dataset.dpPanelFilterTemplate||"";
	var template=templateId?document.getElementById(templateId):null;
	if(!template){return false;}
	trigger.dataset.dpPanelActionWidth="lg";
	trigger.dataset.dpPanelActionStyle="dialog";
	trigger.dataset.dpPanelActionHeading=trigger.dataset.dpPanelFilterHeading||(typeof dpPanelText==="function" ? dpPanelText("table.filters","Filters") : "Filters");
	trigger.dataset.dpPanelActionDescription=trigger.dataset.dpPanelFilterDescription||(typeof dpPanelText==="function" ? dpPanelText("table.filters_description","Narrow this table without losing your place.") : "Narrow this table without losing your place.");
	var content=template.content?template.content.cloneNode(true):null;
	if(!content){return false;}
	var wrap=document.createElement("div");
	wrap.appendChild(content);
	dpPanelOpenModal(trigger,wrap);
	return true;
}
document.addEventListener("click",function(event){
	if(event.target.closest("[data-dp-panel-modal-cancel]")){
		event.preventDefault();
		var root=document.querySelector(".dp-panel-modal-root");
		if(root&&root.classList.contains("dp-panel-modal-busy")){return;}
		dpPanelRequestCloseModal(false);
		return;
	}
	var filterTrigger=event.target.closest("[data-dp-panel-filter-modal]");
	if(filterTrigger){
		event.preventDefault();
		dpPanelOpenFilterModal(filterTrigger);
		return;
	}
	var trigger=event.target.closest("[data-dp-panel-action-modal=\"1\"]");
	if(!trigger){return;}
	if(trigger.matches&&trigger.matches("[data-dp-panel-row]")&&typeof dpPanelRowActivationBlockedByEvent==="function"&&dpPanelRowActivationBlockedByEvent(event,trigger)){
		return;
	}
	if(trigger.dataset.dpPanelConfirmed==="1"){
		delete trigger.dataset.dpPanelConfirmed;
		return;
	}
	event.preventDefault();
	if(trigger.dataset.dpPanelActionHasFields==="1"){
		dpPanelFetchAction(trigger);
		return;
	}
	if(trigger.dataset.dpPanelActionHasContent==="1"&&trigger.dataset.dpPanelActionHasHandler!=="1"&&!trigger.dataset.confirm){
		dpPanelOpenModal(trigger,trigger.dataset.dpPanelActionContent||"");
		return;
	}
	dpPanelConfirmAction(trigger);
});
document.addEventListener("keydown",function(event){
	var root=document.querySelector(".dp-panel-modal-root");
	if(root&&!root.hidden&&dpPanelTrapFocus(root,event)){return;}
	if(root&&!root.hidden&&event.altKey&&event.key==="Enter"){
		event.preventDefault();
		var expand=root.querySelector(".dp-panel-modal-expand:not([hidden])");
		if(expand){expand.click();}
		return;
	}
	if(event.key==="Escape"&&(!root||!root.classList.contains("dp-panel-modal-busy"))){dpPanelRequestCloseModal(false);}
});
JS;
	}

	/**
	 * Supplies drag-and-drop status-board transition behavior.
	 *
	 * board cards declare allowed transitions in data attributes. The
	 * script validates drop targets client-side, highlights allowed or blocked
	 * columns, locates the matching transition form, and submits through the
	 * confirmation modal path when a transition requires confirmation.
	 */
	private static function boardScript(): string {
		return 'function dpPanelBoardTransitions(card){try{return JSON.parse(card.dataset.dpPanelBoardTransitions||"{}")||{};}catch(error){return {};}}function dpPanelBoardTransitionForm(card,transition){var forms=card.querySelectorAll("form");for(var index=0;index<forms.length;index++){var input=forms[index].querySelector("input[name=\"transition\"]");if(input&&input.value===transition){return forms[index];}}return null;}function dpPanelBoardClearDrops(){document.querySelectorAll(".dp-panel-board-drop,.dp-panel-board-drop-blocked").forEach(function(column){column.classList.remove("dp-panel-board-drop","dp-panel-board-drop-blocked");});}function dpPanelBoardPackedGrid(card){return card&&card.closest ? card.closest(".dp-panel-board[data-dp-panel-packed-grid]") : null;}var dpPanelDraggedBoardCard=null;document.addEventListener("dragstart",function(event){var card=event.target.closest("[data-dp-panel-board-card]");if(!card){return;}dpPanelDraggedBoardCard=card;var grid=dpPanelBoardPackedGrid(card);if(grid){grid.dataset.dpPanelBoardDragging="1";}card.classList.add("dp-panel-board-dragging");if(event.dataTransfer){event.dataTransfer.effectAllowed="move";event.dataTransfer.setData("text/plain","dataphyre-panel-board-card");}});document.addEventListener("dragend",function(){var grid=dpPanelBoardPackedGrid(dpPanelDraggedBoardCard);if(dpPanelDraggedBoardCard){dpPanelDraggedBoardCard.classList.remove("dp-panel-board-dragging");}if(grid){delete grid.dataset.dpPanelBoardDragging;if(typeof dpPanelSchedulePackedGrid==="function"){dpPanelSchedulePackedGrid(grid);}}dpPanelDraggedBoardCard=null;dpPanelBoardClearDrops();});document.addEventListener("dragover",function(event){var column=event.target.closest("[data-dp-panel-board-column]");if(!column||!dpPanelDraggedBoardCard){return;}var transitions=dpPanelBoardTransitions(dpPanelDraggedBoardCard);var transition=transitions[column.dataset.dpPanelBoardStatus||""];if(transition){event.preventDefault();if(event.dataTransfer){event.dataTransfer.dropEffect="move";}column.classList.add("dp-panel-board-drop");column.classList.remove("dp-panel-board-drop-blocked");}else{column.classList.add("dp-panel-board-drop-blocked");column.classList.remove("dp-panel-board-drop");}});document.addEventListener("dragleave",function(event){var column=event.target.closest("[data-dp-panel-board-column]");if(column&&!column.contains(event.relatedTarget)){column.classList.remove("dp-panel-board-drop","dp-panel-board-drop-blocked");}});document.addEventListener("drop",function(event){var column=event.target.closest("[data-dp-panel-board-column]");if(!column||!dpPanelDraggedBoardCard){return;}var transitions=dpPanelBoardTransitions(dpPanelDraggedBoardCard);var transition=transitions[column.dataset.dpPanelBoardStatus||""];dpPanelBoardClearDrops();if(!transition){return;}event.preventDefault();var form=dpPanelBoardTransitionForm(dpPanelDraggedBoardCard,transition);if(!form){return;}var button=form.querySelector("[data-confirm]");if(button&&button.dataset.confirm){dpPanelConfirmAction(button);return;}if(form.requestSubmit){form.requestSubmit();}else{form.submit();}});';
	}

}
