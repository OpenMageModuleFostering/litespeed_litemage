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

	protected $_peer ;

	public function initByPeer( $peer )
	{
		$this->_peer = $peer ;
		parent::setData('litemage_bconf', $peer->getData('litemage_bconf')) ;

		$this->_layout = $peer->_layout ;
		$this->_nameInLayout = $peer->_nameInLayout ;
		$this->_alias = $peer->_alias ;
		if ( $parent = $peer->getParentBlock() ) {
			$parent->setChild($peer->_alias, $this) ;
		}

		if ( $this->_layout->getBlock($peer->_nameInLayout) === $peer ) {
			// some bad plugins with duplicated names may lost this block, only replace if same object
			$this->_layout->setBlock($this->_nameInLayout, $this) ;
		}
	}

	protected function _loadCache()
	{
		$esiHtml = Mage::helper('litemage/esi')->getEsiIncludeHtml($this) ;
		if ( ! $esiHtml ) {
			return false ;
		}

		$bconf = $this->getData('litemage_bconf') ;
		Mage::helper('litemage/data')->debugMesg('Injected ESI block ' . $this->_nameInLayout . ' ' . $esiHtml) ;

		if ( ! $bconf['valueonly'] && Mage::registry('LITEMAGE_SHOWHOLES') ) {
			$tip = 'LiteMage ESI block ' . $this->_nameInLayout ;
			$wrapperBegin = '<div style="position:relative;border:1px dotted red;background-color:rgba(198,245,174,0.3);margin:2px;padding:18px 2px 2px 2px;zoom:1;" title="' . $tip
					. '"><div style="position: absolute; left: 0px; top: 0px; padding: 2px 5px; color: white; font-style: normal; font-variant: normal; font-weight: normal; font-size: 11px; line-height: normal; font-family: Arial; z-index: 998; text-align: left !important; background: rgba(0,100,0,0.5);">' . $tip . '</div>' ;
			$wrapperEnd = '</div>' ;

			return $wrapperBegin . $esiHtml . $wrapperEnd ;
		}
		return $esiHtml ;
	}

	protected function _saveCache( $data )
	{
		return false ;
	}

    public function setData($key, $value=null)
    {
		if (is_scalar($key) && is_scalar($value) 
				&& ($key == 'form_action')) {
			$bconf = $this->getData('litemage_bconf');
			$bconf['extra'][$key] = $value;
			parent::setData('litemage_bconf', $bconf);
		}		
		return parent::setData($key, $value);
    }
	
}
