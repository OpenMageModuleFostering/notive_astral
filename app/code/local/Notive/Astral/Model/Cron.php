<?php

class Notive_Astral_Model_Cron {

    public function runOrders()
    {
	    if (Mage::getStoreConfig('Notive_Astral/order_status/enabled', Mage::app()->getStore()) === '1' || isset($_GET['notive_check'])) {
            $this->_checkOrderStatus();
        }
    }

    public function runStock()
    {
        if (Mage::getStoreConfig('Notive_Astral/stock_sync/enabled', Mage::app()->getStore()) === '1' || isset($_GET['notive_check'])) {
            $this->_checkStock();
        }
    }

    private function _checkOrderStatus()
    {
	    /**
	     * @var Notive_Astral_Helper_Webservice $helper_webservice
	     * @var Notive_Astral_Helper_Data $helper_data
	     */
        $helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
        $helper_data = Mage::getSingleton('Notive_Astral_Helper_Data');

        // Load the orders
        $orders = $helper_data->getSentOrders();
        if ($orders) {
            $tracking_orders = $helper_webservice->getOrderTracking($orders);
        } else {
            exit;
        }

        foreach ($orders as $order) {
            $tracking_order = $tracking_orders[$order->getRealOrderId()];
            if (!is_null($tracking_order) && $tracking_order['success'] == true) {
                $itemQty =  $order->getItemsCollection()->count();
                $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);

                $shipment = new Mage_Sales_Model_Order_Shipment_Api();
                $shipmentId = $shipment->create($order->getRealOrderId());
                $shipment_model = Mage::getModel('sales/order_shipment')->loadByIncrementId($shipmentId);

                $carrier_code = 'custom';

                // Currently no support for carriers
                // $carriers = $this->_getCarriers();
                // if (isset($carriers[$tracking_order['shipper']])) {
                //     $carrier_code = $carriers[$tracking_order['shipper']];
                // }

                $track_model = Mage::getModel('sales/order_shipment_track')
                    ->setShipment($shipment_model)
                    ->setData('title', 'Track & trace')
                    ->setData('number', $tracking_order['tracking_code'])
                    ->setData('carrier_code', $carrier_code)
                    ->setData('order_id', $shipment_model->getData('order_id'))
                    ->save();

                $shipment_model->sendEmail();
                $shipment_model->setEmailSent(true);
                $shipment_model->save();
            }
        }
    }

    private function _checkStock()
    {
        // Get the helpers
        /**
	     * @var Notive_Astral_Helper_Webservice $helper_webservice
	     */
        $helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
        $helper_data = Mage::getSingleton('Notive_Astral_Helper_Data');

        $last_sync_date = Mage::getStoreConfig('Notive_Astral/stock_sync/last_sync_date', Mage::app()->getStore());
        // If it's the first time we want to sync all products
        if ($last_sync_date == false || $last_sync_date == '') {
            $last_sync_date = '1970-01-01 00:00:00';
        }

        $thirteenDaysAgo = date('Y-m-d H:i:s', strtotime('-13 days'));
        if ($last_sync_date < $thirteenDaysAgo) {
            $last_sync_date = $thirteenDaysAgo;
        }

        $_stock = $helper_webservice->getStock($last_sync_date);

        $articleCodeField = Mage::getStoreConfig('Notive_Astral/order/article_code_field', Mage::app()->getStore());
        if (isset($_stock['success']) && $_stock['success'] == true) {
            foreach ($_stock['returned'] as $sku => $qty) {
                $product = Mage::getModel('catalog/product')->loadByAttribute($articleCodeField, $sku);

                if ($product != false) {
                    $productId = $product->getId();
                    $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
                    $stockItem->setData('qty', $qty);
                    try {
                        $stockItem->save();
                    } catch (Exception $e) {

                    }
                }
            }
        }

        $configModel = Mage::getModel('core/config');
        $configModel->saveConfig('Notive_Astral/stock_sync/last_sync_date', date('Y-m-d H:i:s'));
    }

    public function _getCarriers()
    {
        $carriers_config = Mage::getModel('shipping/config');
        $return = array();

        if ($carriers_config !== false) {
            $active_carriers = $carriers_config->getActiveCarriers();
            foreach ($active_carriers as $carrier) {
                $carrierCode = $carrier->getCarrierCode();
                $carrierName = Mage::getStoreConfig('carriers/' . $carrierCode . '/title');
                if (empty($carrierName)) {
                    $carrierName = Mage::getStoreConfig('customtrackers/' . $carrierCode . '/title');
                }

                $return[$carrierName] = $carrierCode;
            }
        }
        return $return;
    }

}
