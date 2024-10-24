<?php
namespace CJ;

class Webhook {

	/**
	 * Sets webhook configurations based on specified data.
	 * 
	 * Data Structure:
	 * - product (object, mandatory): Product message settings. Max length: 200.
	 *   - type (string, mandatory): Type of product message. Possible values: 'ALL', 'CANCEL'. Max length: 200.
	 *   - callbackUrls (list, mandatory): List of callback URLs for product messages. Max length per URL: 5.
	 * - stock (object, mandatory): Stock message settings. Max length: 200.
	 *   - type (string, mandatory): Type of stock message. Possible values: 'ALL', 'CANCEL'. Max length: 200.
	 *   - callbackUrls (list, mandatory): List of callback URLs for stock messages. Max length per URL: 5.
	 *
	 * Note: The 'product' and 'stock' objects must include 'type' and 'callbackUrls'. 'type' defines the message type, and 'callbackUrls' specifies the URLs to which the webhook will send notifications.
	 *
	 * @param array $data Array containing the webhook configuration details.
	 * @return mixed Response from CJClient.
	 */
	public static function set(array $data) {
		$response = \CJ\CJClient::createRequest($endpoint="webhook/set", $method="POST", $payload=$data);
		return $response;
	}
	
}
