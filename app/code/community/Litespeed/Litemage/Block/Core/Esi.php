<?php
/**
 * LiteMage
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) 2015-2016 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


class Litespeed_Litemage_Block_Core_Esi extends Mage_Core_Block_Abstract
{
    public function initByPeer( $peer, $esiHtml )
    {
        $this->setData('esiHtml', $esiHtml) ;
        $peer->setData('litemageInjected', 1);

        $this->_layout = $peer->_layout;
        $this->_nameInLayout = $peer->_nameInLayout ;
        $this->_alias = $peer->_alias;
        $parent = $peer->getParentBlock();
        if ($parent != null) {
            $parent->setChild($peer->_alias, $this) ;
        }
        $this->_layout->setBlock($this->_nameInLayout, $this) ;

        if (!$this->hasData('valueonly') && Mage::registry('LITEMAGE_SHOWHOLES')) {
            $tip = 'LiteMage ESI block ' . $this->_nameInLayout;
            $wrapperBegin = '<div style="position:relative;border:1px dotted red;background-color:rgba(198,245,174,0.3);margin:2px;padding:18px 2px 2px 2px;zoom:1;" title="' . $tip
                    . '"><div style="position: absolute; left: 0px; top: 0px; padding: 2px 5px; color: white; font-style: normal; font-variant: normal; font-weight: normal; font-size: 11px; line-height: normal; font-family: Arial; z-index: 998; text-align: left !important; background: rgba(0,100,0,0.5);">' . $tip . '</div>';
            $this->setData('lmwrapperBegin', $wrapperBegin);
            $this->setData('lmwrapperEnd', '</div>');
        }

    }

    protected function _loadCache()
    {
        if ($this->hasData('esiHtml')) {
            $esiHtml = $this->getData('esiHtml');
            Mage::helper('litemage/data')->debugMesg('Injected ESI block ' . $this->_nameInLayout . ' ' . $esiHtml) ;

            if (!$this->hasData('valueonly') && Mage::registry('LITEMAGE_SHOWHOLES'))
                return $this->getData('lmwrapperBegin') . $esiHtml . $this->getData('lmwrapperEnd') ;
            else {
                return $esiHtml;
            }
        }
        else {
            return false;
        }
    }

    protected function _saveCache($data)
    {
        return false;
    }

}