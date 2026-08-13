<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** DOM-based fail-closed HTML sanitizer for rich editor persistence. */
final class PanelEditorHtmlSanitizer implements PanelEditorSanitizer, \JsonSerializable {
	private const MEDIA_ELEMENTS=['img','picture','source','video','audio','track'];
	private const DROP_CONTENT_ELEMENTS=['script','style','iframe','object','embed','svg','math','template','noscript','base','link','meta','form','input','button','select','textarea','option','frame','frameset','portal'];
	private const URL_ATTRIBUTES=['href','src','poster','cite','action','formaction','xlink:href','background','longdesc','usemap','manifest','profile'];
	private const MULTI_URL_ATTRIBUTES=['srcset','imagesrcset','ping'];
	public function __construct(private bool $enabled=true) {}

	public function name(): string { return 'dom_allow_list'; }
	public function ready(): bool { return $this->enabled && class_exists(\DOMDocument::class); }

	public function sanitize(string $content, PanelEditorSanitizationPolicy $policy, PanelEditorContext $context, ?PanelEditorMediaAdapter $media=null): PanelEditorContentResult {
		if(!$this->ready()){
			return PanelEditorContentResult::reject('', 'Server-side DOM sanitization is unavailable.', $content!=='');
		}
		if(preg_match('//u', $content)!==1){
			return PanelEditorContentResult::reject('', 'Editor HTML is not valid UTF-8.', true);
		}
		if(strlen($content)>$policy->byteLimit()){
			return PanelEditorContentResult::reject('', 'Editor HTML exceeds the byte limit.', true);
		}
		$dom=new \DOMDocument('1.0', 'UTF-8');
		$flags=LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING;
		$previous=libxml_use_internal_errors(true);
		$loaded=$dom->loadHTML('<!doctype html><html><body><div id="dp-panel-editor-policy-root">'.$content.'</div></body></html>', $flags);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if(!$loaded){ return PanelEditorContentResult::reject('', 'Editor HTML could not be parsed.', $content!==''); }
		$root=$dom->getElementById('dp-panel-editor-policy-root');
		if(!$root instanceof \DOMElement){ return PanelEditorContentResult::reject('', 'Editor HTML escaped its policy boundary.', $content!==''); }

		$violations=[]; $warnings=[]; $nodes=0;
		foreach(iterator_to_array($root->childNodes) as $child){ $this->sanitizeNode($child, $root, 1, $nodes, $policy, $context, $media, $violations); }
		$body=$dom->getElementsByTagName('body')->item(0);
		if($body instanceof \DOMElement){
			foreach(iterator_to_array($body->childNodes) as $sibling){
				if($sibling!==$root && !($sibling instanceof \DOMText && trim($sibling->textContent)==='')){ $violations[]='Markup escaped the editor root.'; $sibling->parentNode?->removeChild($sibling); }
			}
		}
		$head=$dom->getElementsByTagName('head')->item(0);
		if($head instanceof \DOMElement){
			foreach(iterator_to_array($head->childNodes) as $outside){
				if(!($outside instanceof \DOMText && trim($outside->textContent)==='')){ $violations[]='Markup escaped into the document head.'; $outside->parentNode?->removeChild($outside); }
			}
		}
		$output=''; foreach($root->childNodes as $child){ $output.=$dom->saveHTML($child); }
		$violations=array_values(array_unique($violations));
		if($violations!==[] && $policy->rejectsUnsafe()){
			return PanelEditorContentResult::reject($output, $violations, $output!==$content, ['sanitizer'=>$this->name()]);
		}
		if($violations!==[]){ $warnings=$violations; }
		return PanelEditorContentResult::accept($output, $output!==$content, $warnings, ['sanitizer'=>$this->name()]);
	}

	public function manifest(): array { return ['name'=>$this->name(), 'ready'=>$this->ready(), 'engine'=>'dom', 'trusted_html_input'=>false]; }
	public function jsonSerialize(): array { return $this->manifest(); }

	private function sanitizeNode(\DOMNode $node, \DOMElement $root, int $depth, int &$nodes, PanelEditorSanitizationPolicy $policy, PanelEditorContext $context, ?PanelEditorMediaAdapter $media, array &$violations): void {
		$nodes++;
		if($nodes>$policy->nodeLimit()){ $violations[]='Editor HTML exceeds the node limit.'; $node->parentNode?->removeChild($node); return; }
		if($depth>$policy->depthLimit()){ $violations[]='Editor HTML exceeds the nesting limit.'; $node->parentNode?->removeChild($node); return; }
		if($node instanceof \DOMComment){ if($policy->stripsComments()){ $node->parentNode?->removeChild($node); } return; }
		if($node instanceof \DOMText){ return; }
		if(!$node instanceof \DOMElement){ $violations[]='Unsupported editor markup node was removed.'; $node->parentNode?->removeChild($node); return; }

		$tag=strtolower($node->tagName);
		$allowed=$policy->allowedElements();
		if(in_array($tag, self::DROP_CONTENT_ELEMENTS, true)){
			$violations[]='The <'.$tag.'> element is not allowed.'; $node->parentNode?->removeChild($node); return;
		}
		$isMedia=in_array($tag, self::MEDIA_ELEMENTS, true);
		if($isMedia && ($media===null || !$media->ready())){
			$violations[]='Embedded media requires an explicit ready media adapter.'; $node->parentNode?->removeChild($node); return;
		}
		if(!array_key_exists($tag, $allowed)){
			$violations[]='The <'.$tag.'> element is not allowed.';
			foreach(iterator_to_array($node->childNodes) as $child){ $this->sanitizeNode($child, $root, $depth+1, $nodes, $policy, $context, $media, $violations); }
			$parent=$node->parentNode;
			if($parent!==null){ while($node->firstChild!==null){ $parent->insertBefore($node->firstChild, $node); } $parent->removeChild($node); }
			return;
		}

		$allowedAttributes=$allowed[$tag];
		foreach(iterator_to_array($node->attributes) as $attribute){
			$name=strtolower($attribute->name); $value=$attribute->value;
			if(!in_array($name, $allowedAttributes, true)){
				$violations[]='The '.$name.' attribute is not allowed on <'.$tag.'>.'; $node->removeAttributeNode($attribute); continue;
			}
			if(in_array($name, self::MULTI_URL_ATTRIBUTES, true)){
				$violations[]='The '.$name.' attribute requires a dedicated URL-set adapter.'; $node->removeAttributeNode($attribute); continue;
			}
			if(in_array($name, self::URL_ATTRIBUTES, true)){
				$normalized=$isMedia ? $media?->normalizeReference($value, $context) : $policy->normalizeUrl($value);
				if($normalized===null){ $violations[]='An unsafe URL was removed from <'.$tag.'>.'; $node->removeAttributeNode($attribute); continue; }
				$node->setAttribute($name, $normalized);
			}
		}
		if($tag==='a' && $node->hasAttribute('target')){
			$target=strtolower(trim($node->getAttribute('target')));
			if(!in_array($target, ['_blank','_self'], true)){ $violations[]='An unsafe link target was removed.'; $node->removeAttribute('target'); }
			elseif($target==='_blank'){
				$relations=preg_split('/\s+/', strtolower(trim($node->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				foreach(['noopener','noreferrer'] as $relation){ if(!in_array($relation, $relations, true)){ $relations[]=$relation; } }
				$node->setAttribute('rel', implode(' ', $relations));
			}
		}
		foreach(iterator_to_array($node->childNodes) as $child){ $this->sanitizeNode($child, $root, $depth+1, $nodes, $policy, $context, $media, $violations); }
	}
}
