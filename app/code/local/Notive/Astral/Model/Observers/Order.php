<?php

class Notive_Astral_Model_Observers_Order extends Notive_Astral_Model_Observers_Order_Abstract {
    const ASTRAL_ORDER_STATUS_CODE_ERROR = 'notive_astral_error';
    const ASTRAL_ORDER_STATUS_CODE_OK = 'notive_astral_sent';

    const ASTRAL_SAVED_MARK = 'notive_astral_order_saved';

    private $_enabled,
            $_status_send;

    public function _construct()
    {
	    // On unhold
        parent::_construct();
        $this->_enabled =
            Mage::getStoreConfig('Notive_Astral/order/enabled', Mage::app()->getStore()) === '1';
        $this->_status_send =
            explode(',', Mage::getStoreConfig('Notive_Astral/order/status_send', Mage::app()->getStore()));
    }

    /**
     * Process order
     * @param Varien_Event_Observer|Mage_Sales_Model_Order $event
     * @param bool $manual this is manual call, not from event handler
     */
    public function sales_order_save_after($event, $manual = false)
    {
	    // SHIPPED
        $helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');

        if (!$manual && !$this->_enabled) {
            return;
        }

        if (!$manual) {
            $get_order = version_compare(Mage::getVersion(), '1.4', '<') === true ? 'getOrder' : 'getDataObject';

            if (!$event || !$event->$get_order()) {
                return;
            }

            $order = $event->$get_order();
        } else {
            $order = $event;
        }

        if ($order->getData(self::ASTRAL_SAVED_MARK)) {
            return;
        }

        if ($this->shouldSendOrder($order)) {
            $order->setData(self::ASTRAL_SAVED_MARK, true);

            $result = $helper_webservice->saveOrder($order);

            if ($result !== false && !is_null($result) && isset($result['success']) && $result['success']) {
                $this->setStateSent($order);
            } else {
                $msg = '';
                if ($result !== false && !is_null($result) && isset($result['error'])) {
                    $msg = $result['error'];
                }
                $this->setStateNotSent($order, $msg);
            }
        }
    }

    /**
     * Should we send the order to ASTRAL
     * @param  Mage_Sales_Model_Order $order
     * @return bool
     */
    protected function shouldSendOrder(Mage_Sales_Model_Order $order)
    {
        if (in_array($order->getStatus(), $this->_status_send)) {
            $comments = $order->getStatusHistoryCollection(true);
            $should_send = true;
            foreach ($comments as $comment) {
                if (preg_match('/^notive_astral_sent/Usi', $comment->getStatus())) {
                    $should_send = false;
                }
            }
            if ($should_send !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mark order as not sent
     *
     * @param Mage_Sales_Model_Order $order
     * @throws Mage_Core_Exception
     */
    public function setStateNotSent(Mage_Sales_Model_Order $order, $msg = '')
    {
        if (!$order->canHold()) {
            Mage::throwException($this->__('Error setting hold status.'));
        }
        $order->setHoldBeforeState($order->getState());
        $order->setHoldBeforeStatus($order->getStatus());
        $order->setState(
            Mage_Sales_Model_Order::STATE_HOLDED,
            self::ASTRAL_ORDER_STATUS_CODE_ERROR,
            'Order can\'t be sent to ASTRAL' . ($msg ? ".<br/>Error: {$msg}" : '.')
        )->save();
    }

    /**
     * Mark order as sent
     *
     * @param Mage_Sales_Model_Order $order
     * @throws Mage_Core_Exception
     */
    public function setStateSent(Mage_Sales_Model_Order $order)
    {
        if ($order->getHoldBeforeState() && !$order->canUnhold()) {
            Mage::throwException($this->__('Error removing hold status.'));
        }
        $state = $order->getHoldBeforeState() ? $order->getHoldBeforeState() : $order->getState();
        $order->setState($state, self::ASTRAL_ORDER_STATUS_CODE_OK, 'Order was sent to Astral.')->save();
        $order->setHoldBeforeState(null);
        $order->setHoldBeforeStatus(null);
    }

    /**
     * Mark order as pending
     *
     * @param Mage_Sales_Model_Order $order
     * @throws Mage_Core_Exception
     */
    public function setStatePending(Mage_Sales_Model_Order $order)
    {
        if ($order->getHoldBeforeState() && !$order->canUnhold()) {
            Mage::throwException($this->__('Error removing hold status.'));
        }
        $state = $order->getHoldBeforeState() ? $order->getHoldBeforeState() : $order->getState();
        $order->setState(Mage_Sales_Model_Order::STATE_NEW, true)->save();
        $order->setHoldBeforeState(null);
        $order->setHoldBeforeStatus(null);
    }

}
