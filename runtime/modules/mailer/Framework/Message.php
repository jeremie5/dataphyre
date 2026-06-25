<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer;

/**
 * Immutable normalized email message used by mailer providers and queues.
 *
 * Message accepts loose framework message data, normalizes sender and recipient address shapes, headers, unsubscribe
 * metadata, attachments, tags, and provider metadata, then exposes a provider-ready array for delivery providers,
 * persistence, queues, and diagnostics. Attachment normalization may read local files when only a readable path is
 * supplied.
 */
final class Message implements \JsonSerializable {

	private array $payload;
	private static mixed $lastTagListInput=null;
	private static ?array $lastTagListOutput=null;

	/**
	 * Stores the normalized provider message data.
	 *
	 * @param array<string, mixed> $payload Raw message data.
	 */
	private function __construct(array $payload) {
		$this->payload=self::normalize($payload);
	}

	/**
	 * Creates a normalized message from loose message data.
	 *
	 * @param array<string, mixed> $payload Raw message data.
	 * @return self Immutable normalized message.
	 */
	public static function make(array $payload=[]): self {
		return new self($payload);
	}

	/**
	 * Returns a copy with recursive message overrides applied and normalized.
	 *
	 * @param array<string, mixed> $values Payload values to merge over the current message.
	 * @return self New normalized message instance.
	 */
	public function with(array $values): self {
		return new self(array_replace_recursive($this->payload, $values));
	}

	/**
	 * Returns the complete normalized provider-ready message.
	 *
	 * @return array<string, mixed> Provider-ready message data.
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Returns the normalized sender address.
	 *
	 * @return array{email: string, name: string} Sender address.
	 */
	public function from(): array {
		return $this->payload['from'];
	}

	/**
	 * Returns the optional normalized reply-to address.
	 *
	 * @return ?array{email: string, name: string} Reply-to address, or null when absent.
	 */
	public function replyTo(): ?array {
		return $this->payload['reply_to'];
	}

	/**
	 * Returns primary recipients.
	 *
	 * @return list<array{email: string, name: string}> Normalized To recipients.
	 */
	public function to(): array {
		return $this->payload['to'];
	}

	/**
	 * Returns carbon-copy recipients.
	 *
	 * @return list<array{email: string, name: string}> Normalized CC recipients.
	 */
	public function cc(): array {
		return $this->payload['cc'];
	}

	/**
	 * Returns blind-carbon-copy recipients.
	 *
	 * @return list<array{email: string, name: string}> Normalized BCC recipients.
	 */
	public function bcc(): array {
		return $this->payload['bcc'];
	}

	/**
	 * Returns the message subject.
	 *
	 * @return string Trimmed subject.
	 */
	public function subject(): string {
		return $this->payload['subject'];
	}

	/**
	 * Returns the HTML body.
	 *
	 * @return string HTML content, possibly empty.
	 */
	public function html(): string {
		return $this->payload['html'];
	}

	/**
	 * Returns the plain-text body.
	 *
	 * @return string Text content, possibly empty.
	 */
	public function text(): string {
		return $this->payload['text'];
	}

	/**
	 * Returns normalized provider headers.
	 *
	 * @return array<string, string> Header map.
	 */
	public function headers(): array {
		return $this->payload['headers'];
	}

	/**
	 * Returns normalized attachments with in-memory content.
	 *
	 * @return list<array{filename: string, type: string, content: string, disposition: string, content_id: string}> Attachment data.
	 */
	public function attachments(): array {
		return $this->payload['attachments'];
	}

	/**
	 * Returns provider tags or categories.
	 *
	 * @return list<string> Message tags.
	 */
	public function tags(): array {
		return $this->payload['tags'];
	}

	/**
	 * Returns provider or application metadata carried with the message.
	 *
	 * @return array<string, mixed> Metadata map.
	 */
	public function metadata(): array {
		return $this->payload['metadata'];
	}

	/**
	 * Exports the provider-ready message data.
	 *
	 * @return array<string, mixed> Normalized message data.
	 */
	public function toArray(): array {
		return $this->payload;
	}

	/**
	 * Serializes the provider-ready message data for JSON output.
	 *
	 * @return array<string, mixed> Normalized message data.
	 */
	public function jsonSerialize(): array {
		return $this->payload;
	}

