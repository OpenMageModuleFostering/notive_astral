<?php

class Notive_Astral_Block_Adminhtml_Astralbackend extends Mage_Adminhtml_Block_Template {

    public function getConfigValue($name)
    {
        return Mage::getStoreConfig('notive/astral/'.$name, Mage::app()->getStore());
    }

}
