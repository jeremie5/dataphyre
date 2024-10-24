<?php
namespace Shopiro;

class PromotionCoupon {
	
    private $shopiroClient;
	private $promotionCouponFactory;

    public function __construct(ShopiroClient $shopiroClient, PromotionCouponFactory $promotionCouponFactory) {
        $this->shopiroClient = $shopiroClient;
        $this->promotionCouponFactory = $promotionCouponFactory;
    }
	
    public function create(string $type, array $data) {
		$promotionCouponObject = $this->promotionCouponFactory->create($type, $data);
		return $promotionCouponObject;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'promotion_coupon'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $couponId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'promotion_coupon'], $payload=["cid" => $couponId]);
		return $response;
	}
	
	public function modify(array $coupon){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_promotion_coupon'], $payload=["act"=>"edit", "data" => $coupon]);
		return $response;
	}
 
 	public function delete(int $coupon){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_promotion_coupon'], $payload=["act"=>"remove", "data" => $coupon]);
		return $response;
	}
	
}