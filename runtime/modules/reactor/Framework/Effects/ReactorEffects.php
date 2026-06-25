<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Fluent builder for client-side effects returned by Reactor actions.
 *
 * `ReactorEffects` collects the browser commands that accompany a Reactor
 * response: DOM fragments, dispatched events, focus/scroll requests, redirects,
 * downloads, validation errors, and render-control flags. Methods mutate the
 * current builder and return it so action handlers can compose effects before
 * serializing the final payload.
 *
 * Security boundary: this class normalizes effect keys and a few enum-like
 * options, but it does not sanitize HTML fragments, URLs, selectors, clipboard
 * text, or event detail payloads. Those values are intentionally forwarded to the
 * browser runtime and must be produced by trusted renderers or validated action
 * code.
 */
final class ReactorEffects implements \JsonSerializable {

	private array $effects=[];

	/**
	 * Creates an empty effects builder.
	 *
	 *
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Sets a raw effect value under a normalized effect key.
	 *
	 * Blank keys are ignored after Reactor name normalization. This low-level
	 * method is useful for effect types that are not yet represented by a named
	 * helper, but callers are responsible for shaping values the browser runtime
	 * understands.
	 *
	 * @param string $key Effect key before Reactor name normalization.
	 * @param mixed $value Effect payload to expose in the serialized response.
	 * @return self Current builder for fluent composition.
	 */
	public function set(string $key, mixed $value): self {
		$key=ReactorName::normalize($key);
		if($key!==''){
			$this->effects[$key]=$value;
		}
		return $this;
	}

	/**
	 * Merges a raw effect map into the current builder.
	 *
	 * Each incoming key is normalized through `set()`, so blank or invalid keys
	 * are skipped. A trace entry records the original effect keys when the input is
	 * not empty.
	 *
	 * @param array<string,mixed> $effects Effect payloads keyed by effect name.
	 * @return self Current builder after merge.
	 */
	public function merge(array $effects): self {
		foreach($effects as $key=>$value){
			$this->set((string)$key, $value);
		}
		if($effects!==[]){
			ReactorTrace::record('effect.merged', ['effects'=>array_keys($effects)]);
		}
		return $this;
	}

	/**
	 * Queues a browser event dispatch effect.
	 *
	 * Empty event names are ignored. Detail payloads are preserved exactly, with
	 * targeting flags added only by `dispatchTo()` and `dispatchSelf()`.
	 *
	 * @param string $event Browser event name.
	 * @param array<string,mixed> $detail Event detail payload.
	 * @return self Current builder after queueing the event.
	 */
	public function dispatch(string $event, array $detail=[]): self {
		$event=trim($event);
		if($event===''){
			return $this;
		}
		$this->effects['events'][]=[
			'name'=>$event,
			'detail'=>$detail,
		];
		ReactorTrace::record('effect.event', ['event'=>$event]);
		return $this;
	}

	/**
	 * Queues a browser event dispatch targeted to a Reactor component.
	 *
	 * Component names are normalized and blank targets are ignored. The target is
	 * encoded into the event detail under `_reactor_to` for the client runtime.
	 *
	 * @param string $component Target component name.
	 * @param string $event Browser event name.
	 * @param array<string,mixed> $detail Event detail payload before target metadata is added.
	 * @return self Current builder after queueing the targeted event.
	 */
	public function dispatchTo(string $component, string $event, array $detail=[]): self {
		$component=ReactorName::normalize($component);
		if($component===''){
			return $this;
		}
		$detail['_reactor_to']=$component;
		$this->dispatch($event, $detail);
		ReactorTrace::record('effect.event_targeted', ['event'=>$event, 'target'=>$component]);
		return $this;
	}

	/**
	 * Queues a browser event dispatch targeted to the current component.
	 *
	 * The self target is encoded into event detail under `_reactor_self` for the
	 * client runtime. Empty event names are still ignored by `dispatch()`.
	 *
	 * @param string $event Browser event name.
	 * @param array<string,mixed> $detail Event detail payload before self metadata is added.
	 * @return self Current builder after queueing the self-targeted event.
	 */
	public function dispatchSelf(string $event, array $detail=[]): self {
		$detail['_reactor_self']=true;
		$this->dispatch($event, $detail);
		ReactorTrace::record('effect.event_self', ['event'=>$event]);
		return $this;
	}

	/**
	 * Queues a toast notification effect.
	 *
	 * Blank messages are ignored. Tone values are normalized and default to
	 * `info`. Empty detail arrays are omitted from the toast data sent to the browser.
	 *
	 * @param string $message User-facing notification text.
	 * @param string $tone Normalized presentation tone such as `info`, `success`, or `error`.
	 * @param array<string,mixed> $detail Optional client-side metadata for the toast renderer.
	 * @return self Current builder after queueing the toast.
	 */
	public function toast(string $message, string $tone='info', array $detail=[]): self {
		$message=trim($message);
		if($message===''){
			return $this;
		}
		$this->effects['toasts'][]=array_filter([
			'message'=>$message,
			'tone'=>ReactorName::normalize($tone) ?: 'info',
			'detail'=>$detail,
		], static fn(mixed $value): bool => $value!==[]);
		ReactorTrace::record('effect.toast', ['tone'=>ReactorName::normalize($tone) ?: 'info']);
		return $this;
	}

