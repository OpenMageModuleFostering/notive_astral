<?php
class Notive_Astral_Model_Attribute extends Varien_Object {

    public function toOptionHash()
    {
        switch ($this->getPath()) {
            case 'Notive_Astral/order/article_code_field':
                return $this->getAttributes();
        }
    }

    protected function getAttributes()
    {
        $ret = array();
        $productAttrs = Mage::getResourceModel('catalog/product_attribute_collection');
        foreach ($productAttrs as $productAttr) {
            $label = $productAttr->getFrontendLabel();
            if (strlen($label) == 0) {
                $label = '-';
            }

            $ret[$productAttr->getAttributeCode()] = $label . ' (' . $productAttr->getAttributeCode() . ')';
        }
        natsort($ret);
        return $ret;
    }

    public function toOptionArray()
    {
        $arr = array();
        foreach ($this->toOptionHash() as $v => $l) {
            if (!is_array($l)) {
                $arr[] = array('label' => $l, 'value' => $v);
            } else {
                $options = array();
                foreach ($l as $v1 => $l1) {
                    $options[] = array('value' => $v1, 'label' => $l1);
                }
                $arr[] = array('label' => $v, 'value' => $options);
            }
        }
        return $arr;
    }
}
