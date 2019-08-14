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


class Litespeed_Litemage_Block_Adminhtml_Cache_Management extends Mage_Adminhtml_Block_Template
{
    /**
     * Get clean cache url
     *
     * @return string
     */
    public function getPurgeUrl($type)
    {
		if ($type == 'Refresh') {
			return $this->getUrl('*/cache/index');
		}
		else {
	        $types = array('All', 'Tag', 'Url');

			if (in_array($type, $types)) {
				return $this->getUrl('*/litemageCache/purge' . $type);
			}
			else {
				return $this->getUrl('*/litemageCache/purgeAll');
			}
		}
    }

	public function getCrawlerStatus()
	{
		$status = Mage::getModel( 'litemage/observer_cron' )->getCrawlerStatus();
		$status['url_reset'] = $this->getUrl('*/litemageCache/resetCrawlerList');
		$status['url_details'] = $this->getUrl('*/litemageCache/getCrawlerList');
		return $status;
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
