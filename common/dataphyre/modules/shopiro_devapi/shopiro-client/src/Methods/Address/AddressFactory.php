<?php
namespace Shopiro\Address;

class AddressFactory {

    public function create(string $type, array $data) {
        return new AddressObject($data);
    }

}