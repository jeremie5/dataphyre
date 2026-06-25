<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders structured Panel template sections into escaped HTML fragments.
 *
 * PanelPageTemplate is the lightweight schema renderer behind custom panel
 * pages. It accepts arrays describing hero blocks, lists, tables, forms, chat
 * views, realtime clients, and fallback content, then applies consistent
 * escaping, tone normalization, URL sanitization, and translated default text.
 */
final class PanelPageTemplate implements \Stringable {

	private array $sections;

	/**
	 * Stores normalized section definitions for later rendering.
	 *
	 * @param array<int, array<string, mixed>> $sections Ordered template section definitions.
	 */
	private function __construct(array $sections) {
		$this->sections=$sections;
	}

	/**
	 * Creates a template from structured section definitions.
	 *
	 * @param array<int, array<string, mixed>> $sections Ordered section definitions.
	 * @return self Immutable template renderer.
	 */
	public static function make(array $sections): self {
		return new self($sections);
	}

	/**
	 * Renders the template when the value is cast to a string.
	 *
	 * @return string Escaped panel template HTML.
	 */
	public function __toString(): string {
		return $this->render();
	}

	/**
	 * Renders every recognized template section into one wrapper element.
	 *
	 * Unknown or missing section types fall back to the generic section renderer.
	 * Non-array entries are skipped so callers can build section lists
	 * conditionally without pre-filtering.
	 *
	 * @return string HTML fragment, or an empty string when no sections render output.
	 */
	public function render(): string {
		$html='';
		foreach($this->sections as $section){
			if(!is_array($section) || $section===[]){
				continue;
			}
			$html.=match((string)($section['type'] ?? 'section')){
				'hero'=>self::hero($section),
				'stats', 'metrics'=>self::stats($section),
				'action_list', 'quick_actions'=>self::actionList($section),
				'activity_grid'=>self::activityGrid($section),
				'card_grid'=>self::cardGrid($section),
				'color_swatches', 'swatches'=>self::colorSwatches($section),
				'description_list', 'facts'=>self::descriptionList($section),
				'confidential_fields', 'sensitive_fields'=>self::confidentialFields($section),
				'record_list', 'list'=>self::recordList($section),
				'chat', 'conversation'=>self::chat($section),
				'table', 'data_table'=>self::table($section),
				'form'=>self::form($section),
				'empty'=>self::emptyState($section),
				'notice'=>self::notice($section),
				'realtime_client'=>self::realtimeClient($section),
				'document_content'=>self::documentContent($section),
				default=>self::section($section),
			};
		}
		return $html!=='' ? '<div class="dp-panel-template">'.$html.'</div>' : '';
	}

	/**
	 * Renders the high-emphasis hero section with optional actions and aside facts.
	 *
	 * @param array<string, mixed> $section Section with title, body, actions, and aside entries.
	 * @return string Escaped hero HTML.
	 */
	private static function hero(array $section): string {
		$actions='';
		foreach(self::list($section['actions'] ?? []) as $action){
			$href=self::href($action['url'] ?? $action['href'] ?? '#');
			$actions.='<a class="dp-panel-template-button" href="'.self::e($href).'">'.self::e((string)($action['label'] ?? self::text('common.open', 'Open'))).'</a>';
		}
		$aside='';
		foreach(self::list($section['aside'] ?? []) as $item){
			$aside.='<aside class="dp-panel-template-fact">'
				.'<span>'.self::e((string)($item['label'] ?? '')).'</span>'
				.'<strong>'.self::e((string)($item['value'] ?? '')).'</strong>'
				.(((string)($item['detail'] ?? ''))!=='' ? '<small>'.self::e((string)$item['detail']).'</small>' : '')
				.'</aside>';
		}
		return '<section class="dp-panel-template-hero">'
			.'<div class="dp-panel-template-heading">'.self::eyebrow($section).'<h2>'.self::e((string)($section['title'] ?? '')).'</h2>'.self::body($section).'</div>'
			.($actions!=='' ? '<div class="dp-panel-template-actions">'.$actions.'</div>' : '')
			.$aside
			.'</section>';
	}

	/**
	 * Renders metric cards from item definitions.
	 *
	 * @param array<string, mixed> $section Section containing items with label, value, detail, and tone.
	 * @return string Stats section HTML, or an empty string when no items are present.
	 */
	private static function stats(array $section): string {
		$items='';
		foreach(self::list($section['items'] ?? []) as $item){
			$tone=self::tone((string)($item['tone'] ?? 'neutral'));
			$items.='<article class="dp-panel-template-stat dp-panel-template-tone-'.$tone.'">'
				.'<span>'.self::e((string)($item['label'] ?? '')).'</span>'
				.'<strong>'.self::e((string)($item['value'] ?? '0')).'</strong>'
				.(((string)($item['detail'] ?? ''))!=='' ? '<small>'.self::e((string)$item['detail']).'</small>' : '')
				.'</article>';
		}
		return $items!=='' ? '<section class="dp-panel-template-stats">'.$items.'</section>' : '';
	}

	/**
	 * Renders a list of linked action cards.
	 *
	 * @param array<string, mixed> $section Section with title/body and action items.
	 * @return string Action list HTML.
	 */
	private static function actionList(array $section): string {
		$items='';
		foreach(self::list($section['items'] ?? []) as $item){
			$items.='<a class="dp-panel-template-action-card" href="'.self::e(self::href($item['url'] ?? $item['href'] ?? '#')).'">'
				.'<strong>'.self::e((string)($item['title'] ?? $item['label'] ?? '')).'</strong>'
				.(((string)($item['body'] ?? $item['description'] ?? ''))!=='' ? '<span>'.self::e((string)($item['body'] ?? $item['description'])).'</span>' : '')
				.'</a>';
		}
		return '<section class="dp-panel-template-section dp-panel-template-action-list">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<div>'.$items.'</div>'
			.'</section>';
	}

