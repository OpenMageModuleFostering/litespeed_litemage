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


class Litespeed_Litemage_Model_Observer_Esi extends Varien_Event_Observer
{

    /**
     * Add the core/messages block rewrite if the flash message fix is enabled
     *
     * The core/messages block is rewritten because it doesn't use a template
     * we can replace with an ESI include tag, just dumps out a block of
     * hard-coded HTML and also frequently skips the toHtml method
     *
     * @param Varien_Object $eventObj
     * @return null
     */
    protected $_esi ;
    protected $_isDebug ;
    protected $_moduleEnabledForUser ;
    protected $_canInjectEsi = -1;
    protected $_helper ;
    protected $_config ;
    protected $_viewVary = array();
    protected $_routeCache;
    protected $_injectedBlocks = array();

    protected function _construct()
    {
        $this->_helper = Mage::helper('litemage/esi') ;
        $this->_config = Mage::helper('litemage/data') ;
        $this->_isDebug = $this->_config->isDebug() ;
        $this->_moduleEnabledForUser = $this->_config->moduleEnabledForUser();
    }

    public function purgeEsiCache( $eventObj )
    {
        if ( $this->_moduleEnabledForUser ) {
            $this->_helper->addPurgeEvent($eventObj->getEvent()->getName()) ;
        }
    }

    //customer_login, customer_logout
    public function purgePrivateCache( $eventObj )
    {
        if ( $this->_moduleEnabledForUser ) {
            $this->_helper->setPurgeHeader(array('*'), $eventObj->getEvent()->getName(), null, true) ;
            $this->_viewVary[] = 'env';
        }
    }

    //customer_login
    //other are captured when pre-dispatch by action name
    public function changeEnvCookie( $eventObj )
    {
        if ( $this->_moduleEnabledForUser ) {
            $this->_viewVary[] = 'env';
        }
    }

//controller_action_predispatch
    public function checkControllerNoCache( $eventObj )
    {
        // no need to check admin, this is frontend event only
        if ( ! $this->_moduleEnabledForUser ) {
            $this->_canInjectEsi = 0;
            return ;
        }
        $req = Mage::app()->getRequest() ;
        $controller = $eventObj->getControllerAction();
        $reason = '';

        if (($lmdebug = $req->getParam('LITEMAGE_DEBUG')) !== null) {
            // either isDebug or IP match
            if ($this->_isDebug || $this->_config->isRestrainedIP() || $this->_config->isAdminIP()) {
                if ($lmdebug == 'SHOWHOLES') {
					// for redirect, maybe already set, need to check, otherwise exception
					if ( ! Mage::registry('LITEMAGE_SHOWHOLES') ) {
						Mage::register('LITEMAGE_SHOWHOLES', 1) ;
					}
					// set to nocache later at beforeResponseSend
                }
                elseif ($lmdebug == 'NOCACHE') {
                    $reason = 'contains var LITEMAGE_DEBUG=NOCACHE';
                }
            }
            else {
                $controller->norouteAction();
                return;
            }
        }

        $curActionName = $controller->getFullActionName() ;
        if ($reason == '') {
            $reason = $this->_cannotCache($req, $curActionName);
        }

        if ($reason != '') {
            $this->_canInjectEsi = 0;
            $reason = ' NO_CACHE=' . $reason;

            // special checks
            $envChanged = array('customer_account_logoutSuccess', 'directory_currency_switch');
            if (in_array($curActionName, $envChanged)) {
                $this->_viewVary[] = 'env';
            }
        }
        else {

            // hardcode for now
            if ( strncmp('catalog_category_', $curActionName, strlen('catalog_category_')) == 0 ) {
                $this->_viewVary[] = 'toolbar' ;
                Mage::Helper('litemage/viewvary')->restoreViewVary($this->_viewVary) ;
            }
            elseif (in_array($curActionName, $this->_config->getNoCacheConf(Litespeed_Litemage_Helper_Data::CFG_FULLCACHE_ROUTE))) {
                $this->_setWholeRouteCache($curActionName, $controller);
            }

            if (($lmctrl = $req->getParam('LITEMAGE_CTRL')) !== null) {
                // either isDebug or IP match
                if ($this->_config->isAdminIP()) {
                    if ($lmctrl == 'PURGE') {
						// for redirect, maybe already set, need to check, otherwise exception
						if (!Mage::registry('LITEMAGE_PURGE')) {
							Mage::register('LITEMAGE_PURGE', 1);
						}
                        // set to nocache later at beforeResponseSend
                    }
                }
                else {
                    $controller->norouteAction();
                    return;
                }
            }

            $this->_helper->setCacheControlFlag(Litespeed_Litemage_Helper_Esi::CHBM_CACHEABLE) ;

            if (Mage::getSingleton('core/cookie')->get('litemage_cron') == Litespeed_Litemage_Model_Observer_Cron::USER_AGENT) {
                $currency = Mage::getSingleton('core/cookie')->get('currency');
                if ($currency != '')
                    Mage::app()->getStore()->setCurrentCurrencyCode($currency);
            }
        }

        if ( $this->_isDebug ) {
            $this->_config->debugMesg('****** PRECHECK route_action [' . $curActionName . '] url=' . $req->getRequestString() . $reason) ;
        }

    }

