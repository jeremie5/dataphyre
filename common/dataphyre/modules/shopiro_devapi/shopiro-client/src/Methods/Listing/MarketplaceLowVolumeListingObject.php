<?php
namespace Shopiro\Listing;

class MarketplaceLowVolumeListingObject extends BaseListingObject {

    public function toJSON() {
        return json_encode((array)$this->data);
    }

    public function toArray() {
        return (array)$this->data;
    }

    public function setSubtype(string $value) {
        $this->data['subtype'] = $value;
    }

    public function setTitle(string $language, string $value) {
        $this->data['titles'][$language] = $value;
    }

    public function setDescription(string $language, string $value) {
        $this->data['descriptions'][$language] = $value;
    }

    public function setShippingData(array $value) {
        $this->data['shipping_data'] = $value;
    }

    public function setInformations(array $value) {
        $this->data['information'] = $value;
    }

    public function setRestrictions(array $value) {
        $this->data['restrictions'] = $value;
    }

    public function setCustomReferences(array $value) {
        $this->data['custom_reference'] = $value;
    }

    public function setCategories(array $value) {
        $this->data['categories'] = $value;
    }

    public function setIsDraft(bool|int|string $value) {
        $this->data['is_draft'] = (bool)$value;
    }

    public function setMinorVariations(array $value) {
        $this->data['minor_variations'] = $value;
    }

    public function setMajorVariations(array $value) {
        $this->data['major_variations'] = $value;
    }

    public function setMetadata(array $value) {
        $this->data['metadata'] = $value;
    }

}