	/**
	 * Renders grouped activity panels.
	 *
	 * Empty panels receive an empty-state placeholder so dashboards keep their
	 * layout even when live records have not arrived.
	 *
	 * @param array<string, mixed> $section Section containing panel definitions.
	 * @return string Activity grid HTML, or an empty string when no panels exist.
	 */
	private static function activityGrid(array $section): string {
		$panels='';
		foreach(self::list($section['panels'] ?? []) as $panel){
			$rows='';
			foreach(self::list($panel['items'] ?? []) as $item){
				$rows.='<a class="dp-panel-template-activity-row" href="'.self::e(self::href($item['url'] ?? $item['href'] ?? '#')).'">'
					.'<span><strong>'.self::e((string)($item['title'] ?? '')).'</strong>'
					.(((string)($item['subtitle'] ?? $item['body'] ?? ''))!=='' ? '<small>'.self::e((string)($item['subtitle'] ?? $item['body'])).'</small>' : '').'</span>'
					.(((string)($item['meta'] ?? ''))!=='' ? '<em>'.self::e((string)$item['meta']).'</em>' : '')
					.'</a>';
			}
			if($rows===''){
				$rows=self::emptyState([
					'title'=>(string)($panel['empty_title'] ?? self::text('template.empty_title', 'Nothing to show yet.')),
					'body'=>(string)($panel['empty_body'] ?? self::text('template.empty_live_data', 'Live data will appear here when records exist.')),
				], 'div');
			}
			$tone=self::tone((string)($panel['tone'] ?? 'neutral'));
			$panels.='<section class="dp-panel-template-activity dp-panel-template-tone-'.$tone.'">'
				.'<header>'.self::eyebrow($panel).'<strong>'.self::e((string)($panel['title'] ?? '')).'</strong><i aria-hidden="true"></i></header>'
				.$rows
				.'</section>';
		}
		return $panels!=='' ? '<section class="dp-panel-template-activity-grid">'.$panels.'</section>' : '';
	}

	/**
	 * Renders a card grid with optional tags and per-card links.
	 *
	 * @param array<string, mixed> $section Section containing item cards and optional heading copy.
	 * @return string Card grid HTML, or an empty string when there are no cards.
	 */
	private static function cardGrid(array $section): string {
		$cards='';
		foreach(self::list($section['items'] ?? []) as $item){
			$tags='';
			foreach(self::list($item['tags'] ?? $item['items'] ?? []) as $tag){
				$tags.='<li>'.self::e((string)$tag).'</li>';
			}
			$cards.='<article class="dp-panel-template-card">'
				.'<a href="'.self::e(self::href($item['url'] ?? $item['href'] ?? '#')).'"><strong>'.self::e((string)($item['title'] ?? '')).'</strong><span>'.self::e((string)($item['action_label'] ?? self::text('common.open', 'Open'))).'</span></a>'
				.(((string)($item['body'] ?? ''))!=='' ? '<p>'.self::e((string)$item['body']).'</p>' : '')
				.($tags!=='' ? '<ul>'.$tags.'</ul>' : '')
				.'</article>';
		}
		$header=((string)($section['eyebrow'] ?? $section['title'] ?? $section['body'] ?? $section['description'] ?? ''))!==''
			? '<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			: '';
		return $cards!=='' ? '<section class="dp-panel-template-section dp-panel-template-card-grid-section">'
			.$header
			.'<div class="dp-panel-template-card-grid">'.$cards.'</div>'
			.'</section>' : '';
	}

	/**
	 * Renders label/value/detail facts as a description-list styled section.
	 *
	 * @param array<string, mixed> $section Section containing fact items.
	 * @return string Description list HTML.
	 */
	private static function descriptionList(array $section): string {
		$items='';
		foreach(self::list($section['items'] ?? []) as $item){
			$items.='<div class="dp-panel-template-description-item">'
				.'<span>'.self::e((string)($item['label'] ?? '')) .'</span>'
				.'<strong>'.self::e((string)($item['value'] ?? '')) .'</strong>'
				.(((string)($item['detail'] ?? ''))!=='' ? '<small>'.self::e((string)$item['detail']).'</small>' : '')
				.'</div>';
		}
		return '<section class="dp-panel-template-section dp-panel-template-description-list">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<div>'.$items.'</div>'
			.'</section>';
	}

	/**
	 * Renders labeled color values as accessible visual swatches.
	 *
	 * The value is normalized to a six-character hex color before it is used in
	 * inline style output. Invalid or empty values render as text-only items so
	 * callers can safely pass partially configured brand palettes.
	 *
	 * @param array<string, mixed> $section Section containing color swatch items.
	 * @return string Color swatch section HTML.
	 */
	private static function colorSwatches(array $section): string {
		$items='';
		foreach(self::list($section['items'] ?? []) as $item){
			$value=self::hexColor((string)($item['value'] ?? ''));
			$style=$value!=='' ? ' style="--dp-panel-template-swatch:'.self::e($value).'"' : '';
			$items.='<article class="dp-panel-template-color-swatch"'.$style.'>'
				.($value!=='' ? '<i aria-hidden="true"></i>' : '')
				.'<span><strong>'.self::e((string)($item['label'] ?? '')).'</strong>'
				.'<code>'.self::e($value!=='' ? $value : (string)($item['value'] ?? '')).'</code>'
				.(((string)($item['detail'] ?? ''))!=='' ? '<small>'.self::e((string)$item['detail']).'</small>' : '')
				.'</span>'
				.'</article>';
		}
		return '<section class="dp-panel-template-section dp-panel-template-color-swatches">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<div>'.$items.'</div>'
			.'</section>';
	}

