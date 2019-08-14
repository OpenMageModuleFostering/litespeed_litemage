<?php

/*
 * LiteMage plugin for LiteSpeed web server
 * @copyright  Copyright (c) 2015 LiteSpeed Technologies, Inc. (http://www.litespeedtech.com)
 */

/* This is place holder block to adjust javascript variables. This is a private block, so javascript variable can be adjusted to correct value.
 *
 * The template file is jsvar.phtml

 */

class Litespeed_Litemage_Block_Inject_Jsvar extends Mage_Core_Block_Template
{
    public function isAllowed()
    {
        // only allow this block output if the current url allow ESI injection.
        $helper = Mage::helper('litemage/esi');
        if ($helper->isEsiRequest() || $helper->canInjectEsi()) {
            return true;
        }
        else {
            return false;
        }
    }
    
    // you can add your own function here to handle customized javascript variable
}
