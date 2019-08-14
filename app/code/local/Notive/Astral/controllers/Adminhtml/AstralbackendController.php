<?php

// Used for testing purposes
class Notive_Astral_Adminhtml_AstralbackendController extends Mage_Adminhtml_Controller_Action {

	/**
	 * http://1.magento.b/index.php/astral/adminhtml_astralbackend/index/key/23f32ca093956e94d708367024363344/
	 */
    public function indexAction()
    {
        header('Content-type: text/plain');
        $webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
        $order = Mage::getModel('sales/order')->load(7);
        var_dump($webservice->_processOrder($order));
        exit;
//	    ini_set("display_errors", 1);
//	    $cronModel = Mage::getModel('notive_astral/cron');
//	    echo '<pre>';
//	    var_dump($cronModel->runStock());
//	    die('</pre>');
//	    exit;
    }
    private function _randomTests()
    {
        $product = Mage::getModel('catalog/product')->loadByAttribute('sku', '146_e');
        $productStockedValue = $this->getStockedByProductId($product->getId());

        echo '<pre>';
        var_dump($productStockedValue);
        echo '<pre>';
        die('done');
    }


    public function getStockedByProductId($product_id)
    {
        $websiteId = Mage::app()->getWebsite()->getId();
        $optionId = Mage::getResourceModel('catalog/product')->getAttributeRawValue($product_id, 'stocked_or_cross_docked', $websiteId);
        $attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product', 'stocked_or_cross_docked');
        $collection = Mage::getResourceModel('eav/entity_attribute_option_collection')
            ->setPositionOrder('asc')
            ->setAttributeFilter($attributeId)
            ->setStoreFilter(0)
            ->load();

        $collection = $collection->toOptionArray();
        $return = '';
        foreach ($collection as $option) {
            if ($option['value'] == $optionId) {
                $return = $option["label"];
                break;
            }
        }
        return $return;
    }

    private function _sendOrder()
    {
        $order = Mage::getModel('sales/order')
            ->loadByIncrementId('100000030');

        $helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
        echo '<pre>';
        var_dump($helper_webservice->saveOrder($order));
        echo '<pre>';
        die('done');
    }


    private function _checkOrder()
    {
        $order = Mage::getModel('sales/order')
            ->loadByIncrementId('100000024');

        $addr_billing = $order->getBillingAddress();
        $addr_shipping = $order->getShippingAddress();
        $address = $addr_shipping ? $addr_shipping : $addr_billing;
        list($street, $street_number) = $this->splitAddress($address);

        $date = date('Y-m-d');
        if ($order->getData('preferred_delivery_date')) {
            $date = $order->getData('preferred_delivery_date');
        }

        $data = array(
            'date'=>$date,
            'reference'=>$order->getRealOrderId(),
            'address' => array(
                'addressed_to'=>$address->getName(),
                'street'=>$street,
                'street_number'=>$street_number,
                'number_extention'=>null,
                'zipcode'=>$address->getPostcode(),
                'city'=>$address->getCity(),
                'country_code'=>$address->getCountry(),
                'phone_number'=>$address->getTelephone(),
                'email'=>$order->getCustomerEmail()
            ),
            'order_lines' => array()
        );

        if ($address->getCompany() != '') {
            $data['address']['addressed_to'] = $address->getCompany();
            $data['address']['contactperson'] = $address->getName();
        }

        foreach ($order->getItemsCollection() as $ol) {
            if ($ol->isDeleted() || $ol->getProductType() !== 'simple') {
                continue;
            }

            $product = $this->getProduct($ol);

            $data["order_lines"][] = array(
                'article_code'          => $ol->getSku(),
                'article_description'   => $product ? $product->getName() : '',
                'quantity'              => (int)$ol->getData('qty_ordered')
            );
        }

        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        die('done');
    }