	/**
	 * Renders auditable confidential-value disclosures with fail-closed masking.
	 *
	 * Values are only rendered when the caller marks an item as revealed. Hidden
	 * items render placeholders and optional POST reveal actions so product code
	 * can enforce its own policy checks and audit writes before disclosure.
	 *
	 * @param array<string, mixed> $section Section containing confidential field items.
	 * @return string Confidential field section HTML.
	 */
	private static function confidentialFields(array $section): string {
		$items='';
		foreach(self::list($section['items'] ?? []) as $item){
			$revealed=!empty($item['revealed']);
			$value=(string)($revealed ? ($item['value'] ?? '') : ($item['placeholder'] ?? self::text('template.confidential_placeholder', 'Hidden until access is logged')));
			$tone=self::tone((string)($item['tone'] ?? ($revealed ? 'success' : 'warning')));
			$meta=(string)($item['meta'] ?? $item['detail'] ?? '');
			$items.='<article class="dp-panel-template-confidential-field dp-panel-template-tone-'.$tone.'" data-dp-panel-confidential-field="'.($revealed ? 'revealed' : 'hidden').'">'
				.'<span><strong>'.self::e((string)($item['label'] ?? '')).'</strong>'
				.($meta!=='' ? '<small>'.self::e($meta).'</small>' : '').'</span>'
				.'<code>'.self::e($value).'</code>';
			if(!$revealed && is_array($item['action'] ?? null)){
				$action=$item['action'];
				$method=strtolower((string)($action['method'] ?? 'post'));
				$method=in_array($method, ['get', 'post'], true) ? $method : 'post';
				$href=self::href($action['url'] ?? $action['href'] ?? '#');
				$label=(string)($action['label'] ?? self::text('template.confidential_request_access', 'Request access'));
				if($method==='post'){
					$hidden_action=$action;
					if(!array_key_exists('csrf', $hidden_action)){
						$hidden_action['csrf']=true;
					}
					$action_fields='';
					foreach(self::list($action['fields'] ?? []) as $action_field){
						$action_fields.=self::formField($action_field);
					}
					$items.='<form method="post" action="'.self::e($href).'" data-dp-panel-no-ajax="1">'.self::hiddenFields($hidden_action).($action_fields!=='' ? '<div class="dp-panel-template-confidential-action-fields">'.$action_fields.'</div>' : '').'<button class="dp-panel-template-button dp-panel-template-button-warning" type="submit">'.self::e($label).'</button></form>';
				}
				else {
					$items.='<a class="dp-panel-template-button dp-panel-template-button-warning" href="'.self::e($href).'">'.self::e($label).'</a>';
				}
			}
			$items.='</article>';
		}
		if($items===''){
			$items=self::emptyState([
				'title'=>(string)($section['empty_title'] ?? self::text('template.no_confidential_fields', 'No confidential fields available.')),
				'body'=>(string)($section['empty_body'] ?? ''),
			], 'div');
		}
		return '<section class="dp-panel-template-section dp-panel-template-confidential-fields">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<div>'.$items.'</div>'
			.'</section>';
	}

	/**
	 * Renders record summary rows with optional actions and footer metadata.
	 *
	 * @param array<string, mixed> $section Section containing record items, actions, and empty copy.
	 * @return string Record list HTML.
	 */
	private static function recordList(array $section): string {
		$items='';
		foreach(self::list($section['items'] ?? []) as $item){
			$tone=self::tone((string)($item['tone'] ?? 'neutral'));
			$items.='<article class="dp-panel-template-record dp-panel-template-tone-'.$tone.'">'
				.'<span><strong>'.self::e((string)($item['title'] ?? $item['label'] ?? '')).'</strong>'
				.(((string)($item['subtitle'] ?? $item['body'] ?? ''))!=='' ? '<small>'.self::e((string)($item['subtitle'] ?? $item['body'])).'</small>' : '').'</span>'
				.(((string)($item['value'] ?? ''))!=='' ? '<b>'.self::e((string)$item['value']).'</b>' : '')
				.'</article>';
		}
		if($items===''){
			$items=self::emptyState([
				'title'=>(string)($section['empty_title'] ?? self::text('template.empty_title', 'Nothing to show yet.')),
				'body'=>(string)($section['empty_body'] ?? ''),
			], 'div');
		}
		$actions='';
		foreach(self::list($section['actions'] ?? []) as $action){
			$actions.='<a class="dp-panel-template-button" href="'.self::e(self::href($action['url'] ?? $action['href'] ?? '#')).'">'.self::e((string)($action['label'] ?? self::text('common.open', 'Open'))).'</a>';
		}
		$meta=(string)($section['meta'] ?? '');
		return '<section class="dp-panel-template-section dp-panel-template-record-list">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<div>'.$items.'</div>'
			.($actions!=='' || $meta!=='' ? '<footer>'.($actions!=='' ? '<div class="dp-panel-template-actions">'.$actions.'</div>' : '').($meta!=='' ? '<span>'.self::e($meta).'</span>' : '').'</footer>' : '')
			.'</section>';
	}

