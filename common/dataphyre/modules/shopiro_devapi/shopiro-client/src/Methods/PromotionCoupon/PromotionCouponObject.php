<?php
namespace Shopiro;

class PromotionCouponObject extends BasePromotionCouponObject {

    public function setType(string $value) {
        $this->data['type'] = $value;
    }
	
    public function setValue(float $value) {
        $this->data['value'] = $value;
    }

    public function setEvent(int $value) {
        $this->data['eventid'] = $value;
    }
	
    public function setConditions(array $value) {
        $this->data['conditions'] = $value;
    }

}