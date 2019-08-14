<?php
class Notive_Astral_Helper_Data extends Mage_Core_Helper_Abstract {
    /**
     * Get the orders that were sent to Astral
     *
     * @return Mage_Sales_Model_Resource_Order_Collection
     */
    public function getSentOrders()
    {
        // Get all orders that are processing
        $all_orders = Mage::getModel('sales/order')
                    ->getCollection()
                    ->addFieldToFilter('state', 'processing')
        ;

        $sent_order_ids = array();
        foreach ($all_orders as &$order) {
            $status_histories = Mage::getResourceModel('sales/order_status_history_collection')
                ->setOrderFilter($order)
                ->setOrder('created_at', 'desc')
                ->setOrder('entity_id', 'desc')
                ->addFieldToFilter('status', Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_OK)
            ; // $status_histories

            // The order was sent to Astral
            if (count($status_histories) > 0) {
                $sent_order_ids[] = $order->getId();
            }
        }
        $orders = Mage::getModel('sales/order')
                    ->getCollection()
                    ->addFieldToFilter('entity_id', array('in'=>$sent_order_ids))
        ; // $orders

        return $orders;
    }
}
