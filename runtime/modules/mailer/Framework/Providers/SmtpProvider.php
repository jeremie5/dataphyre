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

/**
 * Sends Dataphyre mail messages through a raw SMTP connection.
 *
 * The provider owns one socket per send attempt, negotiates optional TLS and
 * authentication, serializes Message objects into RFC-style MIME data, and
 * reports remote acceptance or transport failures through SendResult.
 * Host trust, credential storage, and any custom TLS context policy are supplied
 * by configuration outside this provider.
 */
final class SmtpProvider implements MailProvider {

	/** @var resource|null */
	private $socket=null;

	/**
	 * Stores default SMTP connection and authentication configuration.
	 *
	 * @param array<string,mixed> $config Provider defaults such as host, port, secure, auth, username, password, helo, and timeout.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Identifies this provider in mailer results and telemetry.
	 *
	 * @return string Stable provider key.
	 */
	public function name(): string {
		return 'smtp';
	}

	/**
	 * Sends one message through SMTP and closes the socket before returning.
	 *
	 * Runtime options override constructor config. The SMTP dialogue performs
	 * EHLO, optional STARTTLS, optional AUTH PLAIN or LOGIN, envelope sender and
	 * recipients, DATA upload, and QUIT. Bcc addresses participate only in the
	 * SMTP envelope because mime() never writes a Bcc header.
	 *
	 * STARTTLS is attempted when configured, but the provider does not inspect
	 * advertised EHLO capabilities before issuing STARTTLS or AUTH. Any protocol
	 * rejection, socket failure, or MIME serialization error is converted into a
	 * failed SendResult after the socket is closed.
	 *
	 * @param Message $message Message containing envelope, headers, body, and attachments.
	 * @param array<string,mixed> $options Per-send SMTP overrides such as host, port, secure, credentials, auth, helo, and timeout.
	 * @return SendResult Success when the server accepts DATA; failure for configuration, connection, protocol, or serialization errors.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$host=trim((string)($options['host'] ?? $this->config['host'] ?? ''));
		if($host===''){
			return SendResult::failure($this->name(), 'SMTP host is not configured.', 500);
		}
		$port=(int)($options['port'] ?? $this->config['port'] ?? 587);
		$secure=strtolower(trim((string)($options['secure'] ?? $this->config['secure'] ?? 'tls')));
		$username=(string)($options['username'] ?? $this->config['username'] ?? '');
		$password=(string)($options['password'] ?? $this->config['password'] ?? '');
		$timeout=(int)($options['timeout'] ?? $this->config['timeout'] ?? 20);
		$peer=($secure==='ssl' || $secure==='smtps' ? 'ssl://' : '').$host;
		$errorNumber=0;
		$errorMessage='';
		$this->socket=@fsockopen($peer, $port, $errorNumber, $errorMessage, $timeout);
		if(!is_resource($this->socket)){
			return SendResult::failure($this->name(), 'Unable to connect to SMTP server: '.$errorMessage, 500, ['error_number'=>$errorNumber]);
		}
		stream_set_timeout($this->socket, $timeout);
		try{
			$this->expect([220]);
			$hello=(string)($options['helo'] ?? $this->config['helo'] ?? gethostname() ?: 'localhost');
			$this->command('EHLO '.$hello, [250]);
			if($secure==='tls' || $secure==='starttls'){
				$this->command('STARTTLS', [220]);
				if(!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)){
					throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
				}
				$this->command('EHLO '.$hello, [250]);
			}
			if($username!=='' || $password!==''){
				$this->authenticate($username, $password, strtolower((string)($options['auth'] ?? $this->config['auth'] ?? 'login')));
			}
			$this->command('MAIL FROM:<'.($message->from()['email'] ?? '').'>', [250]);
			foreach(array_merge($message->to(), $message->cc(), $message->bcc()) as $address){
				$this->command('RCPT TO:<'.($address['email'] ?? '').'>', [250, 251]);
			}
			$this->command('DATA', [354]);
			$this->write($this->mime($message)."\r\n.");
			$this->expect([250]);
			$this->command('QUIT', [221]);
			@fclose($this->socket);
			$this->socket=null;
			return SendResult::success($this->name(), 250, 'SMTP server accepted the message.', null, ['host'=>$host, 'port'=>$port]);
		}
		catch(\Throwable $exception){
			if(is_resource($this->socket)){
				@fclose($this->socket);
			}
			$this->socket=null;
			return SendResult::failure($this->name(), $exception->getMessage(), 500, ['host'=>$host, 'port'=>$port]);
		}
	}

	/**
	 * Performs SMTP AUTH using PLAIN or LOGIN.
	 *
	 * @param string $username SMTP username.
	 * @param string $password SMTP password.
	 * @param string $method Authentication mechanism name.
	 * @return void
	 * @throws \RuntimeException When the server rejects an authentication step.
	 */
	private function authenticate(string $username, string $password, string $method): void {
		if($method==='plain'){
			$this->command('AUTH PLAIN '.base64_encode("\0".$username."\0".$password), [235]);
			return;
		}
		$this->command('AUTH LOGIN', [334]);
		$this->command(base64_encode($username), [334]);
		$this->command(base64_encode($password), [235]);
	}

