<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Emits panel page results through native PHP response primitives.
 *
 * The emitter centralizes status, header, Set-Cookie preservation, and optional
 * body output for panel front-controller paths that do not use a framework
 * response object.
 */
final class PanelResponseEmitter {

	/**
	 * Sends headers and optionally echoes the page body.
	 *
	 * Header emission is skipped when headers have already been sent. The content
	 * is always returned so tests and embedded callers can inspect the response
	 * without enabling output.
	 *
	 * @param PanelPageResult $result Panel response payload.
	 * @param bool $sendBody Whether to echo the content after sending headers.
	 * @return string Response body content.
	 */
	public static function emit(PanelPageResult $result, bool $sendBody=true): string {
		if(!headers_sent()){
			http_response_code($result->status());
			foreach($result->headers() as $name=>$value){
				$name=trim((string)$name);
				if($name===''){
					continue;
				}
				foreach(self::headerValues($value) as $headerValue){
					header($name.': '.$headerValue, strtolower($name)!=='set-cookie');
				}
			}
		}
		$content=$result->content();
		if($sendBody){
			echo $content;
		}
		return $content;
	}

	/**
	 * Normalizes a header value into non-empty scalar header lines.
	 *
	 * @param mixed $value Scalar value or list of scalar values from PanelPageResult.
	 * @return array<int, string> Header values safe to pass to header().
	 */
	private static function headerValues(mixed $value): array {
		if(is_array($value)){
			$values=[];
			foreach($value as $item){
				if(is_scalar($item)){
					$item=trim((string)$item);
					if($item!==''){
						$values[]=$item;
					}
				}
			}
			return $values;
		}
		if(!is_scalar($value)){
			return [];
		}
		$value=trim((string)$value);
		return $value!=='' ? [$value] : [];
	}
}
