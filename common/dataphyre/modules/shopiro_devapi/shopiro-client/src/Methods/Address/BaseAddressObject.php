<?php
namespace Shopiro;

class BaseAddressObject {
	
    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function save() {
		return \Shopiro\Address::modify($this->data);
    }
	
    public function delete() {
		return \Shopiro\Address::delete($this->data['slid']);
    }
	
}