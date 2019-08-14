<?php

/*
 * LiteMage plugin for LiteSpeed web server
 * @copyright  Copyright (c) 2016 LiteSpeed Technologies, Inc. (http://www.litespeedtech.com)
 */

class Litespeed_Litemage_Model_Config_Source_EnableWarmUp {
    public function toOptionArray() {
        $helper = Mage::helper('litemage/data');
        return array(
            array( 'value' => 1, 'label' => $helper->__( 'Yes' ) ),
            array( 'value' => 2, 'label' => $helper->__( 'Only for custom defined URL list' ) ),
            array( 'value' => 0, 'label' => $helper->__( 'No' ) ),
        );
    }
}
