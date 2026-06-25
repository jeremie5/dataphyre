<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Audits rendered Panel HTML for accessibility regressions.
 *
 * The audit is a lightweight, dependency-free test helper for server-rendered
 * PanelPageResult instances. It parses the response when DOMDocument is
 * available, falls back to conservative string checks otherwise, and reports
 * issues, metrics, and a score for tests, regression reports, or Flightdeck diagnostics.
 */
final class PanelAccessibilityAudit implements \JsonSerializable {

	private string $html;
	private array $issues=[];
	private array $metrics=[];
	private array $meta=[];

	/**
	 * Creates and immediately runs an accessibility audit.
	 *
	 * @param PanelPageResult|string $result Rendered Panel result or raw HTML surface.
	 * @param array<string, mixed> $meta Caller metadata preserved in serialized output.
	 */
	private function __construct(PanelPageResult|string $result, array $meta=[]) {
		$this->html=$result instanceof PanelPageResult ? $result->content() : $result;
		$this->meta=$meta;
		$this->run();
	}

	/**
	 * Builds an audit for rendered Panel output.
	 *
	 * @param PanelPageResult|string $result Rendered Panel result or raw HTML surface.
	 * @param array<string, mixed> $meta Caller metadata preserved in serialized output.
	 * @return self Completed audit instance.
	 */
	public static function from(PanelPageResult|string $result, array $meta=[]): self {
		return new self($result, $meta);
	}

	/**
	 * Reports whether the audit has no error-severity findings.
	 *
	 * Warnings reduce the score but do not fail the audit, which lets CI or
	 * dashboards distinguish blocking accessibility defects from quality hints.
	 *
	 * @return bool True when no error findings were recorded.
	 */
	public function passed(): bool {
		return $this->issueCount('error')===0;
	}

	/**
	 * Calculates a coarse accessibility score from recorded findings.
	 *
	 * Errors carry a heavier penalty than warnings. The score is intentionally
	 * capped at zero rather than throwing so it remains safe for dashboards and
	 * serialized test reports.
	 *
	 * @return int Score from 0 to 100.
	 */
	public function score(): int {
		$errors=$this->issueCount('error');
		$warnings=$this->issueCount('warning');
		return max(0, 100 - ($errors * 15) - ($warnings * 4));
	}

	/**
	 * Returns recorded audit issues, optionally filtered by severity.
	 *
	 * @param ?string $severity One of `error`, `warning`, `info`, or null for all severities.
	 * @return array<int, array{severity: string, rule: string, message: string, context: array<string, mixed>}> Audit issues in discovery order.
	 */
	public function issues(?string $severity=null): array {
		if($severity===null){
			return $this->issues;
		}
		return array_values(array_filter($this->issues, static fn(array $issue): bool => ($issue['severity'] ?? '')===$severity));
	}

	/**
	 * Counts audit issues, optionally by severity.
	 *
	 * @param ?string $severity One of `error`, `warning`, `info`, or null for all severities.
	 * @return int Number of matching issues.
	 */
	public function issueCount(?string $severity=null): int {
		return count($this->issues($severity));
	}

	/**
	 * Returns structural counters gathered during the audit.
	 *
	 * @return array<string, int> Counts for buttons, links, images, inputs, dialogs, ARIA references, live regions, duplicate ids, and reduced-motion hooks.
	 */
	public function metrics(): array {
		return $this->metrics;
	}

	/**
	 * Returns the serializable audit report.
	 *
	 * @return array{type: string, passed: bool, score: int, issue_count: int, error_count: int, warning_count: int, metrics: array<string, int>, issues: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'type'=>'panel_accessibility_audit',
			'passed'=>$this->passed(),
			'score'=>$this->score(),
			'issue_count'=>count($this->issues),
			'error_count'=>$this->issueCount('error'),
			'warning_count'=>$this->issueCount('warning'),
			'metrics'=>$this->metrics,
			'issues'=>$this->issues,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the audit report for JSON consumers.
	 *
	 * @return array{type: string, passed: bool, score: int, issue_count: int, error_count: int, warning_count: int, metrics: array<string, int>, issues: array<int, array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Runs DOM, fallback, and shell-level accessibility checks.
	 *
	 * Metrics are reset on every run so the constructor can safely call this once
	 * without inheriting state from previous audits.
	 *
	 * @return void
	 */
	private function run(): void {
		$this->metrics=[
			'buttons'=>0,
			'links'=>0,
			'images'=>0,
			'inputs'=>0,
			'dialogs'=>0,
			'aria_references'=>0,
			'live_regions'=>0,
			'duplicate_ids'=>0,
			'reduced_motion_hooks'=>0,
		];
		$dom=$this->dom();
		if($dom instanceof \DOMDocument){
			$this->auditDom($dom);
		}
		else {
			$this->auditFallback();
		}
		$this->auditCssAndShell();
	}