	/**
	 * Renders a two-pane chat or conversation section.
	 *
	 * The section may include conversations, messages, a composer form, and
	 * realtime client attributes. Composer hidden fields reuse the same CSRF and
	 * hidden-field contract as generic form sections.
	 *
	 * @param array<string, mixed> $section Chat section definition.
	 * @return string Chat section HTML.
	 */
	private static function chat(array $section): string {
		$conversations='';
		foreach(self::list($section['conversations'] ?? []) as $conversation){
			$href=self::href($conversation['url'] ?? $conversation['href'] ?? '#');
			$active=!empty($conversation['active']);
			$unread=max(0, (int)($conversation['unread'] ?? $conversation['unread_count'] ?? 0));
			$conversations.='<a class="dp-panel-template-chat-conversation'.($active ? ' active' : '').'" href="'.self::e($href).'"'.($active ? ' aria-current="page"' : '').'>'
				.'<span><strong>'.self::e((string)($conversation['title'] ?? $conversation['label'] ?? '')).'</strong>'
				.(((string)($conversation['subtitle'] ?? $conversation['body'] ?? ''))!=='' ? '<small>'.self::e((string)($conversation['subtitle'] ?? $conversation['body'])).'</small>' : '').'</span>'
				.($unread>0 ? '<b>'.self::e((string)$unread).'</b>' : (((string)($conversation['meta'] ?? ''))!=='' ? '<em>'.self::e((string)$conversation['meta']).'</em>' : ''))
				.'</a>';
		}
		if($conversations===''){
			$conversations=self::emptyState([
				'title'=>(string)($section['empty_conversations_title'] ?? self::text('template.no_conversations', 'No conversations yet.')),
				'body'=>(string)($section['empty_conversations_body'] ?? ''),
			], 'div');
		}

		$messages='';
		foreach(self::list($section['messages'] ?? []) as $message){
			$own=!empty($message['own']);
			$message_id=(int)($message['id'] ?? 0);
			$messages.='<article class="dp-panel-template-chat-message'.($own ? ' own' : '').'"'.($message_id>0 ? ' data-dp-panel-message-id="'.self::e((string)$message_id).'"' : '').'>'
				.'<div>'
				.'<strong>'.self::e((string)($message['sender'] ?? $message['title'] ?? 'System')).'</strong>'
				.'<p>'.self::e((string)($message['body'] ?? $message['subtitle'] ?? '')).'</p>'
				.(((string)($message['time'] ?? $message['meta'] ?? ''))!=='' ? '<small>'.self::e((string)($message['time'] ?? $message['meta'])).'</small>' : '')
				.'</div>'
				.'</article>';
		}
		if($messages===''){
			$messages=self::emptyState([
				'title'=>(string)($section['empty_messages_title'] ?? self::text('template.no_messages', 'No messages yet.')),
				'body'=>(string)($section['empty_messages_body'] ?? ''),
			], 'div');
		}

		$composer='';
		if(is_array($section['composer'] ?? null)){
			$composer_config=$section['composer'];
			$method=strtolower((string)($composer_config['method'] ?? 'post'));
			$method=in_array($method, ['get', 'post'], true) ? $method : 'post';
			$action=self::href($composer_config['action'] ?? '#');
			$field_name=(string)($composer_config['field_name'] ?? 'message');
			$placeholder=(string)($composer_config['placeholder'] ?? '');
			$label=(string)($composer_config['label'] ?? $placeholder);
			$button=(string)($composer_config['button'] ?? self::text('common.send', 'Send'));
			$disabled=!empty($composer_config['disabled']);
			$hidden=self::hiddenFields($composer_config);
			$composer='<form class="dp-panel-template-chat-composer" method="'.$method.'" action="'.self::e($action).'"'.(!empty($composer_config['no_ajax']) ? ' data-dp-panel-no-ajax="1"' : '').'>'
				.$hidden
				.'<label><span class="dp-panel-sr-only">'.self::e($label).'</span><textarea name="'.self::e($field_name).'" placeholder="'.self::e($placeholder).'" maxlength="'.self::e((string)(int)($composer_config['maxlength'] ?? 5000)).'"'.($disabled ? ' disabled' : '').'></textarea></label>'
				.'<button class="dp-panel-template-button" type="submit"'.($disabled ? ' disabled' : '').'>'.self::e($button).'</button>'
				.'</form>';
		}

		$realtime=is_array($section['realtime'] ?? null) ? $section['realtime'] : [];
		$attributes=[
			'data-dp-panel-template-chat'=>'1',
		];
		if(($realtime['client'] ?? '')!==''){
			$attributes['data-dp-panel-realtime']=(string)$realtime['client'];
		}
		foreach(['channel'=>'data-dp-panel-channel', 'token'=>'data-dp-panel-realtime-token', 'websocket'=>'data-dp-panel-websocket', 'current_user_id'=>'data-dp-panel-current-user-id'] as $key=>$attribute){
			$value=(string)($realtime[$key] ?? '');
			if($value!==''){
				$attributes[$attribute]=$value;
			}
		}
		$htmlAttributes='';
		foreach($attributes as $attribute=>$value){
			$htmlAttributes.=' '.$attribute.'="'.self::e($value).'"';
		}

		return '<section class="dp-panel-template-section dp-panel-template-chat"'.$htmlAttributes.'>'
			.'<aside class="dp-panel-template-chat-list"><header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['list_title'] ?? self::text('template.conversations', 'Conversations'))).'</strong></header><div>'.$conversations.'</div></aside>'
			.'<main class="dp-panel-template-chat-thread">'
			.'<header><span><strong>'.self::e((string)($section['title'] ?? self::text('template.conversation', 'Conversation'))).'</strong>'.self::body($section, 'small').'</span><em data-dp-panel-chat-state>'.self::e((string)($section['state_label'] ?? '')).'</em></header>'
			.'<div class="dp-panel-template-chat-messages" data-dp-panel-chat-messages>'.$messages.'</div>'
			.$composer
			.'</main>'
			.'</section>';
	}