    // return reason string. if can be cached, return false;
    protected function _cannotCache( $req, $curActionName )
    {
        $requrl = $req->getRequestString() ;

        if ( $req->isPost() ) {
            return 'POST';
        }

        $nocache = $this->_config->getNoCacheConf() ;
        foreach ( $nocache[Litespeed_Litemage_Helper_Data::CFG_NOCACHE_VAR] as $param ) {
            if ( $req->getParam($param) ) {
                return 'contains param ' . $param;
            }
        }

        // check controller level
        $cacheable = false;
        foreach ( $nocache[Litespeed_Litemage_Helper_Data::CFG_CACHE_ROUTE] as $route ) {
            if ( strncmp($route, $curActionName, strlen($route)) == 0 ) {
                $cacheable = true;
                break;
            }
        }
        if ( !$cacheable ) {
            return 'route not cacheable';
        }

        foreach ( $nocache[Litespeed_Litemage_Helper_Data::CFG_NOCACHE_ROUTE] as $route ) {
            if ( strncmp($route, $curActionName, strlen($route)) == 0 ) {
                return 'subroute disabled';
            }
        }

        foreach ( $nocache[Litespeed_Litemage_Helper_Data::CFG_NOCACHE_URL] as $url ) {
            if ( strpos($requrl, $url) !== false ) {
                return 'disabled url ' . $url;
            }
        }

        return ''; // can be cached
    }


    // event core_layout_block_create_after
    public function checkEsiBlock($eventObj)
    {
        if ( ! $this->_moduleEnabledForUser )
            return;

        if ($this->_canInjectEsi === -1) {
            $this->_canInjectEsi = $this->_helper->canInjectEsi();
        }

        if ( ! $this->_canInjectEsi )
            return ;

        // this is to deal with duplicated block names, caused by bad extensions, those blocks are lost in layout->_blocks[name], since name not unique
        $block = $eventObj->getData('block') ;

        $bconf = $this->_config->isEsiBlock($block);
        if ($bconf != null) {
            $blockName = $bconf['bn'];

            if (!isset($this->_injectedBlocks[$blockName])) {
                $bconf['blocks'] = array();
                $this->_injectedBlocks[$blockName] = $bconf;
            }
            $this->_injectedBlocks[$blockName]['blocks'][] = $block;
        }
        // needs to be in its own section, not in else
        if ($block->hasData('litemage_dynamic')) {
            // dynamic injection right now
            $this->_injectDynamicBlock($block);
        }
    }

