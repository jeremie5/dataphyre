<?php
namespace CJ;

class Balance {

	public static function get() {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/pay/getBalance", $method="GET");
		return $response;
	}
	
	public static function pay($orderId) {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/pay/payBalance", $method="POST", $payload=["orderId"=>$orderId]);
		return $response;
	}
	
}