    private function _checkStock()
    {
        // Get the helpers
        $helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
        $helper_data = Mage::getSingleton('Notive_Astral_Helper_Data');

        $_stock = $helper_webservice->getStock();

        if (isset($_stock['success']) && $_stock['success'] == true) {
            foreach ($_stock['returned'] as $sku => $qty) {
                $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);

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
    }

    private function _checkOrderStatus()
    {
        // Get the helpers
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
            if (!is_null($tracking_order) /* && $tracking_order['success'] == true */) {
                $itemQty =  $order->getItemsCollection()->count();
                $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);

                $shipment = new Mage_Sales_Model_Order_Shipment_Api();
                $shipmentId = $shipment->create($order->getRealOrderId());
                $shipment_model = Mage::getModel('sales/order_shipment')->loadByIncrementId($shipmentId);

                $carrier_code = 'custom';
                $carriers = $this->_getCarriers();
                if (isset($carriers[$tracking_order['shipper']])) {
                    $carrier_code = $carriers[$tracking_order['shipper']];
                }

                $track_model = Mage::getModel('sales/order_shipment_track')
                    ->setShipment($shipment_model)
                    ->setData('title', $tracking_order['shipper'])
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

    /**
     * Tries get related product from order line
     * @param Mage_Sales_Model_Order_Item $order_line
     * @return Mage_Catalog_Model_Product|NULL
     */
    private function getProduct(Mage_Sales_Model_Order_Item $order_line)
    {
        // get product
        $product = Mage::getModel('catalog/product')->load($order_line->getProductId());
        if (empty($product) || ($order_line->getProductType() == 'configurable')) {
            $options = $order_line->getProductOptions();
            if (count($options)) {
                $product = false;
                if (isset($options['simple_sku']) && !empty($options['simple_sku'])) {
                    $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $options['simple_sku']);
                } elseif (isset($options['info_buyRequest']) && isset($options['info_buyRequest']['product'])) {
                    $product = Mage::getModel('catalog/product')->load($options['info_buyRequest']['product']);
                } elseif (isset($options['super_product_config']) && isset($options['super_product_config']['product_id'])) {
                    $product = Mage::getModel('catalog/product')->load($options['super_product_config']['product_id']);
                }
            }
        }
        if ($product && $product->getId()) {
            return $product;
        }
        $sku = $order_line->getSku();
        $pid = Mage::getModel('catalog/product')->getIdBySku($sku);
        return $pid ? Mage::getModel('catalog/product')->load($pid) : null;
    }

    /**
     * Split multiline magento address to address and house number
     * @var Mage_Sales_Model_Order_Address $address
     * @return array [0] address [1] house no
     */
    private function splitAddress(Mage_Sales_Model_Order_Address $address)
    {
        $adr = $address->getStreetFull();
        $lines = preg_split("/[\n|\r]/", $adr);
        if (!count($lines)) {
            $lines = array($adr);
        }

        $no = '';
        $parts = explode(' ', $lines[0]);
        if (count($parts) > 1) {
            $no = end($parts);
            unset($parts[count($parts) - 1]); // remove last
        }

        $street = implode(' ', $parts);

        if (count($lines) > 1) {
            $street .= "\n{$lines[1]}";
        }

        return array($street, $no);
    }

	// Temp code, delete
	private function oldCodeBlock(){

		// Get the helpers
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
				$carriers = $this->_getCarriers();
				if (isset($carriers[$tracking_order['shipper']])) {
					$carrier_code = $carriers[$tracking_order['shipper']];
				}

				$track_model = Mage::getModel('sales/order_shipment_track')
				                   ->setShipment($shipment_model)
				                   ->setData('title', $tracking_order['shipper'])
				                   ->setData('number', $tracking_order['tracking_code'])
				                   ->setData('carrier_code', $carrier_code)
				                   ->setData('order_id', $shipment_model->getData('order_id'))
				                   ->save();

				$shipment_model->sendEmail();
				$shipment_model->setEmailSent(true);
				$shipment_model->save();
			}
		}
		/**
		 * @var Notive_Astral_Helper_Webservice $helper_webservice
		 */
		$helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
		$tracking   = $helper_webservice->getTnT('100000048');
		$stock      = $helper_webservice->getStock();

		var_dump($order);exit;

		// $this->_checkOrderStatus();
		// $this->_checkStock();
		// $this->_sendOrder();
		// $this->_randomTests();
	}

	private function checkOrderStatus()
	{
		/**
		 * @var Notive_Astral_Helper_Webservice $helper_webservice
		 * @var Notive_Astral_Helper_Data $helper_data
		 */
		$helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
		$helper_data = Mage::getSingleton('Notive_Astral_Helper_Data');

		// Load the orders
		$orders = $helper_data->getSentOrders();
		print_r($helper_webservice->getOrderTracking($orders));exit;
		if ($orders) {
			$tracking_orders = $helper_webservice->getOrderTracking($orders);
		} else {
			exit;
		}

		foreach ($orders as $order) {
			$tracking_order = $tracking_orders[$order->getRealOrderId()];
			print_r($tracking_order);exit;
			if (!is_null($tracking_order) && $tracking_order['success'] == true) {
				$itemQty =  $order->getItemsCollection()->count();
				$shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);

				$shipment = new Mage_Sales_Model_Order_Shipment_Api();
				$shipmentId = $shipment->create($order->getRealOrderId());
				$shipment_model = Mage::getModel('sales/order_shipment')->loadByIncrementId($shipmentId);

				$carrier_code = 'custom';
				$carriers = $this->_getCarriers();
				if (isset($carriers[$tracking_order['shipper']])) {
					$carrier_code = $carriers[$tracking_order['shipper']];
				}

				$track_model = Mage::getModel('sales/order_shipment_track')
				                   ->setShipment($shipment_model)
				                   ->setData('title', $tracking_order['shipper'])
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

	public function sts() {
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
			if( is_empty_date($tracking_order['success']) ){
				continue;
			}

			if (!is_null($tracking_order) && $tracking_order['success'] == true) {

				$order = Mage::getModel('sales/order')->loadByIncrementId($tracking_order['order_reference']);

				$itemQty =  $order->getItemsCollection()->count();
				$shipment = new Mage_Sales_Model_Order_Shipment_Api();
				$t = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);
				$shipmentId = $shipment->create($order->getRealOrderId());
				$shipment_model = Mage::getModel('sales/order_shipment')->loadByIncrementId($shipmentId);

				$carrier_code = 'custom';
				$carriers = $this->_getCarriers();
				if (isset($carriers[$tracking_order['shipper']])) {
					$carrier_code = $carriers[$tracking_order['shipper']];
				}

				$track_model = Mage::getModel('sales/order_shipment_track')
				                   ->setShipment($shipment_model)
				                   ->setData('title', 'water')
				                   ->setData('number', $tracking_order['tracking_code'])
				                   ->setData('carrier_code', $carrier_code)
				                   ->setData('order_id', $shipment_model->getData('order_id'))
				                   ->save();

				$shipment_model->sendEmail();
				$shipment_model->setEmailSent(true);
				$shipment_model->save();
				die('saved');

			}

		}
	}
}