    protected function _injectDynamicBlock($block)
    {
        $bd = $block->getData('litemage_dynamic');
        if (!isset($bd['type']))
            return;

        if (!isset($bd['tag'])) { // todo: need to validate tag
                /*$conf = $this->_config->getEsiConf('tag', $tag) ;
                if ($conf == null) {
                    if ( $this->_isDebug ) {
                        $this->_config->debugMesg('Missing config for tag '. $tag) ;
                    }
                    return false ;
                } */
            $bd['tag'] = 'welcome';
        }
        $bd['cache-tag'] = 'E.' . $bd['tag'];
        if (!isset($bd['access']) || !in_array($bd['access'], array('private', 'public'))) {
            $bd['access'] = 'private';
        }
        if (!isset($bd['valueonly'])) {
            $bd['valueonly'] = false;
        }

        /*
         *     $litemage_attr = array('litemage_dynamic' =>
                        array('tag' => 'welcome', 'access' => 'private', 'type' => 'customer/form_login', 'template' => 'customer/form/mini.login.phtml'));
         */

        $blockName = $block->getNameInLayout();
        $layout = $block->getLayout();

        //re-init just in case
        $conf = $this->_config->getEsiConf() ;
        $preload = $this->_initInjectionCache($layout) ; // -1: donot use cache, 0 : no cahce, 1: cache loaded, 2: require update


        $esiHtml = '' ;
        if ( $preload && isset($this->_esi['layout']['blocks'][$blockName]) ) {
            $esiHtml = $this->_esi['layout']['blocks'][$blockName] ;
            unset($this->_esi['layout']['blocks'][$blockName]) ;
        }
        else {
            if ( $preload == 1 ) {
                $preload = 2 ; // found a new blockName not preloaded
            }

            $urlOptions = array('b' => $blockName, 't' => $bd['tag']);
            $urlOptions['p'] = str_replace('/', '--', $bd['type']);
            if (isset($bd['template'])) {
                $urlOptions['l'] = str_replace('/', '--', $bd['template']);
            }
            $urlOptions = array_merge($urlOptions, $this->_esi['urlParams']);

            $esiUrl = $this->_helper->getSubReqUrl('litemage/esi/getBlock', $urlOptions) ;
            $esiHtml = '<' . $this->_config->esiTag('include') . ' src="' . $esiUrl . '" combine="sub" cache-tag="' . $bd['cache-tag'] . '" cache-control="no-vary,' . $bd['access'] . '"/>' ;
            if (!$bd['valueonly'] && $this->_isDebug) {
                $esiHtml = '<!--Litemage esi started ' . $blockName . '-->' . $esiHtml . '<!--Litemage esi ended ' . $blockName . '-->' ;
            }
        }

        $this->_helper->setEsiBlockHtml($blockName, $esiHtml) ;

        $esiBlock = new Litespeed_Litemage_Block_Core_Esi() ;
        if ($bd['valueonly']) {
            $esiBlock->setData('valueonly', 1); // needs to set before initbypeer
        }
        $esiBlock->setData('dynamic', 1);
        $esiBlock->initByPeer($block, $esiHtml) ;
        $this->_esi['layout']['preload'] = $preload ;

    }

