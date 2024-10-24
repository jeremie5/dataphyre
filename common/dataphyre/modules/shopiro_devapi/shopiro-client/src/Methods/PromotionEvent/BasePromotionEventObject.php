<?php
namespace Shopiro;

class BasePromotionEventObject {
	
    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function save() {
		$response = \Shopiro\PromotionEvent::modify($this->data);
		unset($this->data);
		return $response;
    }
	
    public function delete() {
		$response = \Shopiro\PromotionEvent::delete($this->data['eid']);
		unset($this->data);
		return $response;
    }
	
}