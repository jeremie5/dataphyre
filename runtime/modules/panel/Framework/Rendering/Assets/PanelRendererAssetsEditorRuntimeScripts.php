<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits the rich text and source editor runtime.
 */
trait PanelRendererAssetsEditorRuntimeScripts {
	/**
	 * Returns this controller module for the public Panel runtime bundle.
	 */
	private static function editorRuntimeScript(): string {
		return <<<'JS'
function dpPanelEditorNormalizeRichHtml(value){
	value=String(value||"");
	if(value!==""&&value.indexOf("<")===-1&&/\r|\n/.test(value)){
		return value.replace(/\r\n?/g,"\n").split(/\n{2,}/).map(function(paragraph){
			var lines=paragraph.split("\n").map(dpPanelEscapeHtml);
			return "<p>"+(lines.join("<br>")||"<br>")+"</p>";
		}).join("");
	}
	var template=document.createElement("template");
	template.innerHTML=value;
	Array.prototype.slice.call(template.content.querySelectorAll("div")).forEach(function(div){
		var replacement=document.createElement("p");
		while(div.firstChild){replacement.appendChild(div.firstChild);}
		if(!replacement.childNodes.length){replacement.appendChild(document.createElement("br"));}
		div.replaceWith(replacement);
	});
	Array.prototype.slice.call(template.content.childNodes).forEach(function(node){
		if(node.nodeType===3){
			var text=String(node.textContent||"").replace(/\r\n?/g,"\n");
			if(text.trim()===""){node.remove();return;}
			var fragment=document.createDocumentFragment();
			text.split(/\n{2,}/).forEach(function(paragraph){
				var p=document.createElement("p");
				paragraph.split("\n").forEach(function(line,index){
					if(index>0){p.appendChild(document.createElement("br"));}
					p.appendChild(document.createTextNode(line));
				});
				fragment.appendChild(p);
			});
			node.replaceWith(fragment);
		}
		else if(node.nodeType===1&&node.tagName==="BR"){
			var p=document.createElement("p");
			p.appendChild(document.createElement("br"));
			node.replaceWith(p);
		}
	});
	return template.innerHTML;
}
var dpPanelEditorMediaReferences=new WeakMap();
/** Returns the ready, canonical asset-provider manifest for an editor. */
function dpPanelEditorAssetProfile(editor){
	if(!editor||!editor.dataset||!editor.dataset.dpPanelEditorProfile){return null;}
	try{
		var profile=JSON.parse(editor.dataset.dpPanelEditorProfile),provider=profile&&profile.asset_provider,capabilities=provider&&provider.capabilities;
		var elements=profile&&profile.policy&&profile.policy.elements;
		if(!provider||provider.type!=="panel_editor_asset_provider"||provider.schema_version!==1||provider.enabled===false||provider.ready!==true||!capabilities||capabilities.canonical_references!==true||!elements||!Array.isArray(elements.img)){return null;}
		return provider;
	}catch(error){return null;}
}
/** Fully decodes a URL component while rejecting unstable deep encoding. */
function dpPanelEditorDecodeAssetUrl(value){
	value=String(value||"");
	for(var pass=0;pass<4;pass++){
		var next;try{next=decodeURIComponent(value);}catch(error){return null;}
		if(next===value){return value;}value=next;
	}
	try{return decodeURIComponent(value)===value?value:null;}catch(error){return null;}
}
/** Mirrors the server asset URL policy: root-relative or HTTPS, never secret-bearing. */
function dpPanelEditorNormalizeAssetUrl(value){
	value=String(value||"").trim();
	if(!value||value.length>4096||/[\\\x00-\x20\x7f]/.test(value)||/^\/\//.test(value)){return null;}
	try{
		var url=new URL(value,document.baseURI),relative=value.charAt(0)==="/"&&value.charAt(1)!=="/";
		if(url.username||url.password||url.hash||(!relative&&url.protocol!=="https:")||(relative&&url.origin!==location.origin)){return null;}
		var path=dpPanelEditorDecodeAssetUrl(url.pathname);if(path===null||/(?:^|\/)\.\.?(?:\/|$)/.test(path)){return null;}
		var unsafe=false;url.searchParams.forEach(function(unused,key){var clean=dpPanelEditorDecodeAssetUrl(key);if(clean===null||/(?:secret|token|password|authorization|credential|api[_-]?key|access[_-]?key)/i.test(clean)){unsafe=true;}});
		return unsafe?null:value;
	}catch(error){return null;}
}
/** Adds one provider-issued media URL to an editor's exact client allow-list. */
function dpPanelEditorAllowMediaReference(editor,value){
	if(!dpPanelEditorAssetProfile(editor)){return null;}
	var normalized=dpPanelEditorNormalizeAssetUrl(value);if(normalized===null){return null;}
	var references=dpPanelEditorMediaReferences.get(editor);if(!references){references=new Set();dpPanelEditorMediaReferences.set(editor,references);}references.add(normalized);return normalized;
}
/** Seeds trusted references from server-rendered rich or markdown source once. */
function dpPanelEditorSeedMediaReferences(editor,value){
	if(!dpPanelEditorAssetProfile(editor)){return;}
	var template=document.createElement("template");template.innerHTML=String(value||"");
	template.content.querySelectorAll("img[src]").forEach(function(image){dpPanelEditorAllowMediaReference(editor,image.getAttribute("src")||"");});
	var markdown=String(value||""),pattern=/!\[[^\]\r\n]*\]\(\s*(?:<([^>\r\n]+)>|((?:\\.|[^)\s])+))/g,match;
	while((match=pattern.exec(markdown))!==null){dpPanelEditorAllowMediaReference(editor,match[1]||match[2]||"");}
}
/** Sanitizes editor HTML while preserving only exact provider-issued image URLs. */
function dpPanelEditorSanitizeRichHtml(editor,value){
	var references=editor&&dpPanelEditorAssetProfile(editor)?dpPanelEditorMediaReferences.get(editor):null;
	return dpPanelSanitizeRichHtml(value,{allowMediaUrl:function(url){var normalized=dpPanelEditorNormalizeAssetUrl(url);return normalized!==null&&!!references&&references.has(normalized);}});
}
/**
 * Repairs malformed rich fragments after normalization or sanitization.
 *
 * Inline wrappers containing whole block elements are inverted so blocks remain
 * top-level editor units, empty formatting shells are removed, and empty lists are
 * discarded. The function mutates the provided DOM fragment in place.
 *
 * @param {DocumentFragment|HTMLElement|null} root Fragment or element to clean.
 * @returns {void}
 */
function dpPanelCleanRichHtmlFragment(root){
	if(!root||!root.querySelectorAll){return;}
	var blockSelector="p,li,blockquote,pre,h1,h2,h3,h4,h5,h6";
	Array.prototype.slice.call(root.querySelectorAll("strong,b,em,i,u,s,a,code")).forEach(function(inline){
		var blocks=Array.prototype.slice.call(inline.children||[]).filter(function(child){
			return child.matches&&child.matches(blockSelector);
		});
		if(blocks.length===0||blocks.length!==(inline.children||[]).length){return;}
		blocks.forEach(function(block){
			var clone=inline.cloneNode(false);
			while(block.firstChild){clone.appendChild(block.firstChild);}
			block.appendChild(clone);
			inline.parentNode.insertBefore(block,inline);
		});
		inline.remove();
	});
	var removable="p,li,strong,b,em,i,u,s,a,blockquote,pre,code,h1,h2,h3,h4,h5,h6";
	for(var pass=0;pass<3;pass++){
		Array.prototype.slice.call(root.querySelectorAll(removable)).forEach(function(node){
			var text=String(node.textContent||"").replace(/\u00a0/g,"").trim();
			var media=node.querySelector&&node.querySelector("br,hr,img");
			if(text===""&&!media){node.remove();}
		});
	}
	Array.prototype.slice.call(root.querySelectorAll("ul,ol")).forEach(function(list){
		if(!list.querySelector("li")){list.remove();}
	});
}
/**
 * Converts the Panel-supported markdown subset into editor-safe HTML.
 *
 * The parser intentionally covers the toolbar contract rather than the full
 * CommonMark surface: headings, unordered and ordered lists, quotes, horizontal
 * rules, fenced code blocks, inline code, emphasis, strike, and links. Link
 * targets are rejected when they use scriptable schemes, and the caller still
 * passes the result through the rich sanitizer.
 *
 * @param {string} value Markdown source from a Panel editor.
 * @returns {string} Unsanitized HTML for the supported markdown subset.
 */
function dpPanelMarkdownToHtml(value){
	var lines=String(value||"").replace(/\r\n?/g,"\n").split("\n");
	var html=[];
	var list=null;
	var paragraph=[];
	var codeFence=false;
	var codeLines=[];
	/**
	 * Applies supported inline markdown markers to already escaped text.
	 *
	 * This helper runs inside the block parser and must never emit unescaped
	 * user text except through controlled anchor attributes and known tags.
	 *
	 * @param {string} text Inline markdown segment.
	 * @returns {string} HTML for the supported inline subset.
	 */
	function inline(text){
		text=dpPanelEscapeHtml(text);
		text=text.replace(/`([^`]+)`/g,"<code>$1</code>");
		text=text.replace(/\*\*([^*]+)\*\*/g,"<strong>$1</strong>");
		text=text.replace(/__([^_]+)__/g,"<strong>$1</strong>");
		text=text.replace(/\*([^*]+)\*/g,"<em>$1</em>");
		text=text.replace(/_([^_]+)_/g,"<em>$1</em>");
		text=text.replace(/~~([^~]+)~~/g,"<s>$1</s>");
		text=text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g,function(match,label,url){
			url=String(url||"").trim().replace(/^&lt;|&gt;$/g,"");
			return '<img src="'+url+'" alt="'+label+'" loading="lazy">';
		});
		text=text.replace(/\[([^\]]+)\]\(([^)]+)\)/g,function(match,label,url){
			url=String(url||"").trim();
			if(/^\s*javascript:/i.test(url)){return label;}
			return '<a href="'+dpPanelEscapeHtml(url)+'" target="_blank" rel="noopener noreferrer">'+label+"</a>";
		});
		return text;
	}
	/**
	 * Emits the pending paragraph buffer and clears parser paragraph state.
	 *
	 * Paragraph lines are joined with spaces so wrapped textarea lines do not
	 * create unexpected hard breaks in the visual preview.
	 *
	 * @returns {void}
	 */
	function flushParagraph(){
		if(paragraph.length){
			html.push("<p>"+inline(paragraph.join(" "))+"</p>");
			paragraph=[];
		}
	}
	/**
	 * Closes the active list tag when the block parser leaves list context.
	 *
	 * @returns {void}
	 */
	function closeList(){
		if(list){html.push("</"+list+">");list=null;}
	}
	lines.forEach(function(line){
		if(/^```/.test(line)){
			flushParagraph();closeList();
			if(codeFence){
				html.push("<pre><code>"+dpPanelEscapeHtml(codeLines.join("\n"))+"</code></pre>");
				codeFence=false;
				codeLines=[];
			}
			else{
				codeFence=true;
				codeLines=[];
			}
			return;
		}
		if(codeFence){
			codeLines.push(line);
			return;
		}
		var heading=line.match(/^(#{1,6})\s+(.+)$/);
		var bullet=line.match(/^\s*[-*]\s+(.+)$/);
		var ordered=line.match(/^\s*\d+\.\s+(.+)$/);
		var quote=line.match(/^>\s?(.+)$/);
		var hr=line.match(/^\s*(?:---|\*\*\*|___)\s*$/);
		if(heading){flushParagraph();closeList();html.push("<h"+heading[1].length+">"+inline(heading[2])+"</h"+heading[1].length+">");}
		else if(bullet){flushParagraph();if(list!=="ul"){closeList();html.push("<ul>");list="ul";}html.push("<li>"+inline(bullet[1])+"</li>");}
		else if(ordered){flushParagraph();if(list!=="ol"){closeList();html.push("<ol>");list="ol";}html.push("<li>"+inline(ordered[1])+"</li>");}
		else if(quote){flushParagraph();closeList();html.push("<blockquote>"+inline(quote[1])+"</blockquote>");}
		else if(hr){flushParagraph();closeList();html.push("<hr>");}
		else if(line.trim()===""){flushParagraph();closeList();}
		else{paragraph.push(line.trim());}
	});
	if(codeFence&&codeLines.length){html.push("<pre><code>"+dpPanelEscapeHtml(codeLines.join("\n"))+"</code></pre>");}
	flushParagraph();closeList();
	return html.join("");
}
/**
 * Refreshes an editor preview pane from the current source value.
 *
 * Markdown and rich modes pass through the sanitizer before entering the preview,
 * code mode uses textContent, and plain mode only converts line breaks after HTML
 * escaping. Status text is refreshed as part of the same render cycle.
 *
 * @param {HTMLElement} editor Editor shell with Panel editor data attributes.
 * @returns {void}
 */
function dpPanelEditorRenderPreview(editor){
	var source=editor.querySelector("[data-dp-panel-editor-source]");
	var preview=editor.querySelector(".dp-panel-editor-preview");
	if(!source||!preview){return;}
	var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase();
	var value=source.value||"";
	if(value.trim()===""){
		preview.classList.add("dp-panel-editor-preview-empty");
		preview.textContent=dpPanelText("client.editor_preview_empty","Preview appears after content is entered.");
		dpPanelEditorRefreshStatus(editor);
		return;
	}
	preview.classList.remove("dp-panel-editor-preview-empty");
	if(mode==="markdown"){preview.innerHTML=dpPanelEditorSanitizeRichHtml(editor,dpPanelMarkdownToHtml(value));}
	else if(mode==="html"||mode==="rich_editor"||mode==="rich_text"){preview.innerHTML=dpPanelEditorSanitizeRichHtml(editor,dpPanelEditorNormalizeRichHtml(value));}
	else if(mode==="code"){if(typeof dpPanelEditorRenderSyntaxTokens!=="function"||!dpPanelEditorRenderSyntaxTokens(editor,preview,value)){preview.textContent=value;}}
	else{preview.innerHTML=dpPanelEscapeHtml(value).replace(/\n/g,"<br>");}
	dpPanelEditorRefreshStatus(editor);
}
/**
 * Updates word, character, and active mode status text for an editor shell.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorRefreshStatus(editor){
	if(!editor||!editor.querySelector){return;}
	var source=editor.querySelector("[data-dp-panel-editor-source]");
	var status=editor.querySelector("[data-dp-panel-editor-status]");
	if(!source||!status){return;}
	var value=source.value||"";
	var words=(value.trim().match(/\S+/g)||[]).length;
	var chars=value.length;
	var currentMode=editor.dataset.dpPanelEditorMode||"write";
	var mode=currentMode==="preview"
		? dpPanelText("editor.preview","Preview")
		: (currentMode==="source" ? dpPanelText("editor.source","Source") : dpPanelText("editor.write","Write"));
	status.textContent=dpPanelText("client.editor_status","{mode} | {words} words | {chars} chars",{mode:mode,words:words,chars:chars});
}
/**
 * Detects whether the visual editor contains user-visible content.
 *
 * Non-breaking spaces and whitespace are ignored, while media-style elements and
 * horizontal rules count as content even when they have no text.
 *
 * @param {HTMLElement|null} visual Contenteditable visual editor surface.
 * @returns {boolean} Whether meaningful visual content exists.
 */
function dpPanelEditorVisualHasContent(visual){
	if(!visual){return false;}
	var text=String(visual.textContent||"").replace(/\u00a0/g,"").trim();
	if(text!==""){return true;}
	return !!visual.querySelector("img,video,audio,iframe,hr");
}
/**
 * Mirrors visual editor emptiness into a data attribute consumed by CSS.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorRefreshEmptyState(editor){
	if(!editor||!editor.querySelector){return;}
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(!visual){return;}
	visual.dataset.dpPanelEditorEmpty=dpPanelEditorVisualHasContent(visual) ? "" : "1";
}
/**
 * Resolves the current selection anchor to an element inside the visual editor.
 *
 * Selection outside the editor is ignored so toolbar state cannot be driven by
 * unrelated page focus.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {Element|null} Closest selectable element inside the visual editor.
 */
function dpPanelEditorSelectionElement(editor){
	if(!editor||!window.getSelection){return null;}
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var selection=window.getSelection();
	if(!visual||!selection||selection.rangeCount<1){return null;}
	var node=selection.anchorNode||selection.getRangeAt(0).commonAncestorContainer;
	if(!node||!visual.contains(node)){return null;}
	if(node.nodeType===3){node=node.parentElement;}
	else if(node.nodeType!==1){node=node.parentElement||null;}
	return node&&node.closest ? node : null;
}
/**
 * Synchronizes toolbar pressed state with the current visual selection.
 *
 * Browser execCommand state is used for native formatting commands, while block
 * and link states are inferred from the selected DOM ancestry. Source and preview
 * modes clear active command state.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorRefreshCommandState(editor){
	if(!editor||!editor.querySelectorAll){return;}
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var isVisual=editor.dataset.dpPanelEditorMode==="write"&&visual&&!visual.hidden;
	var element=isVisual ? dpPanelEditorSelectionElement(editor) : null;
	var states={};
	if(isVisual&&element){
		try{states.bold=!!document.queryCommandState("bold");}catch(error){states.bold=false;}
		try{states.italic=!!document.queryCommandState("italic");}catch(error){states.italic=false;}
		try{states.underline=!!document.queryCommandState("underline");}catch(error){states.underline=false;}
		try{states.strike=!!document.queryCommandState("strikeThrough");}catch(error){states.strike=false;}
		try{states.unordered_list=!!document.queryCommandState("insertUnorderedList");}catch(error){states.unordered_list=false;}
		try{states.ordered_list=!!document.queryCommandState("insertOrderedList");}catch(error){states.ordered_list=false;}
		states.link=!!element.closest("a");
		states.unlink=!!element.closest("a");
		states.heading=!!element.closest("h1,h2,h3,h4,h5,h6");
		states.heading_1=!!element.closest("h1");
		states.heading_2=!!element.closest("h2");
		states.heading_3=!!element.closest("h3");
		states.paragraph=!!element.closest("p");
		states.quote=!!element.closest("blockquote");
		states.code=!!element.closest("pre,code");
		states.code_block=!!element.closest("pre");
	}
	editor.querySelectorAll("[data-dp-panel-editor-command]").forEach(function(button){
		var command=button.dataset.dpPanelEditorCommand||"";
		var active=!!states[command];
		button.dataset.dpPanelFieldButtonState=active ? "active" : "";
		button.setAttribute("aria-pressed",active ? "true" : "false");
	});
}
/**
 * Normalizes text nodes for markdown serialization.
 *
 * @param {string} text Text extracted from a DOM node.
 * @returns {string} Markdown-safe text with non-breaking spaces normalized.
 */
function dpPanelEditorTextToMarkdown(text){
	return String(text||"").replace(/\u00a0/g," ").replace(/[ \t]+\n/g,"\n");
}
/**
 * Normalizes inline markdown output without collapsing intentional paragraph breaks.
 *
 * @param {string} text Inline text extracted from rich HTML.
 * @returns {string} Inline markdown segment.
 */
function dpPanelEditorMarkdownInline(text){
	return dpPanelEditorTextToMarkdown(text).replace(/\n{3,}/g,"\n\n");
}
/**
 * Serializes a node's children into markdown using the current traversal context.
 *
 * @param {Node} node Parent node whose children should be serialized.
 * @param {Object} context Serialization flags shared across recursive calls.
 * @returns {string} Markdown content for all child nodes.
 */
function dpPanelEditorNodeChildrenToMarkdown(node,context){
	var out="";
	Array.prototype.slice.call(node.childNodes||[]).forEach(function(child){
		out+=dpPanelEditorNodeToMarkdown(child,context||{});
	});
	return out;
}
/**
 * Prefixes every non-empty markdown line for quote and nested block rendering.
 *
 * @param {string} text Markdown block text.
 * @param {string} prefix Prefix to apply per line.
 * @returns {string} Prefixed markdown block.
 */
function dpPanelEditorPrefixMarkdownLines(text,prefix){
	text=String(text||"").trim();
	if(text===""){return prefix.trim();}
	return text.split(/\n/).map(function(line){return prefix+line;}).join("\n");
}
/**
 * Serializes a sanitized rich DOM node into the Panel markdown subset.
 *
 * Unknown elements fall through to their children, preserving text while dropping
 * unsupported structure. Links are normalized through the same URL policy used by
 * editor link creation.
 *
 * @param {Node|null} node DOM node from the sanitized editor fragment.
 * @param {Object} context Serialization flags shared across recursive calls.
 * @returns {string} Markdown representation of the node.
 */
function dpPanelEditorNodeToMarkdown(node,context){
	context=context||{};
	if(!node){return "";}
	if(node.nodeType===3){return dpPanelEditorTextToMarkdown(node.nodeValue||"");}
	if(node.nodeType!==1){return "";}
	var tag=String(node.tagName||"").toLowerCase();
	var inner=function(){return dpPanelEditorNodeChildrenToMarkdown(node,context);};
	if(tag==="br"){return "\n";}
	if(tag==="hr"){return "\n\n---\n\n";}
	if(/^h[1-6]$/.test(tag)){return "\n\n"+Array(parseInt(tag.slice(1),10)+1).join("#")+" "+dpPanelEditorMarkdownInline(inner()).trim()+"\n\n";}
	if(tag==="p"||tag==="div"){return "\n\n"+dpPanelEditorMarkdownInline(inner()).trim()+"\n\n";}
	if(tag==="strong"||tag==="b"){return "**"+dpPanelEditorMarkdownInline(inner()).trim()+"**";}
	if(tag==="em"||tag==="i"){return "*"+dpPanelEditorMarkdownInline(inner()).trim()+"*";}
	if(tag==="s"||tag==="strike"||tag==="del"){return "~~"+dpPanelEditorMarkdownInline(inner()).trim()+"~~";}
	if(tag==="code"&&context.inPre!==true){return "`"+dpPanelEditorMarkdownInline(inner()).replace(/`/g,"\\`").trim()+"`";}
	if(tag==="pre"){return "\n\n```\n"+dpPanelEditorTextToMarkdown(node.textContent||"").replace(/\n+$/,"")+"\n```\n\n";}
	if(tag==="blockquote"){return "\n\n"+dpPanelEditorPrefixMarkdownLines(inner(),"> ")+"\n\n";}
	if(tag==="a"){
		var label=dpPanelEditorMarkdownInline(inner()).trim()||String(node.getAttribute("href")||"").trim();
		var href=dpPanelEditorNormalizeLinkUrl(node.getAttribute("href")||"");
		return href ? "["+label+"]("+href+")" : label;
	}
	if(tag==="img"){
		var source=String(node.getAttribute("src")||"").trim();
		var alt=String(node.getAttribute("alt")||"").replace(/[\[\]\\]/g,"\\$&");
		return source ? "!["+alt+"]("+source+")" : alt;
	}
	if(tag==="ul"||tag==="ol"){
		var lines=[];
		Array.prototype.slice.call(node.children||[]).forEach(function(child,index){
			if(String(child.tagName||"").toLowerCase()!=="li"){return;}
			var marker=tag==="ol" ? (index+1)+". " : "- ";
			lines.push(marker+dpPanelEditorNodeChildrenToMarkdown(child,context).trim().replace(/\n/g,"\n  "));
		});
		return "\n\n"+lines.join("\n")+"\n\n";
	}
	if(tag==="li"){return "- "+inner().trim()+"\n";}
	if(tag==="u"){return "<u>"+dpPanelEditorMarkdownInline(inner()).trim()+"</u>";}
	return inner();
}
/**
 * Converts sanitized rich HTML back into the Panel markdown subset.
 *
 * The method sanitizes first, walks the DOM, and then trims excess blank lines so
 * visual editing cannot persist scriptable or unsupported markup through markdown
 * mode.
 *
 * @param {string} html Rich editor HTML.
 * @param {HTMLElement|null=} editor Owning editor for exact media references.
 * @returns {string} Markdown source for persistence in markdown mode.
 */
function dpPanelEditorHtmlToMarkdown(html,editor){
	var template=document.createElement("template");
	template.innerHTML=editor?dpPanelEditorSanitizeRichHtml(editor,html||""):dpPanelSanitizeRichHtml(html||"");
	var markdown=dpPanelEditorNodeChildrenToMarkdown(template.content,{});
	return markdown.replace(/[ \t]+\n/g,"\n").replace(/\n{3,}/g,"\n\n").trim();
}
/**
 * Copies contenteditable HTML into the source textarea and preview state.
 *
 * Visual HTML is normalized, sanitized, optionally serialized to markdown, and
 * written back to the visual surface so the DOM and submitted value share the
 * same security boundary.
 *
 * @param {HTMLElement} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorSyncFromVisual(editor){
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var source=editor.querySelector("[data-dp-panel-editor-source]");
	if(!visual||!source){return;}
	var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase();
	var cleaned=dpPanelEditorSanitizeRichHtml(editor,dpPanelEditorNormalizeRichHtml(visual.innerHTML));
	source.value=mode==="markdown" ? dpPanelEditorHtmlToMarkdown(cleaned,editor) : cleaned;
	var displayHtml=cleaned||"<p><br></p>";
	if(visual.innerHTML!==displayHtml){visual.innerHTML=displayHtml;}
	dpPanelEditorRenderPreview(editor);
	dpPanelRefreshCharacterCounter(source);
	dpPanelEditorRefreshEmptyState(editor);
	dpPanelEditorRefreshCommandState(editor);
}
/**
 * Copies source textarea content into the visual editor surface.
 *
 * Markdown source is rendered through the supported markdown parser; rich and
 * plain rich modes are normalized as HTML. The visual surface always receives
 * sanitized HTML and a placeholder paragraph when empty.
 *
 * @param {HTMLElement} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorSyncToVisual(editor){
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var source=editor.querySelector("[data-dp-panel-editor-source]");
	if(!visual||!source){return;}
	var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase();
	var cleaned=mode==="markdown"
		? dpPanelEditorSanitizeRichHtml(editor,dpPanelMarkdownToHtml(source.value||""))
		: dpPanelEditorSanitizeRichHtml(editor,dpPanelEditorNormalizeRichHtml(source.value||""));
	if(mode!=="markdown"){source.value=cleaned;}
	visual.innerHTML=cleaned||"<p><br></p>";
	dpPanelEditorRenderPreview(editor);
	dpPanelEditorRefreshEmptyState(editor);
	dpPanelEditorRefreshCommandState(editor);
}
/**
 * Wraps a textarea selection with command markup and preserves the new selection.
 *
 * The helper dispatches an input event so counters, previews, and form dirty
 * tracking observe toolbar-driven mutations like user typing.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @param {string} before Markup inserted before the selection.
 * @param {string} after Markup inserted after the selection.
 * @param {string} placeholder Text used when there is no selected range.
 * @returns {void}
 */
function dpPanelEditorWrapSelection(textarea,before,after,placeholder){
	var start=textarea.selectionStart||0;
	var end=textarea.selectionEnd||0;
	var value=textarea.value||"";
	var selected=value.slice(start,end)||placeholder||"";
	textarea.value=value.slice(0,start)+before+selected+after+value.slice(end);
	textarea.focus();
	textarea.setSelectionRange(start+before.length,start+before.length+selected.length);
	textarea.dispatchEvent(new Event("input",{bubbles:true}));
}
/**
 * Replaces the current textarea selection and positions the caret inside the result.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @param {string} replacement Replacement text.
 * @param {number|null} selectStart Optional selection start relative to insertion.
 * @param {number|null} selectEnd Optional selection end relative to insertion.
 * @returns {void}
 */
function dpPanelEditorReplaceSelection(textarea,replacement,selectStart,selectEnd){
	var start=textarea.selectionStart||0;
	var end=textarea.selectionEnd||0;
	var value=textarea.value||"";
	textarea.value=value.slice(0,start)+replacement+value.slice(end);
	textarea.focus();
	var nextStart=start+(selectStart==null ? replacement.length : selectStart);
	var nextEnd=start+(selectEnd==null ? nextStart-start : selectEnd);
	textarea.setSelectionRange(nextStart,nextEnd);
	textarea.dispatchEvent(new Event("input",{bubbles:true}));
}
/**
 * Normalizes link targets accepted by the editor toolbar.
 *
 * Scriptable schemes are rejected, absolute web/contact URLs and local anchors are
 * preserved, and bare domains are promoted to HTTPS.
 *
 * @param {string|null} url User-entered or existing link target.
 * @returns {string} Safe link target, or an empty string when rejected.
 */
function dpPanelEditorNormalizeLinkUrl(url){
	url=String(url||"").trim();
	if(url===""){return "";}
	if(/^\s*javascript:/i.test(url)){return "";}
	if(/^(mailto:|tel:|https?:\/\/|\/|#)/i.test(url)){return url;}
	return "https://"+url;
}
/**
 * Prompts for a link target and applies the editor link URL policy.
 *
 * @param {string} current Current link target used as the prompt default.
 * @returns {string} Normalized link target, or an empty string when canceled or rejected.
 */
function dpPanelEditorPromptLinkUrl(current){
	var url=window.prompt(dpPanelText("client.link_url_prompt","Link URL"),current||"https://");
	return dpPanelEditorNormalizeLinkUrl(url);
}
/**
 * Converts the current markdown textarea selection into a markdown link.
 *
 * Existing markdown link selections are unpacked so the label can be edited while
 * retaining the current URL as prompt context.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @returns {void}
 */
function dpPanelEditorMarkdownLinkSelection(textarea){
	var start=textarea.selectionStart||0;
	var end=textarea.selectionEnd||0;
	var value=textarea.value||"";
	var selected=value.slice(start,end)||"link text";
	var currentUrl="";
	var existing=selected.match(/^\[([^\]]+)\]\(([^)]+)\)$/);
	if(existing){
		selected=existing[1];
		currentUrl=existing[2];
	}
	var url=dpPanelEditorPromptLinkUrl(currentUrl);
	if(!url){return;}
	var replacement="["+selected+"]("+url+")";
	dpPanelEditorReplaceSelection(textarea,replacement,1,1+selected.length);
}
/**
 * Removes link markup from the selected source textarea range.
 *
 * Both sanitized HTML anchors and markdown links are supported because the source
 * toolbar operates across HTML, rich, and markdown editor modes.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @returns {void}
 */
function dpPanelEditorUnlinkSourceSelection(textarea){
	var start=textarea.selectionStart||0;
	var end=textarea.selectionEnd||0;
	var value=textarea.value||"";
	var selected=value.slice(start,end);
	if(!selected){return;}
	var replacement=selected.replace(/<a\b[^>]*>(.*?)<\/a>/gis,"$1").replace(/\[([^\]]+)\]\([^)]+\)/g,"$1");
	dpPanelEditorReplaceSelection(textarea,replacement);
}
/**
 * Captures the selected textarea range expanded to complete logical lines.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @returns {Object} Selection metadata with full value, offsets, line bounds, and line text.
 */
function dpPanelEditorSelectedLines(textarea){
	var value=textarea.value||"";
	var start=textarea.selectionStart||0;
	var end=textarea.selectionEnd||0;
	var lineStart=value.lastIndexOf("\n",Math.max(0,start-1))+1;
	var lineEnd=value.indexOf("\n",end);
	if(lineEnd===-1){lineEnd=value.length;}
	return {value:value,start:start,end:end,lineStart:lineStart,lineEnd:lineEnd,text:value.slice(lineStart,lineEnd)};
}
/**
 * Applies a markdown block prefix to every selected source line.
 *
 * Existing heading, quote, and list markers are stripped first so repeated toolbar
 * presses replace the block type instead of stacking markers.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @param {string} prefix Markdown marker to apply.
 * @param {string} placeholder Text inserted when the selected lines are empty.
 * @returns {void}
 */
function dpPanelEditorPrefixLines(textarea,prefix,placeholder){
	var selected=dpPanelEditorSelectedLines(textarea);
	var text=selected.text||placeholder||"";
	var lines=text.split("\n").map(function(line){
		return prefix+line.replace(/^\s*(?:#{1,6}\s+|>\s+|- |\d+\.\s+)/,"");
	});
	var replacement=lines.join("\n");
	textarea.value=selected.value.slice(0,selected.lineStart)+replacement+selected.value.slice(selected.lineEnd);
	textarea.focus();
	textarea.setSelectionRange(selected.lineStart,selected.lineStart+replacement.length);
	textarea.dispatchEvent(new Event("input",{bubbles:true}));
}
/**
 * Handles Tab indentation behavior for code-mode source editors.
 *
 * Single carets insert two spaces, multiline selections indent or outdent the
 * selected block, and the preview is refreshed after the synthetic input event.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {KeyboardEvent} event Keydown event.
 * @returns {boolean} Whether the event was handled.
 */
function dpPanelEditorHandleCodeKeydown(editor,event){
	if(!editor||!event||String(editor.dataset.dpPanelEditor||"").toLowerCase()!=="code"){return false;}
	if(event.key!=="Tab"){return false;}
	var source=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-editor-source]") : null;
	if(!source||source.readOnly||source.disabled){return false;}
	event.preventDefault();
	var indent="  ";
	var start=source.selectionStart||0;
	var end=source.selectionEnd||0;
	var value=source.value||"";
	if(start!==end&&value.slice(start,end).indexOf("\n")!==-1){
		var lineStart=value.lastIndexOf("\n",start-1)+1;
		var selected=value.slice(lineStart,end);
		if(event.shiftKey){
			var outdented=selected.replace(/^(?: {1,2}|\t)/gm,"");
			source.value=value.slice(0,lineStart)+outdented+value.slice(end);
			source.setSelectionRange(lineStart,lineStart+outdented.length);
		}
		else{
			var indented=selected.replace(/^/gm,indent);
			source.value=value.slice(0,lineStart)+indented+value.slice(end);
			source.setSelectionRange(lineStart,lineStart+indented.length);
		}
	}
	else{
		source.value=value.slice(0,start)+indent+value.slice(end);
		source.setSelectionRange(start+indent.length,start+indent.length);
	}
	source.dispatchEvent(new Event("input",{bubbles:true}));
	dpPanelEditorRenderPreview(editor);
	return true;
}
/**
 * Continues or exits markdown list and quote blocks on Enter.
 *
 * Empty marker lines terminate the block, while active list and quote lines create
 * the next marker with ordered list numbering advanced.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {KeyboardEvent} event Keydown event.
 * @returns {boolean} Whether the event was handled.
 */
function dpPanelEditorHandleMarkdownEnter(editor,event){
	if(!editor||!event||event.key!=="Enter"||event.shiftKey||event.ctrlKey||event.metaKey||event.altKey){return false;}
	if(String(editor.dataset.dpPanelEditor||"").toLowerCase()!=="markdown"){return false;}
	var source=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-editor-source]") : null;
	if(!source||source.readOnly||source.disabled){return false;}
	var start=source.selectionStart||0;
	var end=source.selectionEnd||0;
	if(start!==end){return false;}
	var value=source.value||"";
	var lineStart=value.lastIndexOf("\n",Math.max(0,start-1))+1;
	var line=value.slice(lineStart,start);
	var emptyList=line.match(/^(\s*)(?:[-*]|\d+\.)\s*$/);
	var emptyQuote=line.match(/^(\s*)>\s*$/);
	if(emptyList||emptyQuote){
		event.preventDefault();
		source.value=value.slice(0,lineStart)+value.slice(start);
		source.setSelectionRange(lineStart,lineStart);
		source.dispatchEvent(new Event("input",{bubbles:true}));
		dpPanelEditorRenderPreview(editor);
		return true;
	}
	var unordered=line.match(/^(\s*)([-*])\s+\S/);
	var ordered=line.match(/^(\s*)(\d+)\.\s+\S/);
	var quote=line.match(/^(\s*)>\s+\S/);
	var continuation="";
	if(unordered){continuation="\n"+unordered[1]+unordered[2]+" ";}
	else if(ordered){continuation="\n"+ordered[1]+(parseInt(ordered[2],10)+1)+". ";}
	else if(quote){continuation="\n"+quote[1]+"> ";}
	if(!continuation){return false;}
	event.preventDefault();
	source.value=value.slice(0,start)+continuation+value.slice(end);
	source.setSelectionRange(start+continuation.length,start+continuation.length);
	source.dispatchEvent(new Event("input",{bubbles:true}));
	dpPanelEditorRenderPreview(editor);
	return true;
}
/**
 * Indents or outdents selected markdown list and quote lines with Tab.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {KeyboardEvent} event Keydown event.
 * @returns {boolean} Whether the event was handled.
 */
function dpPanelEditorHandleMarkdownTab(editor,event){
	if(!editor||!event||event.key!=="Tab"){return false;}
	if(String(editor.dataset.dpPanelEditor||"").toLowerCase()!=="markdown"){return false;}
	var source=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-editor-source]") : null;
	if(!source||source.readOnly||source.disabled){return false;}
	var value=source.value||"";
	var start=source.selectionStart||0;
	var end=source.selectionEnd||0;
	var lineStart=value.lastIndexOf("\n",Math.max(0,start-1))+1;
	var lineEnd=value.indexOf("\n",end);
	if(lineEnd===-1){lineEnd=value.length;}
	var selected=value.slice(lineStart,lineEnd);
	if(!/^\s*(?:[-*]|\d+\.|>)\s+/m.test(selected)){return false;}
	event.preventDefault();
	var transformed=event.shiftKey
		? selected.replace(/^( {1,2}|\t)(?=\s*(?:[-*]|\d+\.|>)\s+)/gm,"")
		: selected.replace(/^(?=\s*(?:[-*]|\d+\.|>)\s+)/gm,"  ");
	source.value=value.slice(0,lineStart)+transformed+value.slice(lineEnd);
	source.setSelectionRange(lineStart,lineStart+transformed.length);
	source.dispatchEvent(new Event("input",{bubbles:true}));
	dpPanelEditorRenderPreview(editor);
	return true;
}
/**
 * Executes a toolbar command against the source textarea representation.
 *
 * The command layer adapts markup to HTML/rich or markdown modes, mutates the
 * textarea selection, and relies on input events for downstream synchronization.
 *
 * @param {HTMLTextAreaElement} textarea Source editor textarea.
 * @param {string} mode Editor content mode.
 * @param {string} command Toolbar command identifier.
 * @returns {void}
 */
function dpPanelEditorApplySourceCommand(textarea,mode,command){
	var htmlMode=mode==="html"||mode==="rich_editor"||mode==="rich_text";
	if(command==="undo"){document.execCommand("undo",false,null);return;}
	if(command==="redo"){document.execCommand("redo",false,null);return;}
	if(command==="bold"){dpPanelEditorWrapSelection(textarea,htmlMode?"<strong>":"**",htmlMode?"</strong>":"**","bold text");}
	else if(command==="italic"){dpPanelEditorWrapSelection(textarea,htmlMode?"<em>":"*",htmlMode?"</em>":"*","italic text");}
	else if(command==="underline"){dpPanelEditorWrapSelection(textarea,htmlMode?"<u>":"<u>",htmlMode?"</u>":"</u>","underlined text");}
	else if(command==="strike"){dpPanelEditorWrapSelection(textarea,htmlMode?"<s>":"~~",htmlMode?"</s>":"~~","struck text");}
	else if(command==="heading"||command==="heading_2"){htmlMode ? dpPanelEditorWrapSelection(textarea,"<h2>","</h2>","Heading") : dpPanelEditorPrefixLines(textarea,"## ","Heading");}
	else if(command==="heading_1"){htmlMode ? dpPanelEditorWrapSelection(textarea,"<h1>","</h1>","Heading") : dpPanelEditorPrefixLines(textarea,"# ","Heading");}
	else if(command==="heading_3"){htmlMode ? dpPanelEditorWrapSelection(textarea,"<h3>","</h3>","Heading") : dpPanelEditorPrefixLines(textarea,"### ","Heading");}
	else if(command==="paragraph"){if(htmlMode){dpPanelEditorWrapSelection(textarea,"<p>","</p>","Paragraph");}}
	else if(command==="link"){
		if(htmlMode){
			var htmlUrl=dpPanelEditorPromptLinkUrl("https://");
			if(htmlUrl){dpPanelEditorWrapSelection(textarea,'<a href="'+dpPanelEscapeHtml(htmlUrl)+'">',"</a>","link text");}
		}
		else{dpPanelEditorMarkdownLinkSelection(textarea);}
	}
	else if(command==="unlink"){dpPanelEditorUnlinkSourceSelection(textarea);}
	else if(command==="unordered_list"){htmlMode ? dpPanelEditorWrapSelection(textarea,"<ul><li>","</li></ul>","List item") : dpPanelEditorPrefixLines(textarea,"- ","List item");}
	else if(command==="ordered_list"){htmlMode ? dpPanelEditorWrapSelection(textarea,"<ol><li>","</li></ol>","List item") : dpPanelEditorPrefixLines(textarea,"1. ","List item");}
	else if(command==="quote"){htmlMode ? dpPanelEditorWrapSelection(textarea,"<blockquote>","</blockquote>","Quote") : dpPanelEditorPrefixLines(textarea,"> ","Quote");}
	else if(command==="code"){dpPanelEditorWrapSelection(textarea,htmlMode?"<code>":"`",htmlMode?"</code>":"`","code");}
	else if(command==="code_block"){dpPanelEditorWrapSelection(textarea,htmlMode?"<pre><code>":"\n```\n",htmlMode?"</code></pre>":"\n```\n","code");}
	else if(command==="hr"){dpPanelEditorReplaceSelection(textarea,htmlMode?"<hr>":"\n---\n");}
	else if(command==="clear_format"){
		var selected=textarea.value.slice(textarea.selectionStart||0,textarea.selectionEnd||0);
		if(selected){dpPanelEditorReplaceSelection(textarea,selected.replace(/<\/?[^>]+>/g,"").replace(/[*_~`>#-]/g,""));}
	}
}
/**
 * Executes a toolbar command against the contenteditable visual surface.
 *
 * Native execCommand operations are followed by Panel sanitization and source
 * synchronization. Collapsed selections in non-empty editors receive whole-block
 * replacements for commands that otherwise depend on browser-specific behavior.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {string} command Toolbar command identifier.
 * @returns {void}
 */
function dpPanelEditorApplyVisualCommand(editor,command){
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(!visual||visual.getAttribute("contenteditable")==="false"){return;}
	visual.focus();
	dpPanelEditorRestoreSelection(editor);
	if(command==="undo"){document.execCommand("undo",false,null);dpPanelEditorSyncFromVisual(editor);return;}
	if(command==="redo"){document.execCommand("redo",false,null);dpPanelEditorSyncFromVisual(editor);return;}
	if(command==="clear_format"){document.execCommand("removeFormat",false,null);dpPanelEditorSyncFromVisual(editor);return;}
	if(command==="unlink"){document.execCommand("unlink",false,null);dpPanelEditorSyncFromVisual(editor);return;}
	if(command==="hr"){document.execCommand("insertHTML",false,"<hr>");dpPanelEditorSyncFromVisual(editor);return;}
	if(editor._dpPanelEditorRange&&editor._dpPanelEditorRange.collapsed&&visual.innerHTML.trim()!==""){
		var current=dpPanelEditorSanitizeRichHtml(editor,visual.innerHTML);
		if(command==="bold"){visual.innerHTML="<strong>"+current+"</strong>";}
		else if(command==="italic"){visual.innerHTML="<em>"+current+"</em>";}
		else if(command==="underline"){visual.innerHTML="<u>"+current+"</u>";}
		else if(command==="strike"){visual.innerHTML="<s>"+current+"</s>";}
		else if(command==="quote"){visual.innerHTML="<blockquote>"+current+"</blockquote>";}
		else if(command==="code"||command==="code_block"){visual.innerHTML="<pre><code>"+dpPanelEscapeHtml(visual.textContent||"")+"</code></pre>";}
		else if(command==="unordered_list"){visual.innerHTML="<ul><li>"+dpPanelEscapeHtml(visual.textContent||"")+"</li></ul>";}
		else if(command==="ordered_list"){visual.innerHTML="<ol><li>"+dpPanelEscapeHtml(visual.textContent||"")+"</li></ol>";}
		else if(command==="heading"||command==="heading_2"){visual.innerHTML="<h2>"+dpPanelEscapeHtml(visual.textContent||dpPanelText("client.heading","Heading"))+"</h2>";}
		else if(command==="heading_1"){visual.innerHTML="<h1>"+dpPanelEscapeHtml(visual.textContent||dpPanelText("client.heading","Heading"))+"</h1>";}
		else if(command==="heading_3"){visual.innerHTML="<h3>"+dpPanelEscapeHtml(visual.textContent||dpPanelText("client.heading","Heading"))+"</h3>";}
		else if(command==="paragraph"){visual.innerHTML="<p>"+dpPanelEscapeHtml(visual.textContent||dpPanelText("client.paragraph","Paragraph"))+"</p>";}
		dpPanelEditorSyncFromVisual(editor);
		dpPanelEditorRefreshCommandState(editor);
		return;
	}
	if(command==="heading"||command==="heading_2"){document.execCommand("formatBlock",false,"h2");}
	else if(command==="heading_1"){document.execCommand("formatBlock",false,"h1");}
	else if(command==="heading_3"){document.execCommand("formatBlock",false,"h3");}
	else if(command==="paragraph"){document.execCommand("formatBlock",false,"p");}
	else if(command==="bold"){document.execCommand("bold",false,null);}
	else if(command==="italic"){document.execCommand("italic",false,null);}
	else if(command==="underline"){document.execCommand("underline",false,null);}
	else if(command==="strike"){document.execCommand("strikeThrough",false,null);}
	else if(command==="unordered_list"){document.execCommand("insertUnorderedList",false,null);}
	else if(command==="ordered_list"){document.execCommand("insertOrderedList",false,null);}
	else if(command==="quote"){document.execCommand("formatBlock",false,"blockquote");}
	else if(command==="code"){document.execCommand("formatBlock",false,"code");}
	else if(command==="code_block"){document.execCommand("formatBlock",false,"pre");}
	else if(command==="link"){
		var element=dpPanelEditorSelectionElement(editor);
		var href=dpPanelEditorPromptLinkUrl(element&&element.closest("a") ? element.closest("a").getAttribute("href") : "https://");
		if(href){document.execCommand("createLink",false,href);}
	}
	if((command==="bold"||command==="italic"||command==="underline"||command==="strike")&&visual.innerHTML.trim()!==""){
		var tag=command==="bold" ? "strong" : (command==="italic" ? "em" : (command==="underline" ? "u" : "s"));
		if(visual.innerHTML.indexOf("<"+tag)===-1){
			visual.innerHTML="<"+tag+">"+dpPanelEditorSanitizeRichHtml(editor,visual.innerHTML)+"</"+tag+">";
		}
	}
	dpPanelEditorSyncFromVisual(editor);
	dpPanelEditorRefreshCommandState(editor);
}
/**
 * Dispatches editor keyboard shortcuts to mode-specific handlers or toolbar commands.
 *
 * The handler covers code indentation, markdown continuations, visual markdown
 * shortcuts, and common formatting shortcuts such as bold, italic, underline,
 * strike, and link.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {KeyboardEvent} event Keydown event.
 * @returns {boolean} Whether the event was handled.
 */
function dpPanelEditorHandleShortcut(editor,event){
	if(!editor||!event){return false;}
	if(dpPanelEditorHandleCodeKeydown(editor,event)){return true;}
	if(dpPanelEditorHandleMarkdownEnter(editor,event)){return true;}
	if(dpPanelEditorHandleMarkdownTab(editor,event)){return true;}
	if(dpPanelEditorHandleVisualMarkdownShortcut(editor,event)){return true;}
	var key=String(event.key||"").toLowerCase();
	var command="";
	if((event.ctrlKey||event.metaKey)&&key==="b"){command="bold";}
	else if((event.ctrlKey||event.metaKey)&&key==="i"){command="italic";}
	else if((event.ctrlKey||event.metaKey)&&key==="u"){command="underline";}
	else if((event.ctrlKey||event.metaKey)&&event.shiftKey&&key==="x"){command="strike";}
	else if((event.ctrlKey||event.metaKey)&&key==="k"){command="link";}
	else{return false;}
	event.preventDefault();
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
	return true;
}
/**
 * Gets the active selection range when it belongs to the visual editor.
 *
 * @param {HTMLElement|null} visual Contenteditable visual editor surface.
 * @returns {Range|null} Active range inside the visual editor.
 */
function dpPanelEditorSelectionRangeInVisual(visual){
	if(!visual||!window.getSelection){return null;}
	var selection=window.getSelection();
	if(!selection||selection.rangeCount<1){return null;}
	var range=selection.getRangeAt(0);
	if(!visual.contains(range.commonAncestorContainer)){return null;}
	return range;
}
/**
 * Finds the editable block element containing the current visual selection.
 *
 * @param {HTMLElement} visual Contenteditable visual editor surface.
 * @returns {Element|null} Current text block, or the visual root as fallback.
 */
function dpPanelEditorCurrentTextBlock(visual){
	var range=dpPanelEditorSelectionRangeInVisual(visual);
	if(!range){return null;}
	var node=range.startContainer;
	if(node&&node.nodeType===3){node=node.parentElement;}
	while(node&&node!==visual){
		if(node.matches&&node.matches("p,div,h1,h2,h3,h4,h5,h6,blockquote,li,pre")){return node;}
		node=node.parentElement;
	}
	return visual;
}
/**
 * Moves the browser caret to the end of a node after visual block replacement.
 *
 * @param {Node|null} node Node that should receive the caret.
 * @returns {void}
 */
function dpPanelEditorSetCaretInside(node){
	if(!node||!window.getSelection||!document.createRange){return;}
	var range=document.createRange();
	range.selectNodeContents(node);
	range.collapse(false);
	var selection=window.getSelection();
	selection.removeAllRanges();
	selection.addRange(range);
}
/**
 * Replaces a visual editor block with sanitized HTML and synchronizes editor state.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {Element} block Existing block or visual root to replace.
 * @param {string} html Replacement block HTML.
 * @returns {boolean} Whether a replacement was inserted.
 */
function dpPanelEditorReplaceBlock(editor,block,html){
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(!visual||!block){return false;}
	var template=document.createElement("template");
	template.innerHTML=dpPanelEditorSanitizeRichHtml(editor,html);
	var replacement=template.content.firstElementChild;
	if(!replacement){return false;}
	if(block===visual){
		visual.innerHTML="";
		visual.appendChild(replacement);
	}
	else{
		block.replaceWith(replacement);
	}
	dpPanelEditorSetCaretInside(replacement);
	dpPanelEditorSyncFromVisual(editor);
	return true;
}
/**
 * Converts markdown-style leading markers typed in the visual editor into blocks.
 *
 * A space after markers such as `#`, `-`, `1.`, `>`, or triple backticks replaces
 * the current text block with the corresponding rich HTML structure.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {KeyboardEvent} event Keydown event.
 * @returns {boolean} Whether the shortcut was handled.
 */
function dpPanelEditorHandleVisualMarkdownShortcut(editor,event){
	if(!editor||editor.dataset.dpPanelEditorMode!=="write"||event.key!==" "||event.ctrlKey||event.metaKey||event.altKey){return false;}
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(!visual||visual.getAttribute("contenteditable")==="false"){return false;}
	var range=dpPanelEditorSelectionRangeInVisual(visual);
	if(!range||!range.collapsed){return false;}
	var block=dpPanelEditorCurrentTextBlock(visual);
	if(!block){return false;}
	var text=String(block.textContent||"").replace(/\u00a0/g,"");
	var marker=text.trim();
	var replacement="";
	if(/^#{1,6}$/.test(marker)){
		replacement="<h"+Math.min(6,marker.length)+"><br></h"+Math.min(6,marker.length)+">";
	}
	else if(marker==="-"||marker==="*"){
		replacement="<ul><li><br></li></ul>";
	}
	else if(/^1\.$/.test(marker)){
		replacement="<ol><li><br></li></ol>";
	}
	else if(marker===">"){
		replacement="<blockquote><br></blockquote>";
	}
	else if(marker==="```"){
		replacement="<pre><code><br></code></pre>";
	}
	else{return false;}
	event.preventDefault();
	return dpPanelEditorReplaceBlock(editor,block,replacement);
}
/**
 * Routes visual editor paste through the same sanitizer boundary as typing.
 *
 * Markdown mode prefers plain text and renders it through the markdown subset,
 * HTML paste is sanitized directly, and plain text paste is promoted through rich
 * normalization before insertion.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {ClipboardEvent} event Paste event.
 * @returns {void}
 */
function dpPanelEditorHandlePaste(editor,event){
	var visual=event.target&&event.target.closest ? event.target.closest("[data-dp-panel-editor-visual]") : null;
	if(!visual||visual.getAttribute("contenteditable")==="false"){return;}
	var clipboard=event.clipboardData||window.clipboardData;
	if(!clipboard||typeof clipboard.getData!=="function"){return;}
	var html=clipboard.getData("text/html");
	var text=clipboard.getData("text/plain");
	var mode=String(editor.dataset.dpPanelEditor||"plain").toLowerCase();
	event.preventDefault();
	visual.focus();
	dpPanelEditorRestoreSelection(editor);
	if(mode==="markdown"&&text){
		document.execCommand("insertHTML",false,dpPanelSanitizeRichHtml(dpPanelMarkdownToHtml(text)));
	}
	else if(html){
		document.execCommand("insertHTML",false,dpPanelSanitizeRichHtml(html));
	}
	else{
		var normalizedText=dpPanelSanitizeRichHtml(dpPanelEditorNormalizeRichHtml(text||""));
		document.execCommand(normalizedText ? "insertHTML" : "insertText",false,normalizedText||"");
	}
	dpPanelEditorSyncFromVisual(editor);
	dpPanelEditorRefreshCommandState(editor);
}
/**
 * Stores the current visual selection range for toolbar actions after focus moves.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorRememberSelection(editor){
	if(!editor||!window.getSelection){return;}
	if(editor.dataset.dpPanelEditorMode==="preview"){return;}
	var selection=window.getSelection();
	if(!selection||selection.rangeCount<1){return;}
	var range=selection.getRangeAt(0);
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(visual&&visual.contains(range.commonAncestorContainer)){
		editor._dpPanelEditorRange=range.cloneRange();
	}
}
/**
 * Restores the saved visual editor selection or creates a caret fallback.
 *
 * The saved range is accepted only while it still belongs to the visual surface,
 * which prevents stale ranges from affecting another editor instance.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorRestoreSelection(editor){
	if(!editor||!window.getSelection){return;}
	var selection=window.getSelection();
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var savedRange=editor._dpPanelEditorRange;
	var savedNode=savedRange ? savedRange.commonAncestorContainer : null;
	var savedValid=!!(savedRange&&visual&&savedNode&&(visual===savedNode||visual.contains(savedNode)));
	if(!savedValid){
		if(visual&&visual.firstChild){
			var fallback=document.createRange();
			fallback.selectNodeContents(visual);
			fallback.collapse(false);
			editor._dpPanelEditorRange=fallback;
		}
	}
	if(!editor._dpPanelEditorRange){return;}
	selection.removeAllRanges();
	selection.addRange(editor._dpPanelEditorRange);
}
/**
 * Switches an editor between write, source, and preview presentations.
 *
 * Mode changes synchronize visual/source content as needed, hide inactive shells,
 * update toggle state, and refresh empty, status, and toolbar command indicators.
 *
 * @param {HTMLElement} editor Editor shell.
 * @param {string} mode Requested editor mode.
 * @returns {void}
 */
function dpPanelSetEditorMode(editor,mode){
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	var source=editor.querySelector("[data-dp-panel-editor-source]");
	var sourceShell=source ? (source.closest("[data-dp-panel-input-shell],.dp-panel-input-shell")||source) : null;
	var preview=editor.querySelector(".dp-panel-editor-preview");
	var hasWritePreview=!!editor.querySelector("[data-dp-panel-editor-view='write']");
	if(mode==="visual"){mode="write";}
	if(mode==="source"&&hasWritePreview){mode="write";}
	if(mode==="preview"&&!preview){mode="write";}
	if(mode!=="preview"&&mode!=="source"){mode="write";}
	var previousMode=editor.dataset.dpPanelEditorMode||"";
	if(mode==="preview"){
		if(visual&&previousMode==="write"){dpPanelEditorSyncFromVisual(editor);}
		dpPanelEditorRenderPreview(editor);
	}
	else if(mode==="write"){
		if(visual){dpPanelEditorSyncToVisual(editor);}
		else{dpPanelEditorRenderPreview(editor);}
	}
	editor.dataset.dpPanelEditorMode=mode;
	if(visual){visual.hidden=mode!=="write";}
	if(sourceShell){sourceShell.hidden=(hasWritePreview&&mode==="preview")||!!visual;}
	if(preview&&hasWritePreview){preview.hidden=mode!=="preview";}
	editor.querySelectorAll("[data-dp-panel-editor-view]").forEach(function(toggle){
		var active=toggle.dataset.dpPanelEditorView===mode;
		toggle.dataset.dpPanelFieldButtonState=active ? "active" : "";
		toggle.setAttribute("aria-pressed", active ? "true" : "false");
	});
	dpPanelEditorRefreshEmptyState(editor);
	dpPanelEditorRefreshStatus(editor);
	dpPanelEditorRefreshCommandState(editor);
}
/**
 * Flushes the active editor presentation into source, preview, and status state.
 *
 * This is used before form submission or external reads so contenteditable edits
 * cannot remain only in the visual DOM.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelEditorCommit(editor){
	if(!editor){return;}
	if(window.DataphyrePanelEditors&&typeof window.DataphyrePanelEditors.sync==="function"&&window.DataphyrePanelEditors.sync(editor)){
		dpPanelEditorRenderPreview(editor);
		dpPanelEditorRefreshEmptyState(editor);
		dpPanelEditorRefreshStatus(editor);
		return;
	}
	var visual=editor.querySelector("[data-dp-panel-editor-visual]");
	if(visual&&editor.dataset.dpPanelEditorMode!=="preview"){
		dpPanelEditorSyncFromVisual(editor);
	}
	else{
		dpPanelEditorRenderPreview(editor);
		dpPanelEditorRefreshEmptyState(editor);
		dpPanelEditorRefreshStatus(editor);
	}
}
/**
 * Initializes a single rich editor shell and binds synchronization events.
 *
 * Initialization is idempotent per element, configures paragraph behavior where
 * supported, performs the initial source-to-visual sync, and attaches input,
 * keyboard, paste, focus, mouse, and selection tracking handlers.
 *
 * @param {HTMLElement|null} editor Editor shell.
 * @returns {void}
 */
function dpPanelInitRichEditor(editor){
	if(!editor||editor.dataset.dpPanelEditorReady==="1"){return;}
	editor.dataset.dpPanelEditorReady="1";
	var initialSource=editor.querySelector("[data-dp-panel-editor-source]");
	if(initialSource){dpPanelEditorSeedMediaReferences(editor,initialSource.value||"");}
	try{document.execCommand("defaultParagraphSeparator",false,"p");}catch(error){}
	dpPanelEditorSyncToVisual(editor);
	dpPanelSetEditorMode(editor,editor.dataset.dpPanelEditorMode||"source");
	dpPanelEditorRenderPreview(editor);
	dpPanelEditorRefreshStatus(editor);
	editor.addEventListener("input",function(event){
		if(event.target&&event.target.closest("[data-dp-panel-editor-visual]")){dpPanelEditorSyncFromVisual(editor);}
		if(event.target&&event.target.closest("[data-dp-panel-editor-source]")){
			dpPanelEditorRenderPreview(editor);
		}
		dpPanelEditorRefreshEmptyState(editor);
		dpPanelEditorRefreshCommandState(editor);
	});
	editor.addEventListener("keydown",function(event){dpPanelEditorHandleShortcut(editor,event);});
	editor.addEventListener("paste",function(event){dpPanelEditorHandlePaste(editor,event);});
	editor.addEventListener("keyup",function(){dpPanelEditorRememberSelection(editor);dpPanelEditorRefreshCommandState(editor);});
	editor.addEventListener("mouseup",function(){dpPanelEditorRememberSelection(editor);dpPanelEditorRefreshCommandState(editor);});
	editor.addEventListener("focusin",function(){dpPanelEditorRefreshCommandState(editor);});
	editor.addEventListener("focusout",function(){dpPanelEditorRememberSelection(editor);});
	if(typeof dpPanelEditorInitProfile==="function"){dpPanelEditorInitProfile(editor);}
}
/**
 * Initializes all Panel rich editor shells below a root element.
 *
 * @param {ParentNode|null} root Root search scope, defaulting to document.
 * @returns {void}
 */
function dpPanelInitRichEditors(root){
	(root||document).querySelectorAll("[data-dp-panel-editor]").forEach(dpPanelInitRichEditor);
}
window.DataphyrePanel=window.DataphyrePanel||{};
window.DataphyrePanel.editorRuntime={
	version:1,
	init:dpPanelInitRichEditors,
	allowMediaReference:dpPanelEditorAllowMediaReference,
	sanitizeRichHtml:dpPanelEditorSanitizeRichHtml,
	htmlToMarkdown:dpPanelEditorHtmlToMarkdown,
	restoreSelection:dpPanelEditorRestoreSelection,
	syncFromVisual:dpPanelEditorSyncFromVisual,
	replaceSelection:dpPanelEditorReplaceSelection,
	renderPreview:dpPanelEditorRenderPreview
};
/**
 * Applies browser pattern validity and Panel semantic validity to an input.
 *
 * Native pattern messages keep precedence when the pattern attribute fails and a
 * title is available. Otherwise semantic format rules provide the validation
 * message used by custom formatted fields.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement|null} input Field with optional format metadata.
 * @returns {void}
 */
JS;
	}

}
