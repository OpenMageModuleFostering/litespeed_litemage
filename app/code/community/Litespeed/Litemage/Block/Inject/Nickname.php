<?php

/*
 * LiteMage plugin for LiteSpeed web server
 * @copyright  Copyright (c) 2015 LiteSpeed Technologies, Inc. (http://www.litespeedtech.com)
 */

/* This is sample code to inject a private block which shows customer name only.
    * In litemage config.xml, this block needs to have <valueonly>1</valueonly>. It will output pure value, no added html tags.
    *
    * For example, if nickname is used in an input value field, we need to inject a private block for it.
    * Original template code:
    * <input type="text" name="nickname" id="nickname_field" class="input-text required-entry" value="php echo $this->htmlEscape($data->getNickname()) ?>" required/>
    * We need to add a nickname block in xml under the current block, and add this block class.
    * Update template code to:
    * <input type="text" name="nickname" id="nickname_field" class="input-text required-entry" value="<?php echo $this->getChildHtml('nickname') ?>" required/>
    * For regular esi injected block, we'll output html comment tags around it, however for this case, we can only output pure value.

 */

class Litespeed_Litemage_Block_Inject_Nickname extends Mage_Core_Block_Abstract
{

    /**
     * Get block messsage
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (empty($this->_data['nickname'])) {
            $session = Mage::getSingleton('customer/session');
            if ($session->isLoggedIn()) {
                $this->_data['nickname'] = Mage::helper('core')->escapeHtml($session->getCustomer()->getFirstname());
            }
            else {
                $this->_data['nickname'] = '';
            }
        }

        return $this->_data['nickname'];
    }

}
