<?php


class Notive_Astral_Helper_Webservice {
    protected static $instance;

    // Dev
//    private $webservice_url = 'http://develop.ws-astral.webservices.b/app_dev.php/';

    // Test
    // private $webservice_url = 'http://ws-astral.notive-beta.nl/';

    // Live
    private $webservice_url = 'http://ws.astral.org/';

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new Webservice;
        }
        return self::$instance;
    }

    private function _call($endpoint, $data = array())
    {
        $curl_call = curl_init();
        curl_setopt($curl_call, CURLOPT_URL, $this->webservice_url.'ws/'.$endpoint);
        curl_setopt($curl_call, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_call, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_call, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl_call, CURLOPT_POST, true);


        $data['site_id'] = Mage::getStoreConfig('Notive_Astral/general/username', Mage::app()->getStore());
        $data['customer_number'] = Mage::getStoreConfig('Notive_Astral/general/customer_number', Mage::app()->getStore());
        $data['password'] = sha1(Mage::getStoreConfig('Notive_Astral/general/password', Mage::app()->getStore()));

        curl_setopt($curl_call, CURLOPT_POSTFIELDS, http_build_query($data));

        $returned = curl_exec($curl_call);
        if ($returned == false) {
            return false;
        }
        return json_decode($returned, true);
    }

    public function auth()
    {
        $authResult = $this->_call('auth/login');

        if ($authResult['success'] == true) {
            return true;
        }
        return false;
    }

    public function saveOrder(Mage_Sales_Model_Order $mage_order)
    {
        $order = $this->_processOrder($mage_order);

        return $this->_call('orders/create', $order);
    }

    public function getTnT($order_id)
    {
        if ($this->auth()) {
            return $this->_call('orders/tracking', array(
	            'references'=>$order_id
            ));
        }
    }

    public function getStock($last_update)
    {
        return $this->_call('stock/get', array('updated_after'=>$last_update));
    }

    public function getOrderTracking(Mage_Sales_Model_Resource_Order_Collection $orders)
    {
        $result = array();
        $order_ids = array();
        foreach ($orders as $order) {
            $order_ids[] = $order->getRealOrderId();
        }
        return $this->_call('orders/tracking', array(
            'references'=>$order_ids
        ));
    }

    public function _processOrder(Mage_Sales_Model_Order $order)
    {
        $addr_billing = $order->getBillingAddress();
        $addr_shipping = $order->getShippingAddress();
	    list($street_billing, $street_number_billing) = $this->splitAddress($addr_billing);
	    list($street_shipping, $street_number_shipping) = $this->splitAddress($addr_shipping);

        $address = $addr_shipping ? $addr_shipping : $addr_billing;
        list($street, $street_number) = $this->splitAddress($address);

        $date = date('Y-m-d');
        if ($order->getData('preferred_delivery_date')) {
            $date = $order->getData('preferred_delivery_date');
        }

	    $data = array(
		    'date'=>$date,
		    'reference'=>$order->getRealOrderId(),
		    'shipping_address' => array(
                'company'          => $addr_shipping->getCompany(),
			    'first_name'       => $addr_shipping->getFirstname(),
			    'last_name'        => $addr_shipping->getLastname(),
			    'addressed_to'     => $addr_shipping->getName(),
			    'street'           => $street_shipping,
			    'street_number'    => $street_number_shipping,
			    'zipcode'          => $addr_shipping->getPostcode(),
			    'city'             => $addr_shipping->getCity(),
			    'country_code'     => $addr_shipping->getCountry(),
			    'phone_number'     => $addr_shipping->getTelephone(),
			    'email'            => $order->getCustomerEmail()
		    ),
		    'invoice_address'  => array(
                'company'          => $addr_billing->getCompany(),
			    'first_name'       => $addr_billing->getFirstname(),
			    'last_name'        => $addr_billing->getLastname(),
			    'addressed_to'     => $addr_billing->getName(),
			    'street'           => $street_billing,
			    'street_number'    => $street_number_billing,
			    'zipcode'          => $addr_billing->getPostcode(),
			    'city'             => $addr_billing->getCity(),
			    'country_code'     => $addr_billing->getCountry(),
			    'phone_number'     => $addr_billing->getTelephone(),
			    'email'            => $order->getCustomerEmail()
		    ),
		    'order_lines' => array()
	    );

        $articleCodeField = Mage::getStoreConfig('Notive_Astral/order/article_code_field', Mage::app()->getStore());
        foreach ($order->getItemsCollection() as $ol) {
            if ($ol->isDeleted() || $ol->getProductType() !== 'simple') {
                continue;
            }

            $product = $this->getProduct($ol);

            $articleCode = $product->getData($articleCodeField);
            if (strlen($articleCode) == 0) {
                $articleCode = $ol->getSku();
            }

            $data["order_lines"][] = array(
                'article_code'          => $articleCode,
                'article_description'   => $product ? $product->getName() : '',
                'quantity'              => (int)$ol->getData('qty_ordered')
            );
        }

        return $data;
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
}
