<?php
namespace Shopiro;

class BasePromotionDiscountObject {
	
    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function save() {
		$response = \Shopiro\PromotionDiscount::modify($this->data);
		unset($this->data);
		return $response;
    }
	
    public function delete() {
		$response = \Shopiro\PromotionDiscount::delete($this->data['did']);
		unset($this->data);
		return $response;
    }
	
}