	/**
	 * Sets a redirect effect for the browser response.
	 *
	 * Blank URLs are ignored. The URL is forwarded as-is to the client runtime;
	 * callers must ensure it is safe for navigation.
	 *
	 * @param string $url Destination URL.
	 * @param bool $replace True when browser history should be replaced instead of pushed.
	 * @return self Current builder after setting redirect state.
	 */
	public function redirect(string $url, bool $replace=false): self {
		$url=trim($url);
		if($url!==''){
			$this->effects['redirect']=[
				'url'=>$url,
				'replace'=>$replace,
			];
			ReactorTrace::record('effect.redirect', ['replace'=>$replace]);
		}
		return $this;
	}

	/**
	 * Queues a DOM fragment replacement effect.
	 *
	 * Fragment names accept letters, digits, underscore, dot, colon, and dash. The
	 * HTML is forwarded without sanitization because it is expected to come from
	 * the server renderer. Unsupported modes fall back to `morph`; unsupported
	 * scopes fall back to `root`.
	 *
	 * @param string $name Client fragment name.
	 * @param string $html Rendered HTML fragment.
	 * @param string $mode Replacement mode: `morph`, `inner`, or `outer`.
	 * @param string $scope Replacement scope: `root` or `document`.
	 * @return self Current builder after queueing the fragment.
	 */
	public function fragment(string $name, string $html, string $mode='morph', string $scope='root'): self {
		$name=self::normalizeFragmentName($name);
		if($name===''){
			return $this;
		}
		$mode=ReactorName::normalize($mode);
		$scope=ReactorName::normalize($scope);
		$this->effects['fragments'][]=[
			'name'=>$name,
			'html'=>$html,
			'mode'=>in_array($mode, ['morph', 'inner', 'outer'], true) ? $mode : 'morph',
			'scope'=>$scope==='document' ? 'document' : 'root',
		];
		ReactorTrace::record('effect.fragment', ['name'=>$name, 'mode'=>$mode, 'scope'=>$scope]);
		return $this;
	}

	/**
	 * Sets a focus request for a browser selector.
	 *
	 *
	 * @param string $selector CSS selector to focus on the client.
	 * @param bool $preventScroll True when focus should avoid scrolling the element into view.
	 * @return self Current builder after setting the focus effect.
	 */
	public function focus(string $selector, bool $preventScroll=false): self {
		return $this->targetEffect('focus', $selector, ['prevent_scroll'=>$preventScroll]);
	}

	/**
	 * Sets a scroll-into-view request for a browser selector.
	 *
	 * Unsupported block and inline alignment values fall back to `nearest`, which
	 * mirrors the safest browser default for incremental UI updates.
	 *
	 * @param string $selector CSS selector to scroll into view.
	 * @param string $block Vertical alignment: `start`, `center`, `end`, or `nearest`.
	 * @param string $inline Horizontal alignment: `start`, `center`, `end`, or `nearest`.
	 * @return self Current builder after setting the scroll effect.
	 */
	public function scroll(string $selector, string $block='nearest', string $inline='nearest'): self {
		return $this->targetEffect('scroll', $selector, [
			'block'=>in_array($block, ['start', 'center', 'end', 'nearest'], true) ? $block : 'nearest',
			'inline'=>in_array($inline, ['start', 'center', 'end', 'nearest'], true) ? $inline : 'nearest',
		]);
	}

	/**
	 * Sets the browser document title effect.
	 *
	 * Blank titles are ignored so a component cannot accidentally clear the
	 * document title by passing whitespace.
	 *
	 * @param string $title New document title.
	 * @return self Current builder after setting the title effect.
	 */
	public function title(string $title): self {
		$title=trim($title);
		if($title!==''){
			$this->effects['title']=$title;
			ReactorTrace::record('effect.title');
		}
		return $this;
	}

	/**
	 * Sets a clipboard copy effect.
	 *
	 * The text is intentionally not trimmed so callers can copy exact content,
	 * including leading or trailing whitespace.
	 *
	 * @param string $text Text to place on the clipboard.
	 * @return self Current builder after setting the copy effect.
	 */
	public function copy(string $text): self {
		$this->effects['copy']=(string)$text;
		ReactorTrace::record('effect.copy');
		return $this;
	}

	/**
	 * Sets an open-window effect.
	 *
	 * Blank URLs are ignored. The target defaults to `_blank` after trimming.
	 * Callers are responsible for passing URLs that satisfy application navigation
	 * policy.
	 *
	 * @param string $url URL to open.
	 * @param string $target Browser target name such as `_blank` or `_self`.
	 * @return self Current builder after setting the open effect.
	 */
	public function open(string $url, string $target='_blank'): self {
		$url=trim($url);
		if($url!==''){
			$this->effects['open']=[
				'url'=>$url,
				'target'=>trim($target) ?: '_blank',
			];
			ReactorTrace::record('effect.open', ['target'=>trim($target) ?: '_blank']);
		}
		return $this;
	}

