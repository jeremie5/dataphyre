<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Drivers;

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\Support\AwsSignatureV4;
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;

/**
 * Storage driver for AWS S3 and S3-compatible object stores.
 *
 * The driver maps Dataphyre's filesystem-like StorageDriver interface onto signed HTTP requests.
 * Object keys are normalized with Path::normalize(), requests are signed with AWS Signature
 * Version 4, and response status codes are collapsed into booleans, strings, streams,
 * FileMetadata values, or multipart data expected by storage callers.
 *
 * This class performs network I/O and persists object data in the configured bucket. It does
 * not retry failed requests, validate bucket policy, or translate provider-specific XML errors.
 * Unsuccessful HTTP responses are exposed through false, empty lists, or falsey metadata
 * according to each storage operation's return shape.
 */
final class S3CompatibleDriver implements StorageDriver {

	/**
	 * Creates a driver with S3 endpoint and credential configuration.
	 *
	 * Expected configuration keys are interpreted by AwsSignatureV4 and objectUrl(), including
	 * endpoint, bucket, region, credentials, style, and optional public_url. Credentials remain
	 * in memory on the driver instance and are used only to sign outgoing requests or presigned
	 * URLs; they are not persisted by this driver.
	 *
	 * @param array<string, mixed> $config S3-compatible connection, signing, and URL configuration.
	 */
	public function __construct(private array $config) {
	}

	/**
	 * Checks whether an object key currently exists.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @return bool Whether the HEAD request returned a 2xx status.
	 */
	public function exists(string $path): bool {
		$response=$this->request('HEAD', $path);
		return ($response['status'] ?? 0)>=200 && ($response['status'] ?? 0)<300;
	}

	/**
	 * Reads an object body into memory.
	 *
	 * Options are accepted for interface compatibility but are not interpreted by this driver for
	 * normal reads. The full response body is buffered before being returned.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param array<string, mixed> $options Read options reserved for future provider-specific behavior.
	 * @return string|false Object contents on a 2xx response, or false when the request fails.
	 */
	public function read(string $path, array $options=[]): string|false {
		$response=$this->request('GET', $path);
		return (($response['status'] ?? 0)>=200 && ($response['status'] ?? 0)<300) ? (string)$response['body'] : false;
	}

	/**
	 * Reads an object and wraps the body in a stream resource.
	 *
	 * This implementation buffers the full object before creating the stream. Use
	 * provider-specific multipart or range support elsewhere for very large objects.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param array<string, mixed> $options Read options passed through to read().
	 * @return resource|false Readable stream resource, or false when the object cannot be read.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$contents=$this->read($path, $options);
		return is_string($contents) ? Stream::fromString($contents) : false;
	}

	/**
	 * Writes an object to the configured bucket.
	 *
	 * Resource contents are read into memory before upload. Supported options map to S3 headers:
	 * content_type, visibility=public, cache_control, and content_disposition. Public visibility
	 * is sent as x-amz-acl=public-read; final accessibility still depends on provider policy.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param mixed $contents Stringable contents or a readable resource.
	 * @param array{content_type?:string, visibility?:string, cache_control?:string, content_disposition?:string} $options Upload header options.
	 * @return bool Whether the PUT request returned a 2xx status.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$body=is_resource($contents) ? (Stream::contents($contents) ?: '') : (string)$contents;
		$headers=[
			'content-type'=>(string)($options['content_type'] ?? 'application/octet-stream'),
		];
		if(isset($options['visibility']) && $options['visibility']==='public'){
			$headers['x-amz-acl']='public-read';
		}
		if(isset($options['cache_control'])){
			$headers['cache-control']=(string)$options['cache_control'];
		}
		if(isset($options['content_disposition'])){
			$headers['content-disposition']=(string)$options['content_disposition'];
		}
		$response=$this->request('PUT', $path, $body, $headers);
		return ($response['status'] ?? 0)>=200 && ($response['status'] ?? 0)<300;
	}

	/**
	 * Deletes an object key.
	 *
	 * A 404 response is treated as success so delete remains idempotent for callers that model
	 * storage paths as desired state.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @return bool Whether the object is deleted or already absent.
	 */
	public function delete(string $path): bool {
		$response=$this->request('DELETE', $path);
		return in_array((int)($response['status'] ?? 0), [200, 202, 204, 404], true);
	}

	/**
	 * Reads metadata for an object without downloading its body.
	 *
	 * S3 headers are converted into Dataphyre FileMetadata. The normalized object path is
	 * preserved, content-length becomes size, last-modified becomes a Unix timestamp when
	 * parseable, content-type is copied, and ETag is exposed in the extra metadata array.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @return FileMetadata|false Metadata on a 2xx HEAD response, or false when unavailable.
	 */
	public function metadata(string $path): FileMetadata|false {
		$response=$this->request('HEAD', $path);
		$status=(int)($response['status'] ?? 0);
		if($status<200 || $status>=300){
			return false;
		}
		$headers=$response['headers'] ?? [];
		return new FileMetadata(
			Path::normalize($path),
			isset($headers['content-length']) ? (int)$headers['content-length'] : null,
			isset($headers['last-modified']) ? strtotime($headers['last-modified']) ?: null : null,
			$headers['content-type'] ?? null,
			['etag'=>$headers['etag'] ?? null]
		);
	}

	/**
	 * Lists objects under a prefix.
	 *
	 * The driver uses S3 ListObjectsV2 and returns an empty list for request failures or
	 * unparsable XML. Directory markers are not interpreted specially; every returned Contents
	 * entry becomes a FileMetadata row.
	 *
	 * @param string $prefix Object key prefix.
	 * @param array{limit?:int} $options Listing options; limit maps to max-keys.
	 * @return array<int, FileMetadata> Object metadata rows returned by the provider.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$query=http_build_query([
			'list-type'=>2,
			'prefix'=>Path::normalize($prefix),
			'max-keys'=>(int)($options['limit'] ?? 1000),
		]);
		$response=$this->request('GET', '', '', [], $query);
		if(($response['status'] ?? 0)<200 || ($response['status'] ?? 0)>=300){
			return [];
		}
		$xml=@simplexml_load_string((string)$response['body']);
		if(!$xml){
			return [];
		}
		$items=[];
		foreach($xml->Contents ?? [] as $object){
			$items[]=new FileMetadata((string)$object->Key, (int)$object->Size, strtotime((string)$object->LastModified) ?: null);
		}
		return $items;
	}

	/**
	 * Creates a temporary download URL for an object.
	 *
	 * When public_url is configured, this method returns the public object URL and ignores
	 * expiration because signing is not used for public buckets or CDN frontends. Otherwise it
	 * returns a Signature V4 presigned GET URL whose expiry is enforced by the provider.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param int|\DateTimeInterface $expires Expiration accepted by AwsSignatureV4.
	 * @param array<string, mixed> $options Reserved for interface compatibility.
	 * @return string|false Public or presigned download URL, or false when signing fails.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		if(!empty($this->config['public_url'])){
			return rtrim((string)$this->config['public_url'], '/').'/'.str_replace('%2F', '/', rawurlencode(Path::normalize($path)));
		}
		return AwsSignatureV4::presignedUrl('GET', $this->objectUrl($path), $this->config, $expires);
	}

	/**
	 * Creates a presigned PUT URL for direct browser/client uploads.
	 *
	 * Upload options become signed headers, so clients using the URL must send matching header
	 * values. The URL grants direct write access to the object key until expiry and does not
	 * create a server-side object until the client performs the PUT request.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param int|\DateTimeInterface $expires Expiration accepted by AwsSignatureV4.
	 * @param array{content_type?:string, visibility?:string, cache_control?:string} $options Headers to include in the signature.
	 * @return string|false Presigned upload URL, or false when signing fails.
	 */
	public function temporaryUploadUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		$headers=[];
		if(isset($options['content_type'])){
			$headers['content-type']=(string)$options['content_type'];
		}
		if(isset($options['visibility']) && $options['visibility']==='public'){
			$headers['x-amz-acl']='public-read';
		}
		if(isset($options['cache_control'])){
			$headers['cache-control']=(string)$options['cache_control'];
		}
		return AwsSignatureV4::presignedUrl('PUT', $this->objectUrl($path), $this->config, $expires, $headers);
	}

	/**
	 * Starts a provider-side multipart upload.
	 *
	 * Supported options map to the same content and visibility headers used by write(). The
	 * provider owns the upload session; this driver does not persist upload ids, so callers must
	 * retain the returned id and later supply it to multipart URL generation, completion, or
	 * abort calls.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param array{content_type?:string, visibility?:string, cache_control?:string} $options Multipart initiation headers.
	 * @return array{upload_id:string, path:string}|false Upload session details, or false when initiation fails.
	 */
	public function initiateMultipartUpload(string $path, array $options=[]): array|false {
		$headers=[];
		if(isset($options['content_type'])){
			$headers['content-type']=(string)$options['content_type'];
		}
		if(isset($options['visibility']) && $options['visibility']==='public'){
			$headers['x-amz-acl']='public-read';
		}
		if(isset($options['cache_control'])){
			$headers['cache-control']=(string)$options['cache_control'];
		}
		$response=$this->request('POST', $path, '', $headers, 'uploads=');
		$status=(int)($response['status'] ?? 0);
		if($status<200 || $status>=300){
			return false;
		}
		$xml=@simplexml_load_string((string)($response['body'] ?? ''));
		$uploadId=$xml ? (string)($xml->UploadId ?? '') : '';
		return $uploadId!=='' ? ['upload_id'=>$uploadId, 'path'=>Path::normalize($path)] : false;
	}

	/**
	 * Creates presigned URLs for each part of an active multipart upload.
	 *
	 * Part count is clamped to S3's valid range of 1 through 10000. Returned array keys are
	 * one-based part numbers, matching the part numbers expected by completeMultipartUpload().
	 * Each URL signs the upload id and part number into the query string.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param string $uploadId Multipart upload id from initiateMultipartUpload().
	 * @param int $parts Number of part URLs requested.
	 * @param int|\DateTimeInterface $expires Expiration accepted by AwsSignatureV4.
	 * @param array{content_type?:string} $options Headers to include in each part signature.
	 * @return array<int, string>|false Map of part number to presigned PUT URL.
	 */
	public function temporaryMultipartUploadUrls(string $path, string $uploadId, int $parts, int|\DateTimeInterface $expires, array $options=[]): array|false {
		$parts=max(1, min(10000, $parts));
		$headers=[];
		if(isset($options['content_type'])){
			$headers['content-type']=(string)$options['content_type'];
		}
		$urls=[];
		for($part=1; $part<=$parts; $part++){
			$query=http_build_query([
				'partNumber'=>$part,
				'uploadId'=>$uploadId,
			]);
			$urls[$part]=AwsSignatureV4::presignedUrl('PUT', $this->objectUrl($path, $query), $this->config, $expires, $headers);
		}
		return $urls;
	}

	/**
	 * Completes a multipart upload with caller-supplied part ETags.
	 *
	 * Empty or invalid part lists are rejected locally before any network call. Parts may be
	 * supplied as partNumber => etag or as arrays containing part_number/PartNumber and etag/ETag
	 * keys. Successful completion makes the provider assemble the final object; this driver does
	 * not verify the completed object's checksum afterward.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param string $uploadId Multipart upload id from initiateMultipartUpload().
	 * @param array<int|string, string|array<string, mixed>> $parts Completed upload parts and ETags.
	 * @return bool Whether the complete request returned a 2xx status.
	 */
	public function completeMultipartUpload(string $path, string $uploadId, array $parts): bool {
		$body=$this->completeMultipartXml($parts);
		if($body===''){
			return false;
		}
		$response=$this->request('POST', $path, $body, ['content-type'=>'application/xml'], http_build_query(['uploadId'=>$uploadId]));
		return ($response['status'] ?? 0)>=200 && ($response['status'] ?? 0)<300;
	}

	/**
	 * Aborts a multipart upload session.
	 *
	 * A 404 response is treated as success so abort is idempotent for callers cleaning up unknown
	 * or already-finished upload sessions. Aborting only targets the provider-side upload session,
	 * not an already-completed object.
	 *
	 * @param string $path Object path relative to the configured bucket.
	 * @param string $uploadId Multipart upload id to abort.
	 * @return bool Whether the upload is aborted or already absent.
	 */
	public function abortMultipartUpload(string $path, string $uploadId): bool {
		$response=$this->request('DELETE', $path, '', [], http_build_query(['uploadId'=>$uploadId]));
		return in_array((int)($response['status'] ?? 0), [200, 202, 204, 404], true);
	}

	/**
	 * Sends one signed S3 HTTP request and normalizes the response.
	 *
	 * The returned array always contains status, headers, and body keys. When cURL is unavailable,
	 * status is zero and the response is empty so public methods can fail through their normal
	 * false/empty return path.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Object path relative to the bucket.
	 * @param string $body Request body used for signing and PUT/POST uploads.
	 * @param array<string, string> $headers Request headers to include in the signature.
	 * @param string $query Raw query string appended to the object URL.
	 * @return array{status:int, headers:array<string, string>, body:string} Normalized HTTP response.
	 */
	private function request(string $method, string $path, string $body='', array $headers=[], string $query=''): array {
		if(!function_exists('curl_init')){
			return ['status'=>0, 'body'=>'', 'headers'=>[]];
		}
		$url=$this->objectUrl($path, $query);
		$curl=curl_init($url);
		$requestHeaders=AwsSignatureV4::headers($method, $url, $this->config, $body, $headers);
		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST=>$method,
			CURLOPT_HTTPHEADER=>$requestHeaders,
			CURLOPT_RETURNTRANSFER=>true,
			CURLOPT_HEADER=>true,
		]);
		if($method==='PUT'){
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}
		if($method==='HEAD'){
			curl_setopt($curl, CURLOPT_NOBODY, true);
		}
		$raw=curl_exec($curl);
		$status=(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$headerSize=(int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		curl_close($curl);
		$raw=is_string($raw) ? $raw : '';
		return [
			'status'=>$status,
			'headers'=>$this->parseHeaders(substr($raw, 0, $headerSize)),
			'body'=>substr($raw, $headerSize),
		];
	}

	/**
	 * Builds a path-style or virtual-hosted-style object URL.
	 *
	 * @param string $path Object key relative to the bucket.
	 * @param string $query Optional raw query string.
	 * @return string Absolute URL used for signing and cURL requests.
	 */
	private function objectUrl(string $path, string $query=''): string {
		$endpoint=rtrim((string)($this->config['endpoint'] ?? 'https://s3.amazonaws.com'), '/');
		$bucket=trim((string)($this->config['bucket'] ?? ''), '/');
		$key=Path::normalize($path);
		$style=(string)($this->config['style'] ?? 'path');
		if($style==='virtual'){
			$host=parse_url($endpoint, PHP_URL_HOST);
			$scheme=parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
			$url=$scheme.'://'.$bucket.'.'.$host.'/'.str_replace('%2F', '/', rawurlencode($key));
		}
		else{
			$url=$endpoint.'/'.$bucket.($key!=='' ? '/'.str_replace('%2F', '/', rawurlencode($key)) : '');
		}
		return $query!=='' ? $url.'?'.$query : $url;
	}

	/**
	 * Parses raw HTTP response headers into lowercase names.
	 *
	 * @param string $raw Raw header block returned by cURL.
	 * @return array<string, string> Header map keyed by lowercase header name.
	 */
	private function parseHeaders(string $raw): array {
		$out=[];
		foreach(explode("\n", $raw) as $line){
			$line=trim($line);
			if($line==='' || !str_contains($line, ':')){
				continue;
			}
			[$name, $value]=explode(':', $line, 2);
			$out[strtolower(trim($name))]=trim($value);
		}
		return $out;
	}

	/**
	 * Builds the S3 CompleteMultipartUpload XML document.
	 *
	 * Invalid part numbers and blank ETags are skipped. Valid parts are sorted by part number
	 * before serialization because S3 requires completion XML to be ordered.
	 *
	 * @param array<int|string, string|array<string, mixed>> $parts Completed upload parts and ETags.
	 * @return string XML request body, or an empty string when no valid parts exist.
	 */
	private function completeMultipartXml(array $parts): string {
		$normalized=[];
		foreach($parts as $key=>$part){
			if(is_array($part)){
				$number=(int)($part['part_number'] ?? $part['PartNumber'] ?? $key);
				$etag=(string)($part['etag'] ?? $part['ETag'] ?? '');
			}
			else{
				$number=(int)$key;
				$etag=(string)$part;
			}
			$etag=trim($etag, "\" \t\n\r\0\x0B");
			if($number<1 || $etag===''){
				continue;
			}
			$normalized[$number]=$etag;
		}
		if($normalized===[]){
			return '';
		}
		ksort($normalized);
		$xml='<CompleteMultipartUpload>';
		foreach($normalized as $number=>$etag){
			$xml.='<Part><PartNumber>'.$number.'</PartNumber><ETag>"'.htmlspecialchars($etag, ENT_XML1 | ENT_COMPAT, 'UTF-8').'"</ETag></Part>';
		}
		return $xml.'</CompleteMultipartUpload>';
	}
}