    //controller_action_layout_generate_blocks_after
    public function prepareInjection( $eventObj )
    {
        if ($this->_canInjectEsi === -1) {
            $this->_canInjectEsi = $this->_helper->canInjectEsi();
        }

        if ( ! $this->_canInjectEsi )
            return ;

        $this->_helper->initFormKey() ;

        if (count($this->_injectedBlocks) == 0)
            return;

        $layout = $eventObj->getData('layout') ;
        $conf = $this->_config->getEsiConf() ;
        $preload = $this->_initInjectionCache($layout) ; // -1: donot use cache, 0 : no cahce, 1: cache loaded, 2: require update
        $esiLayoutUpdate = null ;
        $conflict = array();

        foreach ( $this->_injectedBlocks as $blockName => $bd ) {

            foreach ($bd['blocks'] as $block) {

                $esiHtml = '' ;

                $blockAlias = $block->getBlockAlias();
                $blockIndex = $blockName;
                if ($blockAlias != '' && $blockAlias != $blockName) {
                    $blockIndex .= '!' . $blockAlias;
                }

                // check confliction
                if (!isset($conflict[$blockIndex])) {
                    $conflict[$blockIndex] = array($block);
                }
                else {
                    $conflict[$blockIndex][] = $block;
                    if ( $this->_isDebug )
                        $this->_config->debugMesg('ALERT not unique block name plus alias ' . $blockIndex);
                }

                if ( $preload && isset($this->_esi['layout']['blocks'][$blockIndex]) ) {
                    $esiHtml = $this->_esi['layout']['blocks'][$blockIndex] ;
                    unset($this->_esi['layout']['blocks'][$blockIndex]) ;
                }
                else {
                    if ( $preload == 1 ) {
                        $preload = 2 ; // found a new blockIndex not preloaded
                    }

                    // check if it is a child block of an injected block, bypassed one also need to save esihtml
                    if ( $this->_checkIsInjectedChild($block) ) {
                        $esiHtml = 'BYPASS';
                    }
                    else {

                        if ( $esiLayoutUpdate == null ) {
                            $esiLayoutUpdate = Mage::getSingleton('litemage/layout_update') ;
                            $esiLayoutUpdate->setStoreId($this->_esi['urlParams']['s']) ;
                        }

                        $urlOptions = $this->_getEsiUrlBHOptions($blockName, $block, $layout, $esiLayoutUpdate) ;
                        $urlOptions['t'] = $bd['tag'];
                        if ($blockAlias != '' && $blockName != $blockAlias) {
                            $urlOptions['a'] = $blockAlias;
                        }
                        $urlOptions = array_merge($urlOptions, $this->_esi['urlParams']);

                        $esiUrl = $this->_helper->getSubReqUrl('litemage/esi/getBlock', $urlOptions) ;

                        $esiHtml = '<' . $this->_config->esiTag('include') . ' src="' . $esiUrl . '" combine="sub" cache-tag="' . $bd['cache-tag'] . '" cache-control="no-vary,' . $bd['access'] . '"/>' ;
                        if (!$bd['valueonly'] && $this->_isDebug) {
                            //$esiHtml = '<esi:remove>ESI processing not enabled</esi:remove><!--esi' . $esiInclude . '-->' ; // remove comment, for html minify
                            $esiHtml = '<!--Litemage esi started ' . $blockName . '-->' . $esiHtml . '<!--Litemage esi ended ' . $blockName . '-->' ;
                        }
                    }
                }

                $this->_helper->setEsiBlockHtml($blockIndex, $esiHtml) ;

                if ($esiHtml == 'BYPASS') {
                    // can be a child block of an injected block
                    continue;
                }

                if ( $bd['tag'] == 'messages' ) {
                    $esiBlock = new Litespeed_Litemage_Block_Core_Messages() ;
                }
                else {
                    $esiBlock = new Litespeed_Litemage_Block_Core_Esi() ;
                }
                if ($bd['valueonly']) {
                    $esiBlock->setData('valueonly', 1); // needs to be before initbypeer
                }
                $esiBlock->initByPeer($block, $esiHtml) ;

            }

        }

        $this->_esi['layout']['preload'] = $preload ;
    }

    protected function _checkIsInjectedChild($block)
    {
        $layer = 20;
        $blk = $block;
        while (($blk = $blk->getParentBlock()) && $layer > 0 ) {
            if ($blk->getData('litemageInjected')) {
                return true;
            }
            $layer --;
        }
        return false;
    }

