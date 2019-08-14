<?php
class Notive_Astral_Model_Status extends Varien_Object {
    protected static $IGNORE_STATUSES = array(
        Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_ERROR,
        Notive_Astral_Model_Observers_Order::ASTRAL_ORDER_STATUS_CODE_OK
    );

    public function toOptionHash()
    {
        switch ($this->getPath()) {
            case 'Notive_Astral/order/status_send':
                return $this->getStatuses();
        }
        return array('' => 'BUG: ' . $this->getPath() . ' NOT IMPLEMENTED'); // default
    }

    protected function getStatuses()
    {
        $ret = array();
        if (version_compare(Mage::getVersion(), '1.5', '<') === true) {
            // Magento < 1.5
            $cfg = Mage::app()->getConfig();
            foreach ($cfg->getNode('global/sales/order/statuses')->asArray() as $status => $l) {
                $this->add_status($ret, $status, $l['label']);
            }
        } else {
            $col = Mage::getModel('sales/order_status')->getCollection();
            foreach ($col as $st) {
                $this->add_status($ret, $st['status'], $st['label']);
            }
        }
        natsort($ret);
        return $ret;
    }

    protected function add_status(&$arr, $st, $label)
    {
        if (!in_array($st, self::$IGNORE_STATUSES)) {
            $arr[$st] = $label;
        }
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
