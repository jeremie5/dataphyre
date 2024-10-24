<?php
namespace CJ;

class Shopping {
	
	/**
	 * Creates an order with the provided data.
	 * 
	 * Data Structure:
	 * - orderNumber (string, mandatory): Unique identifier for the order from CJ partner. Max length: 50.
	 * - shippingCountryCode (string, mandatory): Country code for shipping. Max length: 200.
	 * - shippingCountry (string, mandatory): Country for shipping. Max length: 200.
	 * - shippingProvince (string, mandatory): Province for shipping. Max length: 200.
	 * - shippingCity (string, mandatory): City for shipping. Max length: 200.
	 * - shippingAddress (string, mandatory): Shipping address. Max length: 200.
	 * - shippingAddress2 (string, mandatory): Additional shipping address. Max length: 200.
	 * - shippingCustomerName (string, mandatory): Shipping name. Max length: 200.
	 * - shippingZip (string, mandatory): Zip code for shipping. Max length: 200.
	 * - shippingPhone (string, mandatory): Phone number for shipping. Max length: 200.
	 * - remark (string, optional): Order remark. Max length: 500.
	 * - logisticName (string, mandatory): Logistic name for shipping. Max length: 200.
	 * - fromCountryCode (string, mandatory): Warehouse's country code. Max length: 200.
	 * - houseNumber (string, optional): House number. Max length: 20.
	 * - email (string, optional): Email address. Max length: 50.
	 * - products (array, mandatory): List of products. Max length: 200 per product.
	 *   - vid (string, mandatory): Variant ID. Max length: 200.
	 *   - quantity (string, mandatory): Quantity. Max length: 200.
	 *
	 * Note: All mandatory fields must be provided to successfully create an order.
	 *
	 * @param array $data Array containing the order details.
	 * @return mixed Response from CJClient.
	 */
	public static function createOrder(array $data) {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/order/createOrder", $method="POST", $payload=$data);
		return $response;
	}
	
	/**
	 * Retrieves all orders based on specified criteria and pagination details.
	 * 
	 * Supported Criteria:
	 * - orderIds (list, optional): List of order IDs to filter. Max length: 100.
	 * - status (string, optional): Order status to filter. Default: CANCELLED. Possible values: CREATED, IN_CART, UNPAID, UNSHIPPED, SHIPPED, DELIVERED, CANCELLED, OTHER. Max length: 200.
	 *
	 * Note: The pageNum is calculated based on the provided count and offset values.
	 *
	 * @param int|null $count Number of orders to retrieve per page.
	 * @param int|null $offset Offset for pagination, used to calculate pageNum.
	 * @param array|null $criterias Array of search criteria for orders.
	 * @return mixed Response from CJClient.
	 */
	public static function getAllOrders(?int $count=null, ?int $offset=null, ?array $criterias=null) {
		if ($count !== null && $offset !== null) {
			$criterias['pageNum'] = intdiv($offset, $count) + 1;
		} else {
			$criterias['pageNum'] = null;
		}
		$criterias['pageSize'] = $count;
		$response = \CJ\CJClient::createRequest($endpoint="shopping/order/list", $method="GET", $payload=$criterias);
		return $response;
	}
	
	/**
	 * Retrieves a specific order based on provided criteria.
	 * 
	 * Supported Criteria:
	 * - orderId (string, mandatory): Unique identifier of the order. Max length: 200. Used for querying order details.
	 * - orderNum (string, mandatory): Unique order number. Max length: 200. Used for querying order details.
	 *
	 * Note: Either orderId or orderNum must be provided to retrieve the order details. Both are mandatory, and at least one must be specified in the criteria.
	 *
	 * @param array|null $criterias Array of search criteria for the order.
	 * @return mixed Response from CJClient.
	 */
	public static function getOrder(array $criterias) {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/order/getOrderDetail", $method="GET", $payload=$criterias);
		return $response;
	}
	
	public static function deleteOrder(string $orderId) {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/order/deleteOrder", $method="DELETE", $payload=["orderId"=>$orderId]);
		return $response;
	}
	
	public static function confirmOrder(string $orderId) {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/order/confirmOrder", $method="POST", $payload=["orderId"=>$orderId]);
		return $response;
	}
	
	public static function getBalance() {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/pay/getBalance", $method="GET", $payload=[]);
		return $response;
	}
	
	public static function payBalance(string $orderId) {
		$response = \CJ\CJClient::createRequest($endpoint="shopping/pay/payBalance", $method="POST", $payload=["orderId"=>$orderId]);
		return $response;
	}
	
}