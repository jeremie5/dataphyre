<?php
namespace Shopiro;

class BasePromotionCouponObject {
	
    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function save() {
		$response = \Shopiro\PromotionCoupon::modify($this->data);
		unset($this->data);
		return $response;
    }
	
    public function delete() {
		$response = \Shopiro\PromotionCoupon::delete($this->data['cid']);
		unset($this->data);
		return $response;
    }
	
}