	/**
	 * Renders a data table with optional section actions.
	 *
	 * Columns define labels and lookup names. Row cell values may be plain values
	 * or action cell descriptors rendered by tableCell().
	 *
	 * @param array<string, mixed> $section Table section definition.
	 * @return string Table section HTML.
	 */
	private static function table(array $section): string {
		$columns=self::list($section['columns'] ?? []);
		$head='';
		foreach($columns as $column){
			$head.='<th scope="col">'.self::e((string)($column['label'] ?? $column['name'] ?? '')).'</th>';
		}
		$body='';
		foreach(self::list($section['rows'] ?? []) as $row){
			if(!is_array($row)){
				continue;
			}
			$cells='';
			foreach($columns as $index=>$column){
				$name=(string)($column['name'] ?? $index);
				$value=$row[$name] ?? $row[$index] ?? '';
				$tag=$index===0 ? 'th scope="row"' : 'td';
				$cells.='<'.$tag.'>'.self::tableCell($value).'</'.($index===0 ? 'th' : 'td').'>';
			}
			$body.='<tr>'.$cells.'</tr>';
		}
		if($body==='' && $columns!==[]){
			$body='<tr><td colspan="'.count($columns).'">'.self::e((string)($section['empty_title'] ?? self::text('template.no_records', 'No records available.'))).'</td></tr>';
		}
		$actions='';
		foreach(self::list($section['actions'] ?? []) as $action){
			$actions.='<a class="dp-panel-template-button" href="'.self::e(self::href($action['url'] ?? $action['href'] ?? '#')).'">'.self::e((string)($action['label'] ?? self::text('common.open', 'Open'))).'</a>';
		}
		return '<section class="dp-panel-template-section dp-panel-template-table">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.($actions!=='' ? '<div class="dp-panel-template-actions">'.$actions.'</div>' : '')
			.'<div class="dp-panel-template-table-scroll"><table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>'
			.'</section>';
	}

	/**
	 * Renders one table cell value.
	 *
	 * Action cells can render GET links or POST forms with optional CSRF hidden
	 * input. Plain values are escaped as text.
	 *
	 * @param mixed $value Scalar cell value or action-cell descriptor.
	 * @return string Escaped cell HTML.
	 */
	private static function tableCell(mixed $value): string {
		if(!is_array($value)){
			return self::e((string)$value);
		}
		$type=(string)($value['type'] ?? 'text');
		if(!in_array($type, ['actions', 'form_actions'], true)){
			return self::e((string)($value['label'] ?? $value['value'] ?? ''));
		}
		$actions='';
		foreach(self::list($value['actions'] ?? []) as $action){
			$method=strtolower((string)($action['method'] ?? 'get'));
			$method=in_array($method, ['get', 'post'], true) ? $method : 'post';
			$href=self::href($action['url'] ?? $action['href'] ?? '#');
			$label=(string)($action['label'] ?? self::text('common.open', 'Open'));
			$tone=self::tone((string)($action['tone'] ?? 'primary'));
			if($method==='post'){
				$hidden_action=$action;
				if(!array_key_exists('csrf', $hidden_action)){
					$hidden_action['csrf']=true;
				}
				$hidden=self::hiddenFields($hidden_action);
				$actions.='<form method="post" action="'.self::e($href).'" data-dp-panel-no-ajax="1">'.$hidden.'<button class="dp-panel-template-button dp-panel-template-button-'.$tone.'" type="submit">'.self::e($label).'</button></form>';
				continue;
			}
			$actions.='<a class="dp-panel-template-button dp-panel-template-button-'.$tone.'" href="'.self::e($href).'">'.self::e($label).'</a>';
		}
		return $actions!=='' ? '<div class="dp-panel-template-actions dp-panel-template-table-actions">'.$actions.'</div>' : '';
	}

