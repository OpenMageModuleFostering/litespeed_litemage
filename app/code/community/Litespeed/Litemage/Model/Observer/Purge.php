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


class Litespeed_Litemage_Model_Observer_Purge extends Varien_Event_Observer
{

    protected $_curProductId ;

    /**
     * Clear LiteSpeed Cache cache by admin actions
     *
     * Events:
     *     controller_action_postdispatch_adminhtml_cache_flushAll
     *     controller_action_postdispatch_adminhtml_cache_flushSystem
     *
     * @param  Varien_Object $eventObj
     * @return null
     */
    public function adminPurgeCache( $eventObj )
    {
        $config = Mage::helper('litemage/data') ;
        if ( $config->moduleEnabled() ) {
            $this->_purgeAllByAdmin($this->_getAdminSession(), $config) ;
        }
    }

    public function adminPurgeCurrency($eventObj)
    {
        $config = Mage::helper('litemage/data') ;
        if ( $config->moduleEnabled() ) {
            Mage::helper('litemage/esi')->setPurgeHeader(array( 'CURR' ), 'adminPurgeCurrency') ;
            $this->_getAdminSession()->addSuccess($config->__('Notified LiteSpeed web server to purge all cached pages which have currency rates.')) ;
        }
    }

    public function adminPurgeCacheBy($type, $ids )
    {
        $config = Mage::helper('litemage/data') ;
        if ( $config->moduleEnabled() ) {
            // validate
            $adminSession = $this->_getAdminSession() ;
            if ($ids == '') {
                $adminSession->addError($config->__('Missing input value.'));
            }
            elseif (in_array($type, array('P','C','G'))) {
                $tags = preg_split("/[\s,]+/", $ids, null, PREG_SPLIT_NO_EMPTY);
                if (count($tags) == 0) {
                    $adminSession->addError($config->__('Missing ID values.'));
                }
                else {
                    $cacheTags = array();
                    foreach ($tags as $tag) {
                        if (strval(intval($tag)) != $tag) {
                            $adminSession->addError($config->__('Invalid ID values ' . $tag));
                            break;
                        }
                        $cacheTags[] = $type . '.' . $tag;
                    }
                    if (count($cacheTags)) {
                        $this->_purgeTagByAdmin($cacheTags, 'by tag ' . implode(',', $cacheTags) . ' (from cache management)');
                    }
                }
            }
            elseif ($type == 'U') {
                if ($ids[0] != '/') {
                    $adminSession->addError($config->__('Invalid URL value, requires relative URL starting with /'));
                }
                else {
                    $this->_purgeUrlByAdmin($ids);
                }
            }
            else {
                $adminSession->addError($config->__('Invalid input type'));
            }
        }
    }

    public function adminConfigChangedSection( $eventObj )
    {
        $config = Mage::helper('litemage/data') ;
        $moduleEnabled = $config->getConf(Litespeed_Litemage_Helper_Data::CFG_ENABLED) ;
        $serverEnabled = isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE'] ;
        $adminSession = $this->_getAdminSession() ;
        if ( ! $serverEnabled ) {
            $adminSession->addError($config->__('Your installation of LiteSpeed Web Server does not have LiteMage Cache enabled.')) ;
        }
        if ( $moduleEnabled ) {
            if ( $serverEnabled ) {
                $adminSession->addNotice($config->__('To make your changes take effect immediately, purge LiteSpeed Cache (System -> Cache Management).')) ;
            }
        }
        else {
            $this->_purgeAllByAdmin($adminSession, $config) ;
        }
    }

    public function adminConfigEditSection( $eventObj )
    {
        $sectionCode = Mage::app()->getRequest()->getParam('section') ;
        if ( $sectionCode == 'litemage' ) {
            $serverEnabled = isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE'] ;
            if ( ! $serverEnabled ) {
                $config = Mage::helper('litemage/data') ;
                $this->_getAdminSession()->addError($config->__('Your installation of LiteSpeed Web Server does not have LiteMage Cache enabled.')) ;
            }
        }
    }

    protected function _purgeAllByAdmin( $adminSession, $config )
    {
        $tags = array( Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG ) ;
        Mage::app()->cleanCache($tags) ;
        $adminSession->addSuccess($config->__('Purged all LiteMage ESI block cache.')) ;

        Mage::helper('litemage/esi')->setPurgeHeader(array( '*' ), 'purgeAllByAdmin') ;
        $adminSession->addSuccess($config->__('Notified LiteSpeed web server to purge all cached items.')) ;
    }

    protected function _purgeTagByAdmin( $tags, $message = '', $reason ='' )
    {
        Mage::helper('litemage/esi')->setPurgeHeader($tags, 'purgeTagByAdmin ' . $reason) ;
        if ( $message ) {
            $this->_getAdminSession()->addSuccess(Mage::helper('litemage/data')->__('Notified LiteSpeed web server to purge ' . $message)) ;
        }
    }

    protected function _purgeUrlByAdmin($url)
    {
        Mage::helper('litemage/esi')->setPurgeURLHeader($url, 'purgeUrlByAdmin') ;
        $this->_getAdminSession()->addSuccess(Mage::helper('litemage/data')->__('Notified LiteSpeed web server to purge URL ' . $url)) ;
    }

    protected function _getAdminSession()
    {
        return Mage::getSingleton('adminhtml/session') ;
    }

    /**
     * Event: admin catalog_category_save_commit_after
     */
    public function adminPurgeCatalogCategory( $eventObj )
    {
        try {
            if ( Mage::helper('litemage/data')->moduleEnabled() ) {
                $category = $eventObj->getEvent()->getCategory() ;
                if ( $category != null ) {
                    $tag = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_CATEGORY . $category->getId() ;
                    $this->_purgeTagByAdmin(array( $tag ), $category->getName()) ;
                }
            }
        } catch ( Exception $e ) {
            Mage::helper('litemage/data')->debugMesg('Error on adminPurgeCatalogCategory: ' . $e->getMessage()) ;
        }

    }