    protected function _setWholeRouteCache($actionName, $controller)
    {
        $app = Mage::app();
        $design = Mage::getDesign() ;
        $tags = array($actionName);
        $tags[] = $app->getStore()->getId() ;
        $tags[] = $design->getPackageName();
        $tags[] = $design->getTheme('layout');
        $cacheId = 'LITEMAGE_ROUTE_' . md5(join('__', $tags));

        $this->_routeCache = array('actionName' => $actionName, 'cacheId' => $cacheId);
        if ($result = $app->loadCache($cacheId)) {
            $this->_routeCache['content'] = unserialize($result);
            $controller->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, 1);
        }
    }

    protected function _initInjectionCache( $layout )
    {
        if (isset($this->_esi['layout']['cacheId'])) {
            // already initialized
            return isset($this->_esi['layout']['preload']) ? $this->_esi['layout']['preload'] : 0;
        }

        $this->_esi = array(
            'layout' => array(),
            'urlParams' => $this->_helper->getEsiSharedParams()) ;
        $rootHandles = $layout->getUpdate()->getHandles() ;
        //should we deal with wrong theme input? THEME_frontend_neoshop_Designer Sarees, Salwar Kameez, Lehengas, Wedding Sherwanis, Mens Kurta Pyjama, Online Shopping
        $tags = array() ;
        foreach ( $rootHandles as $handle ) {
            if ( (strpos($handle, 'CATEGORY_') === false) && ((strpos($handle, 'PRODUCT_') === false) || (strpos($handle, 'PRODUCT_TYPE_') !== false)) ) {
                $this->_esi['layout']['handles'][] = $handle ;
                if ( ($handle != 'customer_logged_out') && ($handle != 'customer_logged_in') )
                    $tags[] = $handle ;
            }
        }
        sort($tags) ;
        $tags[] = 'LITEMAGE_INJECT_ESI' ;
        $tags[] = join('-', $this->_esi['urlParams']); // for env vary
        $cacheId = 'LITEMAGE_BLOCK_' . md5(join('__', $tags)) ;
        $this->_esi['layout']['cacheId'] = $cacheId ;
        $this->_helper->setEsiOn() ;

        $preload = 0 ;

        if ( $result = Mage::app()->loadCache($cacheId) ) {
            $preload = 1 ;
            $this->_esi['layout']['blocks'] = unserialize($result) ;
        }

        if ( $this->_isDebug )
            $this->_config->debugMesg('INJECTING_' . $preload . '  ' . $_SERVER['REQUEST_URI']) ;

        return $preload ;
    }

    protected function _getEsiUrlBHOptions( $blockName, $block, $layout, $esiLayoutUpdate )
    {
        // for blocks and handles
        $hParam = array() ;
        $handles = array() ;
        $packageLayout = $esiLayoutUpdate->getPackageLayout() ;

        $blockNames = $this->_getChildrenNames($block, $layout) ;
        if ( ($alias = $block->getBlockAlias()) && ($alias != $blockName) ) {
            array_unshift($blockNames, $blockName, $alias) ;
        }
        else {
            array_unshift($blockNames, $blockName) ;
        }
        foreach ( $this->_esi['layout']['handles'] as $h ) {
            if ( $h == 'customer_logged_out' || $h == 'customer_logged_in' ) {
                $handles[] = $h ;
            }
            else {

                foreach ( $blockNames as $name ) {
                    $xpath = '//' . $h . '//*[@name="' . $name . '"]' ;
                    if ( $node = $packageLayout->xpath($xpath) ) {
                        $handles[] = $h ;
                        $hParam[] = $h ;
                        break ;
                    }
                }
            }
        }

        $hasCache = $esiLayoutUpdate->loadEsiBlockCache($blockName, $handles) ;
        if ( $hasCache === 0 ) {
            //save layout cache right now, most economic way, blockNames will be filtered
            $blockNames = $esiLayoutUpdate->importLayoutUpdate($blockNames, $handles, $layout) ;
        }
        elseif ( $hasCache == 1 ) {
            // blockNames will be filtered
            $blockNames = $esiLayoutUpdate->getBlockNames() ;
        }

        $urlOptions = array(
            'b' => implode(',', $blockNames) ) ;

        if ( count($hParam) > 0 )
            $urlOptions['h'] = implode(',', $hParam) ;

        return $urlOptions ;
    }

    protected function _getChildrenNames( $block, $layout )
    {
        if ($block == null) {
            return array();
        }

        $children = $block->getSortedChildren() ;
        foreach ( $children as $childName ) {
            if ( $childBlock = $layout->getBlock($childName) ) {
                $alias = $childBlock->getBlockAlias() ;
                if ( $alias != $childName ) {
                    $children[] = $alias ;
                }
                $grandChildren = $this->_getChildrenNames($childBlock, $layout) ;
                if ( count($grandChildren) > 0 ) {
                    $children = array_merge($children, $grandChildren) ;
                }
            }
        }
        return $children ;
    }

    //event: http_response_send_before
    public function beforeResponseSend( $eventObj )
    {
        if ( !$this->_moduleEnabledForUser )
            return;

        $resp = $eventObj->getResponse();

        if (isset($this->_routeCache['content'])) {
            // serve cached whole page
            $resp->setBody($this->_routeCache['content']['body']);
            foreach($this->_routeCache['content']['header'] as $key => $val) {
                $resp->setHeader($key, $val);
            }
			if (isset($this->_routeCache['content']['respcode'])) {
				$resp->setHttpResponseCode($this->_routeCache['content']['respcode']);
			}
            if ($this->_isDebug) {
                // last debug mesg
                $this->_config->debugMesg('###### Served whole route from cache') ;
            }
            return;
        }

        if ( $this->_esi != null && ($this->_esi['layout']['preload'] != 1 || ! empty($this->_esi['layout']['blocks']) || $this->_helper->isEsiBlockAdjusted()) ) {
            $tags = array(Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG, Mage_Core_Model_Layout_Update::LAYOUT_GENERAL_CACHE_TAG);

            if (Mage::app()->useCache('layout')) {
                Mage::app()->saveCache($this->_helper->getEsiBlockHtml(), $this->_esi['layout']['cacheId'], $tags) ;
            }
        }

        if ( count($this->_viewVary) ) {
            // this needs to run before helper's beforeResponseSend
            Mage::Helper('litemage/viewvary')->persistViewVary($this->_viewVary) ;
        }

        $extraHeaders = $this->_helper->beforeResponseSend($resp) ;

        if (isset($this->_routeCache['cacheId']) && Mage::app()->useCache('layout')) {
            $tags = array(Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG);
            $content = array();
            $content['body'] = $resp->getBody();
			$cheaders = array();
			$headers = $resp->getHeaders();
			foreach ($headers as $header) {
				$cheaders[$header['name']] = $header['value'];
			}
			foreach($extraHeaders as $key => $val) {
				$cheaders[$key] = $val;
			}
            $content['header'] = $cheaders;
			$curRespCode = $resp->getHttpResponseCode();
			if ($curRespCode != 200) {
				$content['respcode'] = $curRespCode;
			}

            Mage::app()->saveCache(serialize($content), $this->_routeCache['cacheId'], $tags);
        }

        if ($this->_isDebug) {
            $this->_config->debugMesg('###### end of process, body length ' . strlen($resp->getBody()));
        }

    }

    //catalog_controller_product_view
    public function onCatalogProductView( $eventObj )
    {
        if ( $this->_moduleEnabledForUser && ! $this->_helper->isEsiRequest() ) {
            $productId = $eventObj->getProduct()->getId() ;
            $this->_helper->addCacheEntryTag(Litespeed_Litemage_Helper_Esi::TAG_PREFIX_PRODUCT . $productId) ;

            if ( $this->_config->trackLastViewed() ) {
                $this->_helper->addPurgeEvent($eventObj->getEvent()->getName()) ;
                $this->_helper->trackProduct($productId) ;
            }
        }
    }

    // cms_page_render
    public function onCmsPageRender( $eventObj )
    {
        if ( $this->_moduleEnabledForUser ) {
            $pageId = $eventObj->getPage()->getId() ;
            $this->_helper->addCacheEntryTag(Litespeed_Litemage_Helper_Esi::TAG_PREFIX_CMS . $pageId) ;
        }
    }

    public function initNewVisitor($eventObj)
    {
        if ( $this->_moduleEnabledForUser ) {
            if (Mage::registry('LITEMAGE_NEWVISITOR')) {
                Mage::unregister('LITEMAGE_NEWVISITOR'); // to be safe
            }
            else {
                Mage::register('LITEMAGE_NEWVISITOR', 1);
            }
        }
    }

}