	/**
	 * Parses the rendered HTML into a DOMDocument when the DOM extension exists.
	 *
	 * Libxml errors are isolated to this method so malformed snippets can be
	 * audited without leaking parser warnings into tests or Panel responses.
	 *
	 * @return ?\DOMDocument Parsed DOM, or null when parsing is unavailable.
	 */
	private function dom(): ?\DOMDocument {
		if(!class_exists('\DOMDocument')){
			return null;
		}
		$dom=new \DOMDocument();
		$previous=libxml_use_internal_errors(true);
		$loaded=$dom->loadHTML('<?xml encoding="UTF-8">'.$this->html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $loaded ? $dom : null;
	}

	/**
	 * Performs DOM-based accessibility checks.
	 *
	 * Checks include duplicate ids, accessible names for interactive controls,
	 * image alt handling, input labelling, dialog labels, live-region values, and
	 * ARIA reference integrity.
	 *
	 * @param \DOMDocument $dom Parsed Panel HTML.
	 * @return void
	 */
	private function auditDom(\DOMDocument $dom): void {
		$xpath=new \DOMXPath($dom);
		$ids=[];
		foreach($xpath->query('//*[@id]') ?: [] as $node){
			$id=trim((string)$node->getAttribute('id'));
			if($id===''){
				continue;
			}
			$ids[$id]=($ids[$id] ?? 0)+1;
		}
		foreach($ids as $id=>$count){
			if($count>1){
				$this->metrics['duplicate_ids']++;
				$this->issue('error', 'duplicate_id', 'Duplicate element id.', ['id'=>$id, 'count'=>$count]);
			}
		}
		foreach($xpath->query('//button') ?: [] as $button){
			$this->metrics['buttons']++;
			if(!$this->hasAccessibleName($button, $ids)){
				$this->issue('error', 'button_name', 'Button has no accessible name.', ['html'=>$this->nodeSample($button)]);
			}
		}
		foreach($xpath->query('//a[@href]') ?: [] as $link){
			$this->metrics['links']++;
			if(!$this->hasAccessibleName($link, $ids)){
				$this->issue('warning', 'link_name', 'Link has no accessible name.', ['href'=>$link->getAttribute('href')]);
			}
		}
		foreach($xpath->query('//img') ?: [] as $image){
			$this->metrics['images']++;
			if(!$image->hasAttribute('alt') && $image->getAttribute('aria-hidden')!=='true'){
				$this->issue('error', 'image_alt', 'Image is missing alt text or aria-hidden.', ['src'=>$image->getAttribute('src')]);
			}
		}
		foreach($xpath->query('//input[not(@type="hidden")] | //select | //textarea') ?: [] as $control){
			$this->metrics['inputs']++;
			if(!$this->inputHasLabel($control, $xpath, $ids)){
				$this->issue('error', 'input_label', 'Form control has no label, aria-label, aria-labelledby, title, or placeholder.', [
					'name'=>$control->getAttribute('name'),
					'type'=>$control->getAttribute('type'),
				]);
			}
		}
		foreach($xpath->query('//*[@role="dialog" or @role="alertdialog"]') ?: [] as $dialog){
			$this->metrics['dialogs']++;
			if($dialog->getAttribute('aria-modal')!=='true'){
				$this->issue('warning', 'dialog_modal', 'Dialog should declare aria-modal="true".', ['html'=>$this->nodeSample($dialog)]);
			}
			if(!$dialog->hasAttribute('aria-labelledby') && !$dialog->hasAttribute('aria-label')){
				$this->issue('warning', 'dialog_label', 'Dialog should be labelled by heading or aria-label.', ['html'=>$this->nodeSample($dialog)]);
			}
		}
		foreach($xpath->query('//*[@aria-live]') ?: [] as $live){
			$this->metrics['live_regions']++;
			$value=strtolower(trim($live->getAttribute('aria-live')));
			if(!in_array($value, ['polite', 'assertive', 'off'], true)){
				$this->issue('warning', 'aria_live_value', 'aria-live should be polite, assertive, or off.', ['value'=>$value]);
			}
		}
		foreach(['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-activedescendant'] as $attribute){
			foreach($xpath->query('//*[@'.$attribute.']') ?: [] as $node){
				foreach(preg_split('/\s+/', trim($node->getAttribute($attribute))) ?: [] as $id){
					if($id===''){
						continue;
					}
					$this->metrics['aria_references']++;
					if(!isset($ids[$id])){
						$this->issue('error', 'aria_reference', 'ARIA reference points to a missing id.', [
							'attribute'=>$attribute,
							'id'=>$id,
						]);
					}
				}
			}
		}
	}

	/**
	 * Runs conservative string-based checks when DOMDocument is unavailable.
	 *
	 * The fallback deliberately checks only high-confidence button and image
	 * failures so missing parser support does not create excessive false positives.
	 *
	 * @return void
	 */
	private function auditFallback(): void {
		if(preg_match_all('/<button\b[^>]*>(.*?)<\/button>/is', $this->html, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){
				$this->metrics['buttons']++;
				$source=$match[0];
				$text=trim(strip_tags($match[1]));
				if($text==='' && !preg_match('/aria-label\s*=/i', $source) && !preg_match('/title\s*=/i', $source)){
					$this->issue('error', 'button_name', 'Button has no accessible name.', ['html'=>substr($source, 0, 180)]);
				}
			}
		}
		if(preg_match_all('/<img\b[^>]*>/i', $this->html, $matches)){
			foreach($matches[0] as $source){
				$this->metrics['images']++;
				if(!preg_match('/\salt\s*=/i', $source) && !preg_match('/aria-hidden\s*=\s*["\']true["\']/i', $source)){
					$this->issue('error', 'image_alt', 'Image is missing alt text or aria-hidden.', ['html'=>substr($source, 0, 180)]);
				}
			}
		}
	}

	/**
	 * Audits shell-level affordances that are visible in raw HTML and CSS.
	 *
	 * This catches missing reduced-motion hooks and live regions that may not be
	 * associated with a single DOM node in the main audit pass.
	 *
	 * @return void
	 */
	private function auditCssAndShell(): void {
		if(str_contains($this->html, 'prefers-reduced-motion')){
			$this->metrics['reduced_motion_hooks']++;
		}
		else {
			$this->issue('warning', 'reduced_motion', 'No prefers-reduced-motion hook was found in the rendered surface.');
		}
		if(!str_contains($this->html, 'aria-live')){
			$this->issue('warning', 'live_region', 'No live region was found for async updates or notifications.');
		}
	}

	/**
	 * Determines whether an element exposes an accessible name.
	 *
	 * Text content, aria-label, title, alt, and aria-labelledby references are
	 * accepted. The method does not resolve hidden text semantics; it is intended
	 * as a pragmatic Panel regression check rather than a full accessibility tree.
	 *
	 * @param \DOMElement $node Element to inspect.
	 * @param array<string, int> $ids Known ids from the document.
	 * @return bool True when the element has an accessible name source.
	 */
	private function hasAccessibleName(\DOMElement $node, array $ids): bool {
		$text=trim(preg_replace('/\s+/', ' ', $node->textContent ?? ''));
		if($text!==''){
			return true;
		}
		foreach(['aria-label', 'title', 'alt'] as $attribute){
			if(trim($node->getAttribute($attribute))!==''){
				return true;
			}
		}
		$labelledby=trim($node->getAttribute('aria-labelledby'));
		if($labelledby!==''){
			foreach(preg_split('/\s+/', $labelledby) ?: [] as $id){
				if(isset($ids[$id])){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Determines whether a form control has a usable label.
	 *
	 * In addition to accessible-name sources, placeholders, `label[for]`, and
	 * ancestor label wrappers are accepted because Panel forms use all of those
	 * patterns across compact controls.
	 *
	 * @param \DOMElement $control Input, select, or textarea element.
	 * @param \DOMXPath $xpath XPath helper for label lookups.
	 * @param array<string, int> $ids Known ids from the document.
	 * @return bool True when the form control is labelled.
	 */
	private function inputHasLabel(\DOMElement $control, \DOMXPath $xpath, array $ids): bool {
		if($this->hasAccessibleName($control, $ids)){
			return true;
		}
		if(trim($control->getAttribute('placeholder'))!==''){
			return true;
		}
		$id=trim($control->getAttribute('id'));
		if($id!=='' && (($xpath->query('//label[@for="'.str_replace('"', '\"', $id).'"]')?->length ?? 0)>0)){
			return true;
		}
		$parent=$control->parentNode;
		while($parent instanceof \DOMElement){
			if(strtolower($parent->tagName)==='label'){
				return true;
			}
			$parent=$parent->parentNode;
		}
		return false;
	}

	/**
	 * Returns a compact HTML sample for issue context.
	 *
	 * @param \DOMElement $node Element that triggered an issue.
	 * @return string Whitespace-normalized HTML sample capped for diagnostics.
	 */
	private function nodeSample(\DOMElement $node): string {
		$html=$node->ownerDocument?->saveHTML($node) ?: '';
		return substr(trim(preg_replace('/\s+/', ' ', $html)), 0, 220);
	}

	/**
	 * Records an audit issue with normalized severity.
	 *
	 * @param string $severity Requested severity.
	 * @param string $rule Stable rule identifier.
	 * @param string $message Human-readable finding.
	 * @param array<string, mixed> $context Context values useful for diagnostics.
	 * @return void
	 */
	private function issue(string $severity, string $rule, string $message, array $context=[]): void {
		$this->issues[]=[
			'severity'=>in_array($severity, ['error', 'warning', 'info'], true) ? $severity : 'warning',
			'rule'=>$rule,
			'message'=>$message,
			'context'=>$context,
		];
	}
}
