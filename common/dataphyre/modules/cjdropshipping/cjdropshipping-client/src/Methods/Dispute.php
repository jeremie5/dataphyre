<?php
namespace CJ;

class Dispute {

	/**
	 * Retrieves all disputes based on specified criteria and pagination details.
	 * 
	 * Supported Criteria:
	 * - orderId (string, optional): CJ order ID for filtering disputes. Max length: 100.
	 * - disputeId (integer, optional): Unique dispute ID for filtering. Max length: 10.
	 * - orderNumber (string, optional): Order number for filtering disputes. Max length: 100.
	 *
	 * Note: Criteria are optional and used for filtering.
	 *
	 * @param int|null $count Number of disputes to retrieve per page.
	 * @param int|null $offset Offset for pagination, used to calculate pageNum.
	 * @param array|null $criterias Array of search criteria for disputes.
	 * @return mixed Response from CJClient.
	 */
	public static function getAllDisputes(?int $count=null, ?int $offset=null, ?array $criterias=null) {
		$response = \CJ\CJClient::createRequest($endpoint="disputes/getDisputeList", $method="GET", $payload=$criterias);
		return $response;
	}

	public static function getDispute(string $orderId) {
		$response = \CJ\CJClient::createRequest($endpoint="disputes/disputeProducts", $method="GET", $payload=["orderId"=>$orderId]);
		return $response;
	}
	
	/**
	 * Confirms dispute details for a given order.
	 * 
	 * Data Structure:
	 * - orderId (string, mandatory): CJ order ID. Max length: 100.
	 * - productInfoList (object[], mandatory): List of product information objects. Each object should contain:
	 *   - lineItemId (string, optional): Line item ID of the product.
	 *   - quantity (integer, mandatory): Quantity of the product in dispute.
	 *   - price (BigDecimal, mandatory): Price of the product in dispute. Format: (18,2), Unit: USD.
	 *
	 * Note: The productInfoList must include at least one product information object with mandatory fields filled.
	 *
	 * @param array $data Array containing the dispute confirmation details.
	 * @return mixed Response from CJClient.
	 */
	public static function confirmDispute(array $data) {
		$response = \CJ\CJClient::createRequest($endpoint="disputes/disputeConfirmInfo", $method="POST", $payload=$data);
		return $response;
	}
	
	/**
	 * Creates a dispute with specified details.
	 * 
	 * Data Structure:
	 * - businessDisputeId (string, mandatory): Customer business ID. Max length: 100.
	 * - orderId (string, mandatory): CJ order ID. Max length: 100.
	 * - disputeReasonId (integer, mandatory): ID of the dispute reason. Max length: 10.
	 * - expectType (integer, mandatory): Expected type of resolution. 1 for Refund, 2 for Reissue. Max length: 20.
	 * - refundType (integer, mandatory): Type of refund. 1 for balance, 2 for platform. Max length: 20.
	 * - messageText (string, mandatory): Text message explaining the dispute. Max length: 500.
	 * - imageUrl (string[], optional): Array of image URLs related to the dispute. Max length per URL: 200.
	 * - videoUrl (string[], optional): Array of video URLs related to the dispute. Max length per URL: 200.
	 * - productInfoList (object[], optional): List of product information objects. Each object should contain:
	 *   - price (BigDecimal, mandatory): Price of the product in dispute. Format: (18,2), Unit: USD.
	 *   - lineItemId (string, mandatory): Line item ID of the product. Max length: 100.
	 *   - quantity (integer, mandatory): Quantity of the product in dispute. Max length: 10.
	 *
	 * Note: Mandatory fields must be provided for creating the dispute. ProductInfoList can contain multiple product details if needed.
	 *
	 * @param array $data Array containing the dispute creation details.
	 * @return mixed Response from CJClient.
	 */
	public static function createDispute(array $data) {
		$response = \CJ\CJClient::createRequest($endpoint="disputes/create", $method="POST", $payload=$data);
		return $response;
	}
	
	public static function cancelDispute(string $orderId, string $disputeId) {
		$response = \CJ\CJClient::createRequest($endpoint="disputes/create", $method="POST", $payload=["orderId"=>$orderId, "disputeId"=>$disputeId]);
		return $response;
	}
	
}