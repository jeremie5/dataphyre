<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Providers;

use Dataphyre\Mailer\Contracts\MailProvider;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Mailer\Support\HttpJsonClient;

/**
 * Sends Dataphyre mail messages through Mailgun's Messages API.
 *
 * The provider converts Message objects into Mailgun form fields, supports
 * templates and template variables, attaches custom headers and metadata, sends
 * multipart bodies when attachments exist, and maps API responses to SendResult.
 * Caller-provided endpoint, base URL, and headers are trusted provider
 * configuration and are forwarded to the shared HTTP client.
 */
final class MailgunProvider implements MailProvider {

	/**
	 * Stores default Mailgun API configuration.
	 *
	 * @param array<string,mixed> $config Provider defaults such as api_key, domain, endpoint, base_url, headers, and timeout.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Identifies this provider in mailer results and telemetry.
	 *
	 * @return string Stable provider key.
	 */
	public function name(): string {
		return 'mailgun';
	}

	/**
	 * Sends one message through Mailgun.
	 *
	 * Runtime options override constructor config. The request uses Basic auth
	 * with api:API_KEY, x-www-form-urlencoded bodies for simple messages, and
	 * multipart/form-data when attachments or inline parts are present.
	 * Mailgun's returned id is trimmed of angle brackets before being stored on
	 * the SendResult.
	 *
	 * @param Message $message Message containing envelope, body, template metadata, tags, headers, and attachments.
	 * @param array<string,mixed> $options Per-send overrides such as api_key, domain, endpoint, headers, idempotency_key, and timeout.
	 * @return SendResult Success when Mailgun accepts the message; failure for config or HTTP/API errors.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$apiKey=trim((string)($options['api_key'] ?? $this->config['api_key'] ?? ''));
		$domain=trim((string)($options['domain'] ?? $this->config['domain'] ?? ''));
		if($apiKey==='' || $domain===''){
			return SendResult::failure($this->name(), 'Mailgun API key and domain are not configured.', 500);
		}
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? ''));
		if($endpoint===''){
			$base=rtrim((string)($this->config['base_url'] ?? 'https://api.mailgun.net'), '/');
			$endpoint=$base.'/v3/'.$domain.'/messages';
		}
		$form=$this->formBody($this->payload($message), $message->attachments());
		$response=HttpJsonClient::request('POST', $endpoint, $form['body'], [
			'Authorization'=>'Basic '.base64_encode('api:'.$apiKey),
			'Content-Type'=>$form['content_type'],
			...$this->headers($options),
		], (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$messageId=trim((string)($json['id'] ?? ''), '<>');
		return SendResult::success($this->name(), (int)$response['status'], 'Mailgun accepted the message.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Builds Mailgun form fields from a Message.
	 *
	 * Message headers are sent as `h:*` fields, scalar metadata as `v:*` variables,
	 * and each tag as `o:tag`. Repeated tags overwrite the same form field in this
	 * implementation, so callers that need multiple Mailgun tags should use
	 * provider-specific headers or metadata accepted by their integration.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<string,string> Mailgun form fields without null values.
	 */
	private function payload(Message $message): array {
		$template=$message->metadata()['mailgun_template'] ?? $message->metadata()['template_name'] ?? null;
		$payload=[
			'from'=>$this->formatAddress($message->from()),
			'to'=>$this->addressList($message->to()),
			'subject'=>$message->subject(),
			'text'=>$template===null && $message->text()!=='' ? $message->text() : null,
			'html'=>$template===null && $message->html()!=='' ? $message->html() : null,
			'template'=>$template,
			'cc'=>$message->cc()!==[] ? $this->addressList($message->cc()) : null,
			'bcc'=>$message->bcc()!==[] ? $this->addressList($message->bcc()) : null,
			'h:Reply-To'=>$message->replyTo()!==null ? $this->formatAddress($message->replyTo()) : null,
		];
		$templateVariables=$message->metadata()['template_variables'] ?? $message->metadata()['template_data'] ?? null;
		if(is_array($templateVariables)){
			$payload['h:X-Mailgun-Variables']=json_encode($templateVariables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		}
		foreach($message->headers() as $name=>$value){
			$payload['h:'.$name]=(string)$value;
		}
		foreach($message->tags() as $tag){
			$payload['o:tag']=$tag;
		}
		foreach($message->metadata() as $key=>$value){
			if(is_scalar($value) || $value===null){
				$payload['v:'.$key]=(string)$value;
			}
		}
		return array_filter($payload, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Encodes Mailgun fields and attachments as a request body.
	 *
	 * Attachment content is already loaded into Message rows. Multipart assembly
	 * appends those bytes into one in-memory body string before the HTTP request.
	 *
	 * @param array<string,string> $fields Mailgun form fields.
	 * @param list<array<string,mixed>> $attachments Message attachment data.
	 * @return array{body:string,content_type:string} HTTP body and Content-Type header.
	 * @throws \Exception When multipart boundary generation fails.
	 */
	private function formBody(array $fields, array $attachments): array {
		if($attachments===[]){
			return [
				'body'=>http_build_query($fields, '', '&'),
				'content_type'=>'application/x-www-form-urlencoded',
			];
		}
		$boundary='dp_mailgun_'.bin2hex(random_bytes(12));
		$body='';
		foreach($fields as $name=>$value){
			$body.='--'.$boundary."\r\n";
			$body.='Content-Disposition: form-data; name="'.$this->escapeFieldName((string)$name)."\"\r\n\r\n";
			$body.=(string)$value."\r\n";
		}
		foreach($attachments as $attachment){
			$filename=(string)($attachment['filename'] ?? 'attachment');
			$type=(string)($attachment['type'] ?? 'application/octet-stream');
			$content=(string)($attachment['content'] ?? '');
			$field=((string)($attachment['disposition'] ?? 'attachment'))==='inline' ? 'inline' : 'attachment';
			$body.='--'.$boundary."\r\n";
			$body.='Content-Disposition: form-data; name="'.$field.'"; filename="'.$this->escapeFieldName($filename)."\"\r\n";
			$body.='Content-Type: '.$type."\r\n\r\n";
			$body.=$content."\r\n";
		}
		$body.='--'.$boundary."--\r\n";
		return [
			'body'=>$body,
			'content_type'=>'multipart/form-data; boundary='.$boundary,
		];
	}

	/**
	 * Formats Mailgun recipient lists.
	 *
	 * @param array<int,array{email?:string,name?:string}> $addresses Recipient address data.
	 * @return string Comma-separated recipient header field.
	 */
	private function addressList(array $addresses): string {
		return implode(', ', array_map(fn(array $address): string => $this->formatAddress($address), $addresses));
	}

	/**
	 * Formats one address for Mailgun form fields.
	 *
	 * @param array{email?:string,name?:string} $address Address data.
	 * @return string Display-name address or email alone.
	 */
	private function formatAddress(array $address): string {
		$email=(string)($address['email'] ?? '');
		$name=trim((string)($address['name'] ?? ''));
		return $name!=='' ? $name.' <'.$email.'>' : $email;
	}

	/**
	 * Escapes multipart field and filename parameter values.
	 *
	 * @param string $value Raw field or filename value.
	 * @return string Quoted-parameter-safe value.
	 */
	private function escapeFieldName(string $value): string {
		return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], $value);
	}

	/**
	 * Merges configured and per-send HTTP headers.
	 *
	 * Idempotency keys are truncated before promotion to the request headers.
	 *
	 * @param array<string,mixed> $options Per-send options that may include headers and idempotency_key.
	 * @return array<string,string> Headers for the Mailgun HTTP request.
	 */
	private function headers(array $options): array {
		$headers=is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [];
		if(is_array($options['headers'] ?? null)){
			$headers=array_replace($headers, $options['headers']);
		}
		if(trim((string)($options['idempotency_key'] ?? ''))!=='' && !isset($headers['Idempotency-Key'])){
			$headers['Idempotency-Key']=substr(trim((string)$options['idempotency_key']), 0, 256);
		}
		return $headers;
	}

	/**
	 * Extracts a human-readable Mailgun error message.
	 *
	 * @param array<string,mixed> $response HttpJsonClient response payload.
	 * @return string API message, transport error, or generic failure text.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		return (string)($json['message'] ?? $json['error'] ?? $response['error'] ?? 'Mailgun request failed.');
	}
}
