<?php

$setup = Mage::getResourceModel('sales/setup', 'default_write');

$setup->startSetup();

// Add statusses for magento version 1.5 up
if (version_compare(Mage::getVersion(), '1.5', '>=') === true) {
    $model = Mage::getModel('sales/order_status')->load(Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_ERROR);
    $model->setStatus(Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_ERROR);
    $model->setLabel('Not Sent To Astral');
    $model->save();
    $model->assignState(Mage_Sales_Model_Order::STATE_HOLDED, false);

    $model = Mage::getModel('sales/order_status')->load(Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_OK);
    $model->setStatus(Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_OK);
    $model->setLabel('Sent To Astral');
    $model->save();
    $model->assignState(Mage_Sales_Model_Order::STATE_PROCESSING, false);
}
// Cache refresh
if (method_exists($setup->_conn, 'resetDdlCache')) {
    $setup->_conn->resetDdlCache();
}

$setup->endSetup();