	/**
	 * Serializes a Message into the RFC-style data sent after the DATA command.
	 *
	 * Caller-supplied message headers are copied after the generated headers and
	 * can override them. Header names and values from Message::headers() are not
	 * revalidated here, so upstream normalization is the security boundary for
	 * custom header injection.
	 *
	 * @param Message $message Message to encode.
	 * @return string CRLF-delimited MIME headers and body.
	 * @throws \Exception When MIME boundaries require random bytes and entropy is unavailable.
	 */
	private function mime(Message $message): string {
		$headers=[
			'Date'=>date(DATE_RFC2822),
			'From'=>$this->formatAddress($message->from()),
			'To'=>$this->addressList($message->to()),
			'Subject'=>$this->encodeHeader($message->subject()),
			'MIME-Version'=>'1.0',
		];
		if($message->cc()!==[]){
			$headers['Cc']=$this->addressList($message->cc());
		}
		if($message->replyTo()!==null){
			$headers['Reply-To']=$this->formatAddress($message->replyTo());
		}
		foreach($message->headers() as $name=>$value){
			$headers[$name]=(string)$value;
		}
		$body=$this->mimeBody($message, $headers);
		$lines=[];
		foreach($headers as $name=>$value){
			$lines[]=$name.': '.$value;
		}
		return implode("\r\n", $lines)."\r\n\r\n".$body;
	}

	/**
	 * Builds the MIME body and mutates headers with the matching content type.
	 *
	 * Simple messages use quoted-printable text or HTML. Messages with both text
	 * and HTML become multipart/alternative inside multipart/mixed, and
	 * attachments are emitted as base64 mixed parts.
	 *
	 * Attachment bytes are already loaded into the Message object before this
	 * method runs. Very large attachments therefore stay in memory through MIME
	 * assembly and socket upload.
	 *
	 * @param Message $message Message body and attachments to encode.
	 * @param array<string,string> $headers Header map updated with Content-Type and transfer headers.
	 * @return string Encoded MIME body.
	 * @throws \Exception When multipart boundary generation fails.
	 */
	private function mimeBody(Message $message, array &$headers): string {
		$attachments=$message->attachments();
		$alternative=$message->html()!=='' && $message->text()!=='';
		if($attachments===[] && !$alternative){
			$headers['Content-Type']=$message->html()!=='' ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
			$headers['Content-Transfer-Encoding']='quoted-printable';
			return quoted_printable_encode($message->html()!=='' ? $message->html() : $message->text());
		}
		$rootBoundary='dp_mail_'.bin2hex(random_bytes(12));
		$headers['Content-Type']='multipart/mixed; boundary="'.$rootBoundary.'"';
		$parts=[];
		if($alternative){
			$altBoundary='dp_alt_'.bin2hex(random_bytes(12));
			$alt=[];
			$alt[]='--'.$altBoundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($message->text());
			$alt[]='--'.$altBoundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($message->html());
			$alt[]='--'.$altBoundary.'--';
			$parts[]='--'.$rootBoundary."\r\nContent-Type: multipart/alternative; boundary=\"".$altBoundary."\"\r\n\r\n".implode("\r\n", $alt);
		}
		else{
			$type=$message->html()!=='' ? 'text/html' : 'text/plain';
			$content=$message->html()!=='' ? $message->html() : $message->text();
			$parts[]='--'.$rootBoundary."\r\nContent-Type: ".$type."; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($content);
		}
		foreach($attachments as $attachment){
			$filename=(string)($attachment['filename'] ?? 'attachment');
			$type=(string)($attachment['type'] ?? 'application/octet-stream');
			$parts[]='--'.$rootBoundary."\r\nContent-Type: ".$type.'; name="'.$this->escapeHeader($filename)."\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"".$this->escapeHeader($filename)."\"\r\n\r\n".chunk_split(base64_encode((string)($attachment['content'] ?? '')));
		}
		$parts[]='--'.$rootBoundary.'--';
		return implode("\r\n", $parts);
	}

