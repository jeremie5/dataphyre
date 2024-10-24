<?php
namespace CJ;

class Logistic {

	/**
	 * Calculates freight based on specified criteria.
	 * 
	 * Supported Criteria:
	 * - startCountryCode (string, mandatory): Country of origin code. Max length: 200.
	 * - endCountryCode (string, mandatory): Country of destination code. Max length: 200.
	 * - zip (string, optional): Zip code for destination. Max length: 200.
	 * - taxId (string, optional): Tax ID for the shipment. Max length: 200.
	 * - houseNumber (string, optional): House number for the shipping address. Max length: 200.
	 * - iossNumber (string, optional): IOSS number for the shipment. Max length: 200.
	 * - quantity (int, mandatory): Quantity of the product for freight calculation. Max length: 10.
	 * - vid (string, mandatory): Variant ID of the product for freight calculation. Max length: 200.
	 *
	 * Note: Mandatory fields must be provided for accurate freight calculation.
	 *
	 * @param array $criterias Array containing the criteria for freight calculation.
	 * @return mixed Response from CJClient.
	 */
	public static function getFreight($criterias) {
		$response = \CJ\CJClient::createRequest($endpoint="logistic/freightCalculate", $method="POST", $payload=$criterias);
		return $response;
	}
	
	public static function getTracking(...$trackNumbers) {
		$response = \CJ\CJClient::createRequest($endpoint="logistic/getTrackInfo", $method="GET", $payload=$trackNumbers);
		return $response;
	}
	
}