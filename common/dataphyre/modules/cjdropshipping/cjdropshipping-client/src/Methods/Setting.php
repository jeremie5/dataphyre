<?php
namespace CJ;

class Setting {

	public static function get() {
		$response = \CJ\CJClient::createRequest($endpoint="setting/get", $method="GET");
		return $response;
	}
	
}