	/**
	 * Renders a form section with fields, hidden inputs, actions, and badges.
	 *
	 * Only GET and POST methods are emitted. Hidden raw HTML is accepted for
	 * compatibility with trusted panel code; field labels and values are escaped.
	 *
	 * @param array<string, mixed> $section Form section definition.
	 * @return string Form section HTML.
	 */
	private static function form(array $section): string {
		$fields='';
		foreach(self::list($section['fields'] ?? []) as $field){
			$fields.=self::formField($field);
		}
		$actions='';
		foreach(self::list($section['actions'] ?? []) as $action){
			$type=in_array((string)($action['type'] ?? 'submit'), ['button', 'reset', 'submit'], true) ? (string)($action['type'] ?? 'submit') : 'submit';
			$buttonAttributes='';
			$buttonAttributes.=((string)($action['name'] ?? ''))!=='' ? ' name="'.self::e((string)$action['name']).'"' : '';
			$buttonAttributes.=((string)($action['value'] ?? ''))!=='' ? ' value="'.self::e((string)$action['value']).'"' : '';
			$actions.='<button class="dp-panel-template-button" type="'.$type.'"'.$buttonAttributes.(!empty($action['disabled']) ? ' disabled' : '').'>'.self::e((string)($action['label'] ?? self::text('common.save', 'Save'))).'</button>';
		}
		$badges='';
		foreach(self::list($section['badges'] ?? []) as $badge){
			$tone=self::tone((string)($badge['tone'] ?? 'neutral'));
			$badges.='<span class="dp-panel-template-badge dp-panel-template-tone-'.$tone.'">'.self::e((string)($badge['label'] ?? '')).'</span>';
		}
		$method=strtolower((string)($section['method'] ?? 'post'));
		$method=in_array($method, ['get', 'post'], true) ? $method : 'post';
		$action=self::href($section['action'] ?? '#');
		$hidden=self::hiddenFields($section);
		$class='dp-panel-template-section dp-panel-template-form-section'.(!empty($section['compact']) ? ' dp-panel-template-form-section-compact' : '');
		return '<section class="'.$class.'">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<form class="dp-panel-template-form" method="'.$method.'" action="'.self::e($action).'"'.(!empty($section['no_ajax']) ? ' data-dp-panel-no-ajax="1"' : '').'>'
			.$hidden.((string)($section['hidden_html'] ?? ''))
			.'<div class="dp-panel-template-form-grid">'.$fields.'</div>'
			.($actions!=='' || $badges!=='' ? '<footer>'.($actions!=='' ? '<div class="dp-panel-template-actions">'.$actions.'</div>' : '').($badges!=='' ? '<div class="dp-panel-template-badges">'.$badges.'</div>' : '').'</footer>' : '')
			.'</form>'
			.'</section>';
	}

	/**
	 * Builds hidden input fields for forms and POST action cells.
	 *
	 * A truthy csrf key adds the current MVC session token when available.
	 * Additional hidden_fields entries may be keyed scalars or name/value arrays.
	 *
	 * @param array<string, mixed> $section Section or action descriptor.
	 * @return string Hidden input HTML.
	 */
	private static function hiddenFields(array $section): string {
		$fields=[];
		if(!empty($section['csrf'])){
			$token=class_exists(\Dataphyre\Mvc\Session::class) ? \Dataphyre\Mvc\Session::token() : '';
			if($token!==''){
				$fields[]=['name'=>'_token', 'value'=>$token];
			}
		}
		$configured=is_array($section['hidden_fields'] ?? null) ? $section['hidden_fields'] : [];
		foreach($configured as $key=>$field){
			if(is_array($field)){
				$name=(string)($field['name'] ?? $key);
				$value=(string)($field['value'] ?? '');
			}
			else {
				$name=(string)$key;
				$value=(string)$field;
			}
			if($name===''){
				continue;
			}
			$fields[]=['name'=>$name, 'value'=>$value];
		}
		$html='';
		foreach($fields as $field){
			$html.='<input type="hidden" name="'.self::e((string)$field['name']).'" value="'.self::e((string)$field['value']).'">';
		}
		return $html;
	}

	/**
	 * Renders one form field descriptor.
	 *
	 * Supports hidden, checkbox, select/searchable select, textarea, and common
	 * HTML input types. Unknown input types fall back to text.
	 *
	 * @param array<string, mixed> $field Field descriptor.
	 * @return string Field HTML.
	 */
	private static function formField(array $field): string {
		$type=strtolower((string)($field['type'] ?? 'text'));
		$name=(string)($field['name'] ?? '');
		$label=(string)($field['label'] ?? $name);
		$value=(string)($field['value'] ?? '');
		$attributes=' name="'.self::e($name).'"';
		$attributes.=$value!=='' ? ' value="'.self::e($value).'"' : '';
		$attributes.=!empty($field['required']) ? ' required' : '';
		$attributes.=!empty($field['disabled']) ? ' disabled' : '';
		$attributes.=((string)($field['placeholder'] ?? ''))!=='' ? ' placeholder="'.self::e((string)$field['placeholder']).'"' : '';
		$attributes.=isset($field['maxlength']) ? ' maxlength="'.self::e((string)(int)$field['maxlength']).'"' : '';
		$attributes.=((string)($field['inputmode'] ?? ''))!=='' ? ' inputmode="'.self::e((string)$field['inputmode']).'"' : '';
		if($type==='hidden'){
			return '<input type="hidden"'.$attributes.'>';
		}
		$control='';
		if($type==='checkbox'){
			$checkboxValue=(string)($field['checkbox_value'] ?? '1');
			$checkboxAttributes=' name="'.self::e($name).'" value="'.self::e($checkboxValue).'"';
			$checkboxAttributes.=!empty($field['checked']) ? ' checked' : '';
			$checkboxAttributes.=!empty($field['required']) ? ' required' : '';
			$checkboxAttributes.=!empty($field['disabled']) ? ' disabled' : '';
			return '<label class="dp-panel-template-field dp-panel-template-field-checkbox'.(!empty($field['wide']) ? ' dp-panel-template-field-wide' : '').'"><input type="checkbox"'.$checkboxAttributes.'><span>'.self::e($label).'</span></label>';
		}
		if(in_array($type, ['select', 'relationship', 'belongs_to', 'relation', 'searchable_select'], true)){
			$options='';
			foreach(self::list($field['options'] ?? []) as $option){
				if(is_array($option['options'] ?? null)){
					$groupOptions='';
					foreach(self::list($option['options']) as $groupOption){
						$optionValue=(string)($groupOption['value'] ?? $groupOption['label'] ?? '');
						$groupOptions.='<option value="'.self::e($optionValue).'"'.($optionValue===$value ? ' selected' : '').(!empty($groupOption['disabled']) ? ' disabled' : '').'>'.self::e((string)($groupOption['label'] ?? $optionValue)).'</option>';
					}
					$options.='<optgroup label="'.self::e((string)($option['label'] ?? '')).'">'.$groupOptions.'</optgroup>';
					continue;
				}
				$optionValue=(string)($option['value'] ?? $option['label'] ?? '');
				$options.='<option value="'.self::e($optionValue).'"'.($optionValue===$value ? ' selected' : '').(!empty($option['disabled']) ? ' disabled' : '').'>'.self::e((string)($option['label'] ?? $optionValue)).'</option>';
			}
			$searchable=!empty($field['searchable']) || in_array($type, ['relationship', 'belongs_to', 'relation', 'searchable_select'], true);
			$selectAttributes=$attributes.($searchable ? ' data-dp-panel-searchable="1"' : '');
			$control='<select'.$selectAttributes.'>'.$options.'</select>';
			if($searchable){
				$control=self::searchableSelect($name, $label, $field, $control);
			}
		}
		elseif($type==='textarea'){
			$control='<textarea'.$attributes.'>'.self::e($value).'</textarea>';
		}
		else {
			$type=in_array($type, ['color', 'date', 'datetime-local', 'email', 'month', 'number', 'password', 'search', 'tel', 'text', 'time', 'url', 'week'], true) ? $type : 'text';
			$control='<input type="'.$type.'"'.$attributes.'>';
		}
		return '<label class="dp-panel-template-field'.(!empty($field['wide']) ? ' dp-panel-template-field-wide' : '').'"><span>'.self::e($label).'</span>'.$control.'</label>';
	}

