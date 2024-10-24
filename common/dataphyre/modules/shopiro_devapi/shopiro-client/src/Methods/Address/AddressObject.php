<?php
namespace Shopiro;

class AddressObject extends BaseAddressObject {

    public function setType(string $value) {
        $this->data['type'] = $value;
    }

    public function setFirstName(string $value) {
        $this->data['first_name'] = $value;
    }
	
    public function setLastName(string $value) {
        $this->data['last_name'] = $value;
    }

    public function setCity(string $value) {
        $this->data['city'] = $value;
    }
	
    public function setCountry(string $value) {
        $this->data['country'] = $value;
    }
	
    public function setSubdivision(string $value) {
        $this->data['subdivision'] = $value;
    }
	
    public function setPostalCode(string $value) {
        $this->data['postal_code'] = $value;
    }
	
    public function setAddressLine1(string $value) {
        $this->data['address_line1'] = $value;
    }
	
    public function setAddressLine2(string $value) {
        $this->data['address_line2'] = $value;
    }
	
    public function setPhoneNumber(string $value) {
        $this->data['phone_number'] = $value;
    }

}