    //admin catalog_product_save_commit_after
    public function adminPurgeCatalogProduct( $eventObj )
	{
		try {
			if ( Mage::helper('litemage/data')->moduleEnabled() ) {
				$product = $eventObj->getEvent()->getProduct() ;
				if ( ($product != null) &&
						( $tags = $this->_getPurgeProductTags($product, true)) ) {
					$this->_purgeTagByAdmin($tags, $product->getName(), 'adminPurgeCatalogProduct - catalog_product_save_commit_after') ;
				}
			}
		} catch ( Exception $e ) {
			Mage::helper('litemage/data')->debugMesg('Error on adminPurgeCatalogProduct: ' . $e->getMessage()) ;
		}
	}

	// global cataloginventory_stock_item_save_after
    public function purgeCatalogProductByStock( $eventObj )
    {
        try {
			$helper = Mage::helper('litemage/data');
            if ( $helper->moduleEnabled() ) {
                $item = $eventObj->getEvent()->getItem() ;
				$option = $helper->getConf(Litespeed_Litemage_Helper_Data::CFG_FLUSH_PRODCAT) ;

				$changedauto = $item->getStockStatusChangedAutomatically() ;
				$origqty = $item->getOriginalInventoryQty();
				$qty = $item->getQty();
				$qtycorrection = $item->getQtyCorrection();

				$qtyChanged = $qtycorrection > 0;
				$stockStatusChanged = $changedauto || ($origqty <= 0 && $qty > 0 && $qtycorrection > 0);

				$purgeProd = false;
				$purgeCategory = false;

				switch ($option) {
					case 1:	// Only flush product and categories when stock status change
						if ($stockStatusChanged) {
							$purgeProd = true;
							$purgeCategory = true;
						}
						break;
					case 2:	// Flush product when stock status change, do not flush categories
						if ($stockStatusChanged) {
							$purgeProd = true;
						}
						break;
					case 3:	// Always flush product and categories when qty/stock status change
						if ($qtyChanged || $stockStatusChanged) {
							$purgeProd = true;
							$purgeCategory = true;
						}
						break;
					case 0:	// Flush product when qty/stock status change, flush categories only when stock status change
					default:
						if ($qtyChanged || $stockStatusChanged) {
							$purgeProd = true;
						}
						if ($stockStatusChanged) {
							$purgeCategory = true;
						}

				}

				$reason = "in cataloginventory_stock_item_save_after  option = $option purgeprod = $purgeProd purgeCategory = $purgeCategory statusChangedAuto = $changedauto , origQty = $origqty , qty = $qty, qtyCorrection = $qtycorrection";
				if ($purgeProd) {
					$product = Mage::getModel('catalog/product')->load($item->getProductId());
					if ($tags = $this->_getPurgeProductTags($product, $purgeCategory)) {
						Mage::helper('litemage/esi')->setPurgeHeader($tags, $reason) ;
					}
				}
				Mage::helper('litemage/data')->debugMesg($reason);
            }
        } catch ( Exception $e ) {
            Mage::helper('litemage/data')->debugMesg('Error on purgeCatalogProductByStock: ' . $e->getMessage()) ;
        }
    }

    // on admin cms_page_save_commit_after
    public function adminPurgeCmsPage( $eventObj )
    {
        try {
            if ( Mage::helper('litemage/data')->moduleEnabled() ) {
                $page = $eventObj->getEvent()->getObject() ;
                if ( $page != null ) {
                    $id = $page->getId() ;
                    if ( $id != null ) {
                        $tag = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_CMS . $id ;
                        $this->_purgeTagByAdmin(array( $tag ), 'Page ' . $page->getTitle()) ;
                    }
                }
            }
        } catch ( Exception $e ) {
            Mage::helper('litemage/data')->debugMesg('Error on adminPurgeCmsPage: ' . $e->getMessage()) ;
        }
    }

    protected function _getPurgeProductTags( $product, $purgeCategory )
    {
        $productId = $product->getId() ;
        if ( $this->_curProductId == $productId )
            return null; // already purged
        $this->_curProductId = $productId ;

        $cids = $product->getCategoryIds() ;

        if ( $cids == null )
            $cids = array() ;

        $tags = array() ;
        $tags[] = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_PRODUCT . $productId ;

        $pids = array_unique(array_merge(Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($productId), Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($productId))) ;

        foreach ( $pids as $pid ) {
            $tags[] = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_PRODUCT . $pid ;
            $pp = Mage::getModel('catalog/product')->load($pid) ;
            if ( $pp->isVisibleInCatalog() ) {
                if ( ($pcids = $pp->getCategoryIds()) != null ) {
                    $cids = array_merge($cids, $pcids) ;
                }
            }
        }

		if ($purgeCategory) {
			$cids = array_unique($cids) ;
			$pcids = array() ;

			foreach ( $cids as $cid ) {
				$tags[] = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_CATEGORY . $cid ;
				$cat = Mage::getModel('catalog/category')->load($cid) ;
				$pcids = array_merge($pcids, $cat->getParentIds()) ;
			}

			$pcids = array_diff(array_unique($pcids), $cids) ;
			foreach ( $pcids as $cid ) {
				$cat = Mage::getModel('catalog/category')->load($cid) ;
				$dispmode = $cat->getDisplayMode() ;
				if ( $dispmode == Mage_Catalog_Model_Category::DM_PRODUCT || $dispmode == Mage_Catalog_Model_Category::DM_MIXED )
					$tags[] = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_CATEGORY . $cid ;
			}
		}

        return $tags;
    }


}
