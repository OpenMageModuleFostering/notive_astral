<?php

class Notive_Astral_Sales_OrderController extends Mage_Adminhtml_Controller_Action {

    public function indexAction()
    {
        $id = $this->getRequest()->getParam('order_id');
        $order = Mage::getModel('sales/order')->load($id);
        $helper_webservice = Mage::getSingleton('Notive_Astral_Helper_Webservice');
        $observer = Mage::getModel('Notive_Astral_Model_Observers_Order');

        if (!$order->getId()) {
            $this->_getSession()->addError($this->__('This order no longer exists.'));
            $this->_redirect('adminhtml/sales_order/index');
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
            return false;
        }

	    /**
	     * @var Notive_Astral_Helper_Webservice $helper_webservice
	     */
	    $result = $helper_webservice->saveOrder($order);

        //Als order opgeslagen wordt, stuur door naar order model
        if ($result !== false && !is_null($result) && isset($result['success']) && $result['success']) {
            $this->_getSession()->addSuccess($this->__('The order was sent to ASTRAL.'));
            $observer->setStateSent($order);
        } else {
            $msg = '';
            if ($result !== false && !is_null($result) && isset($result['error'])) {
                $msg = $result['error'];
            }
            $this->_getSession()->addError($this->__('The order was not sent to ASTRAL and has been put on hold.'));
            $observer->setStateNotSent($order, $msg);
        }

        $this->_redirect('adminhtml/sales_order/view', array('order_id'=>$id));

        return false;
    }

}
