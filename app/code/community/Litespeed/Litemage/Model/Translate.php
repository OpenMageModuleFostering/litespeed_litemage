<?php

/**
 * LiteMage by LiteSpeed Technologies, Inc.
 *
 * @package    LiteSpeed_LiteMage
 * @copyright  Copyright (c) 2015 LiteSpeed Technologies (http://www.litespeedtech.com)
 */

class Litespeed_Litemage_Model_Translate extends Mage_Core_Model_Translate
{
    /* this class rewrite just try to fix one issue with old class: cacheId does not regenerated, so will still point to different store.
     * This can be removed if you do not have multiple stores.
     */

    public function init($area, $forceReload = false)
    {
        $this->_cacheId = NULL;
        parent::init($area, $forceReload);
    }
}
