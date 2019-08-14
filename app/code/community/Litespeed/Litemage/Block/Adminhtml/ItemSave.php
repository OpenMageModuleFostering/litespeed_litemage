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
 * @copyright  Copyright (c) 2016 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


class Litespeed_Litemage_Block_Adminhtml_ItemSave extends Mage_Adminhtml_Block_Template
{
	const SAVE_PROD_SESSION_KEY = 'litemage_admin_saveprod';
	
	public function getProductSaveOptionUrl()
    {
		if (($product = Mage::registry('product')) == null) {
            $product = Mage::registry('current_product');
        }

		if ($product && $product->getId() > 0) {
            return $this->getUrl('*/litemageCache/productSaveOption', array('id' => $product->getId()));
		}
		return null;
    }
	
	public function getCurrentProdSaveOptions()
	{
		$session = Mage::getSingleton('admin/session');
		if (!$session->getData(self::SAVE_PROD_SESSION_KEY)) {
			$session->setData(self::SAVE_PROD_SESSION_KEY, 'c');
		}
		$cur = $session->getData(self::SAVE_PROD_SESSION_KEY);
		
		$lmhelper = Mage::helper('litemage/data');
		$options = array('c' => $lmhelper->__('For This Product and Related Parent Categories'),
			'p' => $lmhelper->__('For This Product Only'),
			'n' => $lmhelper->__('Do Not Purge'));
		$buf = '';
		foreach($options as $k => $v) {
			$buf .= '<option value="' . $k . '"';
			if ($k == $cur) {
				$buf .= ' selected';
			}
			$buf .= '>' . $v . '</option>';
		}
		return $buf;
	}

    /**
     * Check if block can be displayed
     *
     * @return bool
     */
    public function canShowButton()
    {
        return Mage::helper('litemage/data')->moduleEnabled();
    }

    public function isCacheAvailable()
    {
        return Mage::app()->useCache('layout') && Mage::app()->useCache('config');
    }



}