	/**
	 * Wraps a select control with client-side search affordances.
	 *
	 * @param string $name Field name used to derive a stable search id.
	 * @param string $label Human field label.
	 * @param array<string, mixed> $field Field descriptor with optional search copy.
	 * @param string $selectHtml Already-rendered select element.
	 * @return string Searchable select wrapper HTML.
	 */
	private static function searchableSelect(string $name, string $label, array $field, string $selectHtml): string {
		$searchId='dp-panel-template-select-search-'.substr(sha1($name.'|'.$label), 0, 12);
		$placeholder=(string)($field['search_placeholder'] ?? self::text('template.search_options', 'Search options'));
		$noResults=(string)($field['no_results_text'] ?? self::text('template.no_matching_options', 'No matching options'));
		$searchLabel=self::text('template.search_field', '{label} search', ['label'=>$label]);
		$search='<label class="dp-panel-searchable-select-search" for="'.self::e($searchId).'"><span class="dp-panel-sr-only">'.self::e($searchLabel).'</span><input id="'.self::e($searchId).'" type="search" value="" placeholder="'.self::e($placeholder).'" autocomplete="off" data-dp-panel-searchable-select-input></label>';
		$status='<small class="dp-panel-searchable-select-status" data-dp-panel-searchable-select-status aria-live="polite"></small>';
		return '<div class="dp-panel-searchable-select" data-dp-panel-searchable-select="1" data-dp-panel-select-no-results="'.self::e($noResults).'">'.$search.$selectHtml.$status.'</div>';
	}

	/**
	 * Renders a generic content section.
	 *
	 * The content field is trusted HTML supplied by panel code; title, eyebrow,
	 * and body fields remain escaped.
	 *
	 * @param array<string, mixed> $section Generic section definition.
	 * @return string Section HTML.
	 */
	private static function section(array $section): string {
		return '<section class="dp-panel-template-section">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.((string)($section['content'] ?? ''))
			.'</section>';
	}

	/**
	 * Renders a notice section with normalized tone.
	 *
	 * @param array<string, mixed> $section Notice section definition.
	 * @return string Notice HTML.
	 */
	private static function notice(array $section): string {
		$tone=self::tone((string)($section['tone'] ?? 'neutral'));
		return '<section class="dp-panel-template-section dp-panel-template-notice dp-panel-template-tone-'.$tone.'">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')) .'</strong>'.self::body($section, 'p').'</header>'
			.'</section>';
	}

	/**
	 * Renders hidden realtime bootstrap markup and optional script tag.
	 *
	 * Invalid clients or script URLs return an empty string so incomplete
	 * realtime configuration does not produce broken markup.
	 *
	 * @param array<string, mixed> $section Realtime client section definition.
	 * @return string Realtime bootstrap HTML.
	 */
	private static function realtimeClient(array $section): string {
		$client=Resource::normalizeName((string)($section['client'] ?? ''));
		$script=self::href($section['script'] ?? '');
		if($client==='' || $script==='#'){
			return '';
		}
		if(!empty($section['script_only'])){
			return '<script src="'.self::e($script).'" defer></script>';
		}
		$attributes=[
			'data-dp-panel-template-realtime'=>'1',
			'data-dp-panel-realtime'=>$client,
		];
		foreach(['channel'=>'data-dp-panel-channel', 'token'=>'data-dp-panel-realtime-token', 'websocket'=>'data-dp-panel-websocket'] as $key=>$attribute){
			$value=(string)($section[$key] ?? '');
			if($value!==''){
				$attributes[$attribute]=$value;
			}
		}
		$htmlAttributes='';
		foreach($attributes as $attribute=>$value){
			$htmlAttributes.=' '.$attribute.'="'.self::e($value).'"';
		}
		return '<section class="dp-panel-template-section dp-panel-template-realtime" hidden>'
			.'<div'.$htmlAttributes.'></div>'
			.'<script src="'.self::e($script).'" defer></script>'
			.'</section>';
	}

	/**
	 * Renders document paragraphs or an empty document placeholder.
	 *
	 * @param array<string, mixed> $section Document content section definition.
	 * @return string Document section HTML.
	 */
	private static function documentContent(array $section): string {
		$paragraphs=self::list($section['paragraphs'] ?? $section['items'] ?? []);
		$content='';
		foreach($paragraphs as $paragraph){
			$text=is_array($paragraph) ? (string)($paragraph['body'] ?? $paragraph['text'] ?? '') : (string)$paragraph;
			if($text!==''){
				$content.='<p>'.self::e($text).'</p>';
			}
		}
		if($content===''){
			$content=self::emptyState([
				'title'=>(string)($section['empty_title'] ?? self::text('template.no_document_content', 'No document content available.')),
				'body'=>(string)($section['empty_body'] ?? ''),
			], 'div');
		}
		return '<section class="dp-panel-template-section dp-panel-template-document-content">'
			.'<header>'.self::eyebrow($section).'<strong>'.self::e((string)($section['title'] ?? '')).'</strong>'.self::body($section, 'span').'</header>'
			.'<article>'.$content.'</article>'
			.'</section>';
	}

	/**
	 * Renders a standard empty-state block.
	 *
	 * @param array<string, mixed> $section Empty-state title and body values.
	 * @param string $tag Wrapper tag, constrained to section or div.
	 * @return string Empty-state HTML.
	 */
	private static function emptyState(array $section, string $tag='section'): string {
		$tag=in_array($tag, ['section', 'div'], true) ? $tag : 'section';
		return '<'.$tag.' class="dp-panel-empty-state"><strong>'.self::e((string)($section['title'] ?? self::text('template.empty_title', 'Nothing to show yet.'))).'</strong>'
			.(((string)($section['body'] ?? $section['description'] ?? ''))!=='' ? '<span>'.self::e((string)($section['body'] ?? $section['description'])).'</span>' : '')
			.'</'.$tag.'>';
	}

	/**
	 * Translates template copy with a fallback string.
	 *
	 * @param string $key Panel translation key.
	 * @param string $fallback Fallback copy when no translation exists.
	 * @param array<string, mixed> $parameters Translation replacement parameters.
	 * @return string Localized text.
	 */
	private static function text(string $key, string $fallback, array $parameters=[]): string {
		return Panel::trans($key, $parameters, null, $fallback);
	}

	/**
	 * Renders optional eyebrow copy for a section header.
	 *
	 * @param array<string, mixed> $section Section descriptor.
	 * @return string Escaped eyebrow HTML or an empty string.
	 */
	private static function eyebrow(array $section): string {
		$eyebrow=(string)($section['eyebrow'] ?? '');
		return $eyebrow!=='' ? '<span>'.self::e($eyebrow).'</span>' : '';
	}

	/**
	 * Renders optional section body or description copy.
	 *
	 * @param array<string, mixed> $section Section descriptor.
	 * @param string $tag Wrapper tag, constrained to p or span.
	 * @return string Escaped body HTML or an empty string.
	 */
	private static function body(array $section, string $tag='p'): string {
		$body=(string)($section['body'] ?? $section['description'] ?? '');
		$tag=in_array($tag, ['p', 'span'], true) ? $tag : 'p';
		return $body!=='' ? '<'.$tag.'>'.self::e($body).'</'.$tag.'>' : '';
	}

	/**
	 * Normalizes optional item collections to lists.
	 *
	 * @param mixed $items Candidate array value.
	 * @return array<int, mixed> Values reindexed as a list, or an empty list.
	 */
	private static function list(mixed $items): array {
		return is_array($items) ? array_values($items) : [];
	}

	/**
	 * Normalizes semantic tone names used by template CSS classes.
	 *
	 * @param string $tone Candidate tone.
	 * @return string Supported tone name, defaulting to neutral.
	 */
	private static function tone(string $tone): string {
		$tone=strtolower(trim($tone));
		return in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
	}

	/**
	 * Normalizes a CSS hex color for safe swatch rendering.
	 *
	 * @param string $value Submitted color value.
	 * @return string Normalized #rrggbb color, or an empty string when invalid.
	 */
	private static function hexColor(string $value): string {
		$value=trim(strtolower($value));
		if(preg_match('/^#?([0-9a-f]{3})$/', $value, $match)===1){
			return '#'.$match[1][0].$match[1][0].$match[1][1].$match[1][1].$match[1][2].$match[1][2];
		}
		if(preg_match('/^#?([0-9a-f]{6})$/', $value, $match)===1){
			return '#'.$match[1];
		}
		return '';
	}

	/**
	 * Normalizes href values for generated links and forms.
	 *
	 * Empty or multiline values are replaced with # to avoid broken attributes.
	 *
	 * @param mixed $href Candidate href value.
	 * @return string Safe single-line href string.
	 */
	private static function href(mixed $href): string {
		$href=trim((string)$href);
		if($href==='' || str_contains($href, "\n") || str_contains($href, "\r")){
			return '#';
		}
		return $href;
	}

	/**
	 * Escapes text for safe HTML attribute or content output.
	 *
	 * @param string $value Raw text.
	 * @return string UTF-8 HTML-escaped text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