	/**
	 * Normalizes raw message data into the mailer provider shape.
	 *
	 * Defaults come from DP_MAILER_CFG where available. List-Unsubscribe and List-Unsubscribe-Post headers are synthesized
	 * from unsubscribe fields when explicit headers are not already present.
	 *
	 * @param array<string, mixed> $payload Raw message data.
	 * @return array<string, mixed> Normalized provider-ready message data.
	 */
	public static function normalize(array $payload): array {
		$config=\defined('DP_MAILER_CFG') && \is_array(\DP_MAILER_CFG) ? \DP_MAILER_CFG : [];
		$from=$payload['from'] ?? $config['from'] ?? ['email'=>'no-reply@example.invalid', 'name'=>'Dataphyre'];
		$replyTo=$payload['reply_to'] ?? $payload['replyTo'] ?? $config['reply_to'] ?? null;
		$headers=isset($payload['headers']) ? self::stringMap($payload['headers']) : [];
		if(
			isset($payload['list_unsubscribe'])
			|| isset($payload['listUnsubscribe'])
			|| isset($payload['unsubscribe'])
			|| isset($payload['unsubscribe_url'])
			|| isset($payload['unsubscribeUrl'])
			|| isset($payload['unsubscribe_email'])
			|| isset($payload['unsubscribeEmail'])
			|| isset($payload['one_click_unsubscribe'])
			|| isset($payload['oneClickUnsubscribe'])
		){
			$listUnsubscribe=self::listUnsubscribeHeader($payload);
			if($listUnsubscribe!=='' && !isset($headers['List-Unsubscribe'])){
				$headers['List-Unsubscribe']=$listUnsubscribe;
			}
			if(self::oneClickUnsubscribe($payload) && isset($headers['List-Unsubscribe']) && !isset($headers['List-Unsubscribe-Post'])){
				$headers['List-Unsubscribe-Post']='List-Unsubscribe=One-Click';
			}
		}
		return [
			'from'=>self::address($from) ?? ['email'=>'no-reply@example.invalid', 'name'=>'Dataphyre'],
			'reply_to'=>self::address($replyTo),
			'to'=>self::addresses($payload['to'] ?? []),
			'cc'=>isset($payload['cc']) ? self::addresses($payload['cc']) : [],
			'bcc'=>isset($payload['bcc']) ? self::addresses($payload['bcc']) : [],
			'subject'=>trim((string)($payload['subject'] ?? '')),
			'html'=>(string)($payload['html'] ?? ''),
			'text'=>(string)($payload['text'] ?? ''),
			'headers'=>$headers,
			'attachments'=>isset($payload['attachments']) ? self::normalizeAttachments($payload['attachments']) : [],
			'tags'=>self::normalizeTags($payload['tags'] ?? $payload['categories'] ?? []),
			'metadata'=>is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
		];
	}

	private static function normalizeTags(mixed $tags): array {
		if(self::$lastTagListInput===$tags && self::$lastTagListOutput!==null){
			return self::$lastTagListOutput;
		}
		$input=$tags;
		$normalized=array_values(array_filter(array_map('strval', (array)$tags)));
		self::$lastTagListInput=$input;
		return self::$lastTagListOutput=$normalized;
	}

	/**
	 * Normalizes one or more recipient addresses.
	 *
	 * Accepts a single string, a list of strings/arrays, or an associative email => name map.
	 *
	 * @param mixed $addresses Raw address collection.
	 * @return list<array{email: string, name: string}> Valid normalized addresses.
	 */
	private static function addresses(mixed $addresses): array {
		if(is_string($addresses)){
			$addresses=[$addresses];
		}
		if(is_array($addresses)===false){
			return [];
		}
		$normalized=[];
		foreach($addresses as $key=>$value){
			$address=is_string($key) && !is_numeric($key)
				? self::address(['email'=>$key, 'name'=>$value])
				: self::address($value);
			if($address!==null){
				$normalized[]=$address;
			}
		}
		return $normalized;
	}

