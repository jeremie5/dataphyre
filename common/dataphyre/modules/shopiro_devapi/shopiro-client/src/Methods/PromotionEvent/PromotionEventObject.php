<?php
namespace Shopiro;

class PromotionEventObject extends BasePromotionEventObject {

    public function setName(int $value) {
        $this->data['name'] = $value;
    }
	
    public function setConditions(array $value) {
        $this->data['conditions'] = $value;
    }

}