	/**
	 * Sets a browser download effect.
	 *
	 * Blank URLs are ignored. The optional filename is trimmed and serialized as
	 * an empty string when omitted.
	 *
	 * @param string $url Download URL.
	 * @param ?string $filename Suggested filename for the browser download.
	 * @return self Current builder after setting the download effect.
	 */
	public function download(string $url, ?string $filename=null): self {
		$url=trim($url);
		if($url!==''){
			$this->effects['download']=[
				'url'=>$url,
				'filename'=>$filename!==null ? trim($filename) : '',
			];
			ReactorTrace::record('effect.download');
		}
		return $this;
	}

	/**
	 * Sets normalized validation errors for the client response.
	 *
	 * Field names and messages are trimmed; blank fields and blank messages are
	 * discarded. Scalar messages are converted to single-item message lists.
	 *
	 * @param array<string,mixed> $errors Field-to-message or field-to-messages map.
	 * @return self Current builder after setting validation errors.
	 */
	public function errors(array $errors): self {
		$this->effects['errors']=self::normalizeErrors($errors);
		ReactorTrace::record('effect.errors', ['fields'=>array_keys($this->effects['errors'])]);
		return $this;
	}

	/**
	 * Clears client-side validation errors.
	 *
	 *
	 * @return self Current builder after setting an empty error map.
	 */
	public function clearErrors(): self {
		$this->effects['errors']=[];
		ReactorTrace::record('effect.errors_cleared');
		return $this;
	}

	/**
	 * Sets the default component replacement mode.
	 *
	 * Only `morph` and `inner` are accepted. Unknown modes are ignored so callers
	 * cannot accidentally emit a client mode the runtime does not understand.
	 *
	 * @param string $mode Replacement mode for the main component render.
	 * @return self Current builder after applying a supported mode.
	 */
	public function replaceMode(string $mode): self {
		$mode=ReactorName::normalize($mode);
		if(in_array($mode, ['morph', 'inner'], true)){
			$this->effects['replace']=$mode;
			ReactorTrace::record('effect.replace', ['mode'=>$mode]);
		}
		return $this;
	}

	/**
	 * Controls whether the client should skip the normal component render.
	 *
	 * This flag lets actions return side effects without shipping a fresh
	 * component HTML payload.
	 *
	 * @param bool $skip True when the normal component render should be skipped.
	 * @return self Current builder after setting the render-control flag.
	 */
	public function skipRender(bool $skip=true): self {
		$this->effects['skip_render']=$skip;
		ReactorTrace::record('effect.skip_render', ['skip'=>$skip]);
		return $this;
	}

	/**
	 * Returns queued client effects for the Reactor response encoder.
	 *
	 * The returned array is the exact shape that `jsonSerialize()` exposes to the
	 * Reactor response encoder.
	 *
	 * @return array<string,mixed> Serialized client effects.
	 */
	public function all(): array {
		return $this->effects;
	}

	/**
	 * Serializes queued client effects for JSON responses.
	 *
	 * @return array<string,mixed> Serialized client effects.
	 */
	public function jsonSerialize(): array {
		return $this->effects;
	}

	/**
	 * Normalizes validation errors into a field-to-message-list map.
	 *
	 * @param array<string,mixed> $errors Raw validation errors.
	 * @return array<string,array<int,string>> Trimmed non-empty messages keyed by non-empty field name.
	 */
	private static function normalizeErrors(array $errors): array {
		$normalized=[];
		foreach($errors as $field=>$messages){
			$field=trim((string)$field);
			if($field===''){
				continue;
			}
			$messages=is_array($messages) ? $messages : [$messages];
			foreach($messages as $message){
				$message=trim((string)$message);
				if($message!==''){
					$normalized[$field][]=$message;
				}
			}
		}
		return $normalized;
	}

	/**
	 * Sets a selector-targeted browser effect.
	 *
	 * Blank selectors are ignored. Options are merged after the selector so callers
	 * can add browser-specific flags without duplicating selector handling.
	 *
	 * @param string $name Effect name such as `focus` or `scroll`.
	 * @param string $selector CSS selector for the target element.
	 * @param array<string,mixed> $options Additional effect options.
	 * @return self Current builder after setting the target effect.
	 */
	private function targetEffect(string $name, string $selector, array $options=[]): self {
		$selector=trim($selector);
		if($selector!==''){
			$this->effects[$name]=['selector'=>$selector]+$options;
			ReactorTrace::record('effect.'.$name, ['selector'=>$selector]);
		}
		return $this;
	}

	/**
	 * Normalizes a fragment name for client-side lookup.
	 *
	 * Fragment names are intentionally restricted to identifier-like characters so
	 * they can be used safely as client-side registry keys and DOM markers.
	 *
	 * @param string $name Raw fragment name.
	 * @return string Accepted fragment name or an empty string when invalid.
	 */
	private static function normalizeFragmentName(string $name): string {
		$name=trim($name);
		return preg_match('/^[a-zA-Z0-9_.:-]+$/', $name)===1 ? $name : '';
	}
}
