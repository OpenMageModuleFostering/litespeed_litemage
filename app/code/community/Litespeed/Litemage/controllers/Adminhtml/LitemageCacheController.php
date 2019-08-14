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



class Litespeed_Litemage_Adminhtml_LitemageCacheController extends Mage_Adminhtml_Controller_Action
{
    public function purgeAllAction()
    {
        Mage::getModel( 'litemage/observer_purge' )->adminPurgeCache(null);
        $this->_redirect('*/cache/index');
    }

    public function purgeTagAction()
    {
        $req = $this->getRequest();
        Mage::getModel( 'litemage/observer_purge' )->adminPurgeCacheBy($req->getParam('tag_types'), $req->getParam('purge_tag'));
        $this->_redirect('*/cache/index');
    }

    public function purgeUrlAction()
    {
        Mage::getModel( 'litemage/observer_purge' )->adminPurgeCacheBy('U', $this->getRequest()->getParam('purge_url'));
        $this->_redirect('*/cache/index');
    }

	public function resetCrawlerListAction()
	{
        $req = $this->getRequest();
        Mage::getModel( 'litemage/observer_cron' )->resetCrawlerList($req->getParam('list'));
        $this->_redirect('*/cache/index');
	}

	public function getCrawlerListAction()
	{
        $req = $this->getRequest();
        $output = Mage::getModel( 'litemage/observer_cron' )->getCrawlerList($req->getParam('list'));
		$this->getResponse()->setBody($output);
	}

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/cache/litemage');
    }
}