	/**
	 * Normalizes one email address into the provider address contract.
	 *
	 * String addresses may be plain emails or Display Name <email@example.com> values.
	 *
	 * @param mixed $address Raw address value.
	 * @return ?array{email: string, name: string} Normalized address, or null when invalid.
	 */
	private static function address(mixed $address): ?array {
		if($address===null || $address===false){
			return null;
		}
		if(is_string($address)){
			$address=trim($address);
			if($address===''){
				return null;
			}
			if(preg_match('/^(.*?)<([^>]+)>$/', $address, $matches)===1){
				$name=trim(trim($matches[1]), "\"' ");
				$email=trim($matches[2]);
				return filter_var($email, FILTER_VALIDATE_EMAIL) ? ['email'=>$email, 'name'=>$name] : null;
			}
			return filter_var($address, FILTER_VALIDATE_EMAIL) ? ['email'=>$address, 'name'=>''] : null;
		}
		if(is_array($address)){
			$email=trim((string)($address['email'] ?? $address['address'] ?? ''));
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
				return null;
			}
			return ['email'=>$email, 'name'=>trim((string)($address['name'] ?? ''))];
		}
		return null;
	}

	/**
	 * Converts an arbitrary header map into string keys and values.
	 *
	 * @param mixed $map Raw map value.
	 * @return array<string, string> Normalized string map.
	 */
	private static function stringMap(mixed $map): array {
		if(is_array($map)===false){
			return [];
		}
		$normalized=[];
		foreach($map as $key=>$value){
			$key=trim((string)$key);
			if($key!==''){
				$normalized[$key]=(string)$value;
			}
		}
		return $normalized;
	}

	/**
	 * Builds an RFC-style List-Unsubscribe header value from message fields.
	 *
	 * Explicit list_unsubscribe/listUnsubscribe strings pass through. Structured unsubscribe fields may contribute a URL
	 * and/or mailto target, each wrapped in angle brackets for provider compatibility.
	 *
	 * @param array<string, mixed> $payload Raw message data.
	 * @return string Header value, or empty string when no valid unsubscribe target exists.
	 */
	private static function listUnsubscribeHeader(array $payload): string {
		$value=$payload['list_unsubscribe'] ?? $payload['listUnsubscribe'] ?? $payload['unsubscribe'] ?? null;
		if(is_string($value)){
			return trim($value);
		}
		if(is_array($value)===false){
			$value=[];
		}
		$url=trim((string)($payload['unsubscribe_url'] ?? $payload['unsubscribeUrl'] ?? $value['url'] ?? ''));
		$email=trim((string)($payload['unsubscribe_email'] ?? $payload['unsubscribeEmail'] ?? $value['email'] ?? ''));
		$parts=[];
		if($url!=='' && filter_var($url, FILTER_VALIDATE_URL)){
			$parts[]='<'.$url.'>';
		}
		if($email!==''){
			$mailto=str_starts_with(strtolower($email), 'mailto:') ? $email : 'mailto:'.$email;
			if(filter_var(substr($mailto, 7), FILTER_VALIDATE_EMAIL)){
				$parts[]='<'.$mailto.'>';
			}
		}
		return implode(', ', $parts);
	}

	/**
	 * Reports whether one-click unsubscribe should be advertised.
	 *
	 * @param array<string, mixed> $payload Raw message data.
	 * @return bool True when one-click unsubscribe is enabled by top-level or nested unsubscribe fields.
	 */
	private static function oneClickUnsubscribe(array $payload): bool {
		$value=$payload['one_click_unsubscribe'] ?? $payload['oneClickUnsubscribe'] ?? null;
		if($value!==null){
			return filter_var($value, FILTER_VALIDATE_BOOL);
		}
		$unsubscribe=$payload['unsubscribe'] ?? $payload['list_unsubscribe'] ?? $payload['listUnsubscribe'] ?? null;
		if(is_array($unsubscribe)){
			return filter_var($unsubscribe['one_click'] ?? $unsubscribe['oneClick'] ?? false, FILTER_VALIDATE_BOOL);
		}
		return false;
	}

	/**
	 * Normalizes attachments into provider-ready in-memory data.
	 *
	 * String entries are treated as file paths. Array entries may provide content directly or a readable path whose contents
	 * are loaded during normalization. Entries without content after this step are discarded.
	 *
	 * @param mixed $attachments Raw attachment collection.
	 * @return list<array{filename: string, type: string, content: string, disposition: string, content_id: string}> Normalized attachments.
	 */
	private static function normalizeAttachments(mixed $attachments): array {
		if(is_array($attachments)===false){
			return [];
		}
		$normalized=[];
		foreach($attachments as $attachment){
			if(is_string($attachment)){
				$attachment=['path'=>$attachment];
			}
			if(is_array($attachment)===false){
				continue;
			}
			$path=trim((string)($attachment['path'] ?? ''));
			$content=(string)($attachment['content'] ?? '');
			if($content==='' && $path!=='' && is_file($path) && is_readable($path)){
				$content=(string)file_get_contents($path);
			}
			if($content===''){
				continue;
			}
			$filename=trim((string)($attachment['filename'] ?? ($path!=='' ? basename($path) : 'attachment')));
			$normalized[]=[
				'filename'=>$filename!=='' ? $filename : 'attachment',
				'type'=>trim((string)($attachment['type'] ?? $attachment['mime'] ?? 'application/octet-stream')),
				'content'=>$content,
				'disposition'=>trim((string)($attachment['disposition'] ?? 'attachment')),
				'content_id'=>trim((string)($attachment['content_id'] ?? $attachment['cid'] ?? '')),
			];
		}
		return $normalized;
	}
}
