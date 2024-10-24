<?php
namespace CJ;

class Product {
	
	/**
	 * Retrieves all products based on specified criteria.
	 * 
	 * Supported Criteria:
	 * - pageNum (int, optional): Page number. Default is 1.
	 * - pageSize (int, optional): Number of results per page. Default is 20.
	 * - categoryId (string, optional): Category ID for inquiry. Max length: 200.
	 * - pid (string, optional): Product ID for inquiry. Max length: 200.
	 * - productSku (string, optional): Product SKU for inquiry. Max length: 200.
	 * - productName (string, optional): Product name for inquiry. Max length: 200.
	 * - productNameEn (string, optional): Product name in English. Max length: 200.
	 * - productType (string, optional): Type of product. Values: ORDINARY_PRODUCT, SUPPLIER_PRODUCT. Max length: 200.
	 * - countryCode (string, optional): Country code (e.g., CN, US). Max length: 200.
	 * - createTimeFrom (string, optional): Start of creation time range. Format: yyyy-MM-dd hh:mm:ss. Max length: 200.
	 * - createTimeTo (string, optional): End of creation time range. Format: yyyy-MM-dd hh:mm:ss. Max length: 200.
	 * - brandOpenId (long, optional): Brand ID for inquiry. Max length: 200.
	 * - minPrice (number, optional): Minimum price for inquiry (e.g., 1.0).
	 * - maxPrice (number, optional): Maximum price for inquiry (e.g., 2.5).
	 *
	 * @param int|null $count Number of items to retrieve.
	 * @param int|null $offset Offset for pagination.
	 * @param array|null $criterias Array of search criteria.
	 * @return mixed Response from CJClient.
	 */
	public static function getAll(?int $count=null, ?int $offset=null, ?array $criterias=null) {
		$criterias??=[];
		if ($count !== null && $offset !== null) {
			$criterias['pageNum'] = intdiv($offset, $count) + 1;
		} else {
			$criterias['pageNum'] = null;
		}
		$criterias['pageSize'] = $count;
		$response = \CJ\CJClient::createRequest($endpoint="product/list", $method="GET", $payload=$criterias);
		return $response;
	}
	
	/**
	 * Retrieves a specific product based on provided criteria.
	 * 
	 * Supported Criteria (choose one of the following):
	 * - pid (string, optional): Product ID for inquiry. Choose one of the following criteria. Max length: 200.
	 * - productSku (string, optional): Product SKU for inquiry. Choose one of the following criteria. Max length: 200.
	 * - variantSku (string, optional): Variant SKU for inquiry. Choose one of the following criteria. Max length: 200.
	 *
	 * Note: Only one of pid, productSku, or variantSku should be provided in the criteria.
	 *
	 * @param array|null $criterias Array of search criteria.
	 * @return mixed Response from CJClient.
	 */
	public static function getProduct(?array $criterias=null) {
		$response = \CJ\CJClient::createRequest($endpoint="product/query", $method="GET", $payload=$criterias);
		return $response;
	}
	
	/**
	 * Retrieves a specific product based on provided criteria.
	 * 
	 * Supported Criteria (choose one of the following):
	 * - pid (string, optional): Product ID for inquiry. Choose one of the following criteria. Max length: 200.
	 * - productSku (string, optional): Product SKU for inquiry. Choose one of the following criteria. Max length: 200.
	 * - variantSku (string, optional): Variant SKU for inquiry. Choose one of the following criteria. Max length: 200.
	 *
	 * Note: Only one of pid, productSku, or variantSku should be provided in the criteria.
	 *
	 * @param array|null $criterias Array of search criteria.
	 * @return mixed Response from CJClient.
	 */
	public static function getProductVariations(?array $criterias=null) {
		$response = \CJ\CJClient::createRequest($endpoint="product/variant/query", $method="GET", $payload=$criterias);
		return $response;
	}
	
	public static function getProductVariant(?string $variantId=null) {
		$response = \CJ\CJClient::createRequest($endpoint="product/variant/queryByVid", $method="GET", $payload=["vid"=>$variantId]);
		return $response;
	}
	
	public static function getProductVariantInventory(?string $variantId=null) {
		$response = \CJ\CJClient::createRequest($endpoint="product/stock/queryByVid", $method="GET", $payload=["vid"=>$variantId]);
		return $response;
	}
	
	/**
	 * Retrieves all product reviews based on specified criteria and pagination details.
	 * 
	 * Supported Criteria:
	 * - pid (string, mandatory): Product ID for inquiry. Max length: 200.
	 * - score (integer, optional): Score for filtering reviews. Max length: 20.
	 * - pageNum (int, optional): Page number for pagination. Calculated based on count and offset. Default is 1.
	 * - pageSize (int, optional): Number of reviews per page. Matches the count parameter. Default is 20.
	 *
	 * Note: The pageNum is calculated based on the provided count and offset values.
	 *
	 * @param int|null $count Number of reviews to retrieve per page.
	 * @param int|null $offset Offset for pagination, used to calculate pageNum.
	 * @param array|null $criterias Array of search criteria for reviews.
	 * @return mixed Response from CJClient.
	 */
	public static function getAllProductReviews(?int $count=null, ?int $offset=null, ?array $criterias=null) {
		if ($count !== null && $offset !== null) {
			$criterias['pageNum'] = intdiv($offset, $count) + 1;
		} else {
			$criterias['pageNum'] = null;
		}
		$criterias['pageSize'] = $count;
		$response = \CJ\CJClient::createRequest($endpoint="product/comments", $method="GET", $payload=$criterias);
		return $response;
	}
	
	/**
	 * Creates a sourcing request with specified criteria.
	 * 
	 * Supported Criteria:
	 * - thirdProductId (string, optional): Third-party product ID. Max length: 200.
	 * - thirdVariantId (string, optional): Third-party variant ID. Max length: 200.
	 * - thirdProductSku (string, optional): Third-party product SKU. Max length: 200.
	 * - productName (string, mandatory): Name of the product. Max length: 200.
	 * - productImage (string, mandatory): Image URL of the product. Max length: 200.
	 * - productUrl (string, optional): URL of the product. Max length: 200.
	 * - remark (string, optional): Additional remarks or notes. Max length: 200.
	 * - price (BigDecimal, optional): Expected price of the product in USD. Max length: 200.
	 *
	 * Note: productName and productImage are mandatory fields for the sourcing request.
	 *
	 * @param array $criterias array of the sourcing request criteria.
	 * @return mixed Response from CJClient.
	 */
	public static function createSourcingRequest(array $criterias) {
		$response = \CJ\CJClient::createRequest($endpoint="product/sourcing/create", $method="POST", $payload=$criterias);
		return $response;
	}
	
	public static function getSourcingRequest(string|array $requestIds) {
		if(!is_array($requestIds) && is_string($requestIds))$requestIds[]=$requestIds;
		$response = \CJ\CJClient::createRequest($endpoint="product/sourcing/query", $method="POST", $payload=$requestIds);
		return $response;
	}

}