	/**
	 * Writes one SMTP command and validates the expected response code.
	 *
	 * @param string $command Command line without trailing CRLF.
	 * @param array<int,int> $codes Acceptable three-digit SMTP response codes.
	 * @return string Raw server response including continuation lines.
	 * @throws \RuntimeException When writing fails or the response code is unexpected.
	 */
	private function command(string $command, array $codes): string {
		$this->write($command);
		return $this->expect($codes);
	}

	/**
	 * Writes one CRLF-terminated line to the active SMTP socket.
	 *
	 * @param string $line Line content without trailing CRLF.
	 * @return void
	 * @throws \RuntimeException When the socket is unavailable or fwrite fails.
	 */
	private function write(string $line): void {
		if(!is_resource($this->socket) || fwrite($this->socket, $line."\r\n")===false){
			throw new \RuntimeException('SMTP write failed.');
		}
	}

	/**
	 * Reads an SMTP response, including continuation lines, and checks its code.
	 *
	 * @param array<int,int> $codes Acceptable three-digit SMTP response codes.
	 * @return string Raw response text.
	 * @throws \RuntimeException When the server closes the connection or returns an unexpected code.
	 */
	private function expect(array $codes): string {
		$response='';
		do{
			$line=fgets($this->socket);
			if($line===false){
				throw new \RuntimeException('SMTP server closed the connection.');
			}
			$response.=$line;
			$more=isset($line[3]) && $line[3]==='-';
		}while($more);
		$code=(int)substr($response, 0, 3);
		if(!in_array($code, $codes, true)){
			throw new \RuntimeException('Unexpected SMTP response: '.trim($response));
		}
		return $response;
	}

	/**
	 * Formats a list of Message address arrays for a MIME header.
	 *
	 * @param array<int,array{email?:string,name?:string}> $addresses Recipient address data.
	 * @return string Comma-separated header value.
	 */
	private function addressList(array $addresses): string {
		return implode(', ', array_map(fn(array $address): string => $this->formatAddress($address), $addresses));
	}

	/**
	 * Formats one address for MIME headers.
	 *
	 * @param array{email?:string,name?:string} $address Address data.
	 * @return string Encoded display name with email, or the email alone.
	 */
	private function formatAddress(array $address): string {
		$email=(string)($address['email'] ?? '');
		$name=trim((string)($address['name'] ?? ''));
		return $name!=='' ? $this->encodeHeader($name).' <'.$email.'>' : $email;
	}

	/**
	 * Encodes non-ASCII header text using UTF-8 base64 encoded-word syntax.
	 *
	 * @param string $value Header value to sanitize.
	 * @return string Header-safe value without CRLF injection characters.
	 */
	private function encodeHeader(string $value): string {
		return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : str_replace(["\r", "\n"], '', $value);
	}

	/**
	 * Escapes quoted header parameter values.
	 *
	 * @param string $value Filename or parameter value.
	 * @return string Value safe for a quoted MIME parameter.
	 */
	private function escapeHeader(string $value): string {
		return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], $value);
	}
}
