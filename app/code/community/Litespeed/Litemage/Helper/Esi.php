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


class Litespeed_Litemage_Helper_Esi
{

    const LSHEADER_PURGE = 'X-LiteSpeed-Purge' ;
    const LSHEADER_CACHE_CONTROL = 'X-LiteSpeed-Cache-Control' ;
    const LSHEADER_CACHE_TAG = 'X-LiteSpeed-Tag' ;
    const LSHEADER_CACHE_VARY = 'X-LiteSpeed-Vary';
    const TAG_PREFIX_CMS = 'G.' ;
    const TAG_PREFIX_CATEGORY = 'C.' ;
    const TAG_PREFIX_PRODUCT = 'P.' ;
    const TAG_PREFIX_ESIBLOCK = 'E.' ;

	//BITMASK for Cache Header Flag
    const CHBM_CACHEABLE = 1 ;
    const CHBM_PRIVATE = 2 ;
    const CHBM_ONLY_CACHE_EMPTY = 4 ;
    const CHBM_ESI_ON = 16 ;
    const CHBM_ESI_REQ = 32 ;
    const CHBM_FORMKEY_REPLACED = 64 ;
	const CHBM_NOT_CACHEABLE = 128 ; // for redirect

    const FORMKEY_REAL = '_litemage_realformkey' ;
    const FORMKEY_REPLACE = 'litemagefmkeylmg' ; //do not use special characters, maybe changed by urlencode
    const FORMKEY_NAME = '_form_key' ;

    // config items
    protected $_viewedTracker ;
    protected $_cacheVars = array( 'tag' => array(), 'flag' => 0, 'ttl' => -1, 'env' => array(), 'cookie' => array(), 'baseUrl' => '', 'baseUrlESI' => '' ) ;
	protected $_esiLayoutCache ;
    protected $_esiPurgeEvents = array() ;
	protected $_defaultEnvVaryCookie = '_lscache_vary'; // system default
    protected $_isDebug;
    protected $_config;


    public function __construct()
    {
        $this->_config = Mage::helper('litemage/data');
        $this->_isDebug = $this->_config->isDebug();
		if (isset($_SERVER['LSCACHE_VARY_COOKIE'])) {
			$this->_defaultEnvVaryCookie =  $_SERVER['LSCACHE_VARY_COOKIE'];
		}
    }

    public function isDebug()
    {
        return $this->_isDebug;
    }

    public function setCacheControlFlag( $flag, $ttl = -1, $tag = '' )
    {
        $this->_cacheVars['flag'] = $flag ;
        if ( $tag )
            $this->_cacheVars['tag'][] = $tag ;
        if ( $ttl != -1 )
            $this->_cacheVars['ttl'] = $ttl ;
        // init esiconf
        $this->_config->getEsiConf('tag');
    }

	public function notCacheable()
	{
		return ( ($this->_cacheVars['flag'] & self::CHBM_NOT_CACHEABLE) == self::CHBM_NOT_CACHEABLE );
	}

    protected function _setEsiOn()
    {
        if ( ($this->_cacheVars['flag'] & self::CHBM_ESI_ON) == 0 ) {
            $this->_cacheVars['flag'] |= self::CHBM_ESI_ON ;
        }
    }

    public function getBaseUrl()
    {
        if ($this->_cacheVars['baseUrl'] == '') {
            $base = Mage::getBaseUrl(); // cannot use request->getBaseUrl, as store ID maybe in url
            $this->_cacheVars['baseUrl'] = $base;
			$esibase = $base;
			if ((stripos($base, 'http') !== false) && ($pos = strpos($base, '://'))) {
				// remove domain, some configuration will use multiple domain/vhosts map  to different one.
				$pos2 = strpos($base, '/', $pos+ 4);
				$esibase = ($pos2 === false) ? '/' : substr($base, $pos2);
			}
			$this->_cacheVars['baseUrlESI'] = $esibase;
        }

        return $this->_cacheVars['baseUrl'];
    }

	public function getEsiBaseUrl()
	{
		if ($this->_cacheVars['baseUrlESI'] == '') {
			$this->getBaseUrl();
		}
		return $this->_cacheVars['baseUrlESI'];
	}

    protected function _getSubReqUrl($route, $params)
    {
        $baseurl = $this->getEsiBaseUrl();
        $url = $baseurl . $route . '/';
		$eparams = $this->_config->encodeEsiUrlParams($params);
        foreach ( $eparams as $key => $value ) {
            $url .= $key . '/' . $value . '/';
        }
        return $url;
    }


    public function canInjectEsi()
    {
        $flag = $this->_cacheVars['flag'] ;
        return ((($flag & self::CHBM_CACHEABLE) != 0) && (($flag & self::CHBM_ESI_REQ) == 0)) ;
    }

    public function isEsiRequest()
    {
        $flag = $this->_cacheVars['flag'] ;
        return (($flag & self::CHBM_ESI_REQ) != 0) ;
    }

    public function initFormKey()
    {
        $session = Mage::getSingleton('core/session') ;
        if ( method_exists($session, 'getFormKey') ) {
            $cur_formkey = $session->getFormKey() ;
            if ( $cur_formkey != self::FORMKEY_REPLACE ) {
                $session->setData(self::FORMKEY_REAL, $cur_formkey) ;
                $session->setData(self::FORMKEY_NAME, self::FORMKEY_REPLACE) ;
            }
            $this->_cacheVars['flag'] |= self::CHBM_FORMKEY_REPLACED ;
        }
    }

    protected function _restoreFormKey()
    {
        if ( ($this->_cacheVars['flag'] & self::CHBM_FORMKEY_REPLACED) != 0 ) {
            $session = Mage::getSingleton('core/session') ;
            if ( ($realFormKey = $session->getData(self::FORMKEY_REAL)) != NULL ) {
                $session->unsetData(self::FORMKEY_REAL) ;
                $session->setData(self::FORMKEY_NAME, $realFormKey) ;
            }
        }
    }

    public function addPrivatePurgeEvent( $eventName )
    {
        // always set purge header, due to ajax call, before_reponse_send will not be triggered, also it may die out in the middle, so must set raw header using php directly
		if (isset($this->_esiPurgeEvents[$eventName]))
			return;

		$this->_esiPurgeEvents[$eventName] = $eventName ;
		if ( $cachePurgeTags = $this->_getEsiPurgeTags() ) {
			$purgeHeader = $this->_getPurgeHeaderValue($cachePurgeTags, true);
			header(self::LSHEADER_PURGE . ': ' . $purgeHeader, true);
			if ($this->_isDebug)
				$this->_config->debugMesg("SetPurgeHeader: " . $purgeHeader . '  (triggered by event ' . $eventName . ')') ;
		}
    }

    protected function _getEsiPurgeTags()
    {
        if ( count($this->_esiPurgeEvents) == 0 )
            return NULL ;

        $events = $this->_config->getEsiConf('event');
        $tags = array() ;
        foreach ( $this->_esiPurgeEvents as $e ) {
            if (isset($events[$e])) {
                foreach($events[$e] as $t) {
                    if (!in_array($t, $tags)) {
                        $tags[] = $t;
                    }
                }
            }
        }

        if ($this->_isDebug) {
            $this->_config->debugMesg('Purge events ' . implode(', ', $this->_esiPurgeEvents) . ' tags: ' . implode(', ', $tags));
        }

        return (count($tags) ? $tags : NULL) ;
    }

    protected function _getPurgeHeaderValue($tags, $isPrivate)
    {
        $purgeHeader = $isPrivate ? 'private,' : '' ;
        $t = '';
        foreach ($tags as $tag) {
            $t .= ( $tag == '*' ) ? '*' : 'tag=' . $tag . ',' ;
        }
        $purgeHeader .= trim($t, ',');
        return $purgeHeader;
    }

    public function setPurgeHeader( $tags, $by, $response = NULL, $isPrivate = false )
    {
        $purgeHeader = $this->_getPurgeHeaderValue($tags, $isPrivate);

        if ( $response == NULL ) {
            $response = Mage::app()->getResponse() ;
        }

        if ($this->_isDebug) {
            $this->_config->debugMesg("SetPurgeHeader: " . $purgeHeader . '  (triggered by ' . $by . ')') ;
		}
        $response->setHeader(self::LSHEADER_PURGE, $purgeHeader, true) ;
    }

    public function setPurgeURLHeader( $url, $by )
    {
        $response = Mage::app()->getResponse() ;

        if ($this->_isDebug) {
            $this->_config->debugMesg("SetPurgeHeader: " . $url . '  (triggered by ' . $by . ')') ;
		}
        $response->setHeader(self::LSHEADER_PURGE, $url, true) ;
    }

    /*public function refreshPrivateSessionOnce($by)
    {
        header(self::LSHEADER_PURGE . ': once, private, *', false);
    }*/

    public function addCacheEntryTag( $tag )
    {
        $this->_cacheVars['tag'][] = $tag ;
    }

    public function trackProduct( $productId )
    {
        if ( $this->_viewedTracker == NULL )
            $this->_viewedTracker = array( 'product' => $productId ) ;
        else
            $this->_viewedTracker['product'] = $productId ;
    }

	public function getEsiIncludeHtml($block)
	{
		if (!isset($this->_esiLayoutCache)) {
			// load from cache
			$this->_initEsiLayoutCache($block);
		}

		$bi = $this->_getEsiBlockIndex($block);
		if (isset($this->_esiLayoutCache['blocks'][$bi]['e'])) {
			$html = $this->_esiLayoutCache['blocks'][$bi]['e'];
		}
		else {
			$html = $this->_loadEsiIncludeHtml($block, $bi);
		}
		return $html;
	}


    protected function _initEsiLayoutCache( $block )
    {
		/*
		 * _esiLayoutCache => array(),
		 *			'cacheId'
		 *			'esiUpdate'
		 *			'adjusted'
		 *			'blocks[blockindex][e] : esiinclude html string'
		 *								[h] : actual handle used
		 *								[b] : block name
		 */

		$update = $block->getLayout()->getUpdate();
		if ( ! $update instanceof Litespeed_Litemage_Model_Layout_Update) {
			Mage::throwException('LiteMage module requires Layout_Update class overwrite');
		}

		$tags = $update->getUsedHandles(); // used handles include customer_logged_in/out
        $tags[] = 'LITEMAGE_INJECT_ESI' ;
        $tags[] = join('-', $this->getEsiSharedParams()); // for env vary
        $cacheId = 'LITEMAGE_BLOCK_' . md5(join('__', $tags)) ;
        $this->_esiLayoutCache['cacheId'] = $cacheId ;
        $this->_setEsiOn() ;

        $preload = 0 ;
        $this->_esiLayoutCache = array('adjusted' => false,
			'layoutUpdate' => $update,
			'cacheId' => $cacheId,
			'blocks' => array());

        if ( $result = Mage::app()->loadCache($cacheId) ) {
            $preload = 1 ;
            $this->_esiLayoutCache['blocks'] = unserialize($result) ;
        }

        if ( $this->_isDebug ) {
			// 0 not in cache, 1 from cache
            $this->_config->debugMesg('INJECTING_' . $preload . '  ' . $_SERVER['REQUEST_URI']) ;
		}
    }

	protected function _loadEsiIncludeHtml($block, $bi)
	{
		$bconf = $block->getData('litemage_bconf');
		$blockName = $bconf['bn'];
		$blockHandles = null;
		$urlOptions = array('t' => $bconf['tag'], 'bi' => $bi);

		if (isset($bconf['is_dynamic'])) {
			$urlOptions['pc'] = $bconf['pc'];
			if (!empty($block->getTemplate())) {
				$urlOptions['pt'] = $block->getTemplate();
			}
		}
		else {
			$blockHandles = $this->_getBlockHandles($blockName, $block);
		}

		if (!empty($blockHandles)) {
			$urlOptions['h'] = implode(',', $blockHandles);
		}
		$urlOptions = array_merge($urlOptions, $this->getEsiSharedParams());

		$esiUrl = $this->_getSubReqUrl('litemage/esi/getBlock', $urlOptions) ;
		// use single quote to work with pagespeed module
		$esiHtml = '<' . $this->_config->esiTag('include') . " src='" . $esiUrl . "' combine='sub' cache-tag='" . $bconf['cache-tag'] . "' cache-control='no-vary," . $bconf['access'] . "'/>" ;
		if ($this->_isDebug && !$bconf['valueonly']) {
			$esiHtml = '<!--Litemage esi started ' . $blockName . '-->' . $esiHtml . '<!--Litemage esi ended ' . $blockName . '-->' ;
		}

		$this->_esiLayoutCache['blocks'][$bi] = array(
			'h' => $blockHandles,	//actual handle used
			'e' => $esiHtml);		// esiinclude html string
		$this->_esiLayoutCache['adjusted'] = true;

		return $esiHtml;
	}

	protected function _getEsiBlockIndex($block)
	{
		// check if it's in layout, maybe a lost child introduced by bad layout
		$index = $this->_getEsiBlockIndexElement($block, $block->getLayout());
		return implode(';', $index);
	}

	protected function _getEsiBlockIndexElement($block, $layout)
	{
		// check if it's in layout, maybe a lost child introduced by bad layout
		$blockIndex = array();
		$blockName = $block->getNameInLayout();

		if ($layout->getBlock($blockName) != $block) {
			$alias = $block->getBlockAlias();
			if ($alias != '' && $alias != $blockName) {
					$blockName .= ',' . $alias;
			}

			if ($parent = $block->getParentBlock()) {
				$blockIndex = $this->_getEsiBlockIndexElement($parent, $layout);
			}
		}
		$blockIndex[] = $blockName;
		return $blockIndex;
	}

    protected function _getBlockHandles($blockName, $block)
    {
        $blockNames = $this->_getChildrenNames($block, $block->getLayout()) ;
        if ( ($alias = $block->getBlockAlias()) && ($alias != $blockName) ) {
            array_unshift($blockNames, $blockName, $alias) ;
        }
        else {
            array_unshift($blockNames, $blockName) ;
        }

        $handles = $this->_esiLayoutCache['layoutUpdate']->getBlockHandles($blockNames);

		return $handles;
    }

    protected function _getChildrenNames( $block, $layout )
    {
        if ($block == NULL) {
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

	protected function _refreshEsiBlockCache()
	{
		if ($this->_esiLayoutCache['adjusted'] && Mage::app()->useCache('layout')) {
			$tags = array(Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG, Mage_Core_Model_Layout_Update::LAYOUT_GENERAL_CACHE_TAG);
            Mage::app()->saveCache(serialize($this->_esiLayoutCache['blocks']), $this->_esiLayoutCache['cacheId'], $tags) ;
			$this->_esiLayoutCache['adjusted'] = false;
        }
	}

    public function beforeResponseSend( $response )
    {
		$this->_refreshEsiBlockCache();

        $extraHeaders = array();
        $envChanged = $this->setEnvCookie();

        $cacheControlHeader = '' ;
        $flag = $this->_cacheVars['flag'] ;
        $cacheable = true;

        if ( (($flag & self::CHBM_CACHEABLE) == 0)
                || $envChanged
                || Mage::registry('LITEMAGE_SHOWHOLES')
                || Mage::registry('LITEMAGE_PURGE')
                || !in_array($response->getHttpResponseCode(), array( 200, 301, 404 ))) {
            $cacheable = false;
        }

        if ( $cacheable ) {
            if ( ($flag & self::CHBM_PRIVATE) != 0 )
                $cacheControlHeader = 'private,max-age=' . (($this->_cacheVars['ttl'] > 0) ? $this->_cacheVars['ttl'] : $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_PRIVATETTL)) ;
            else
                $cacheControlHeader = 'public,max-age=' . (($this->_cacheVars['ttl'] > 0) ? $this->_cacheVars['ttl'] : $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_PUBLICTTL)) ;

            $notEsiReq = (($flag & self::CHBM_ESI_REQ) == 0);
            if ($notEsiReq) {
                // for cacheable, non-esi page
                if ($vary_on = $this->_getCacheVaryOn()) {
                    $extraHeaders[self::LSHEADER_CACHE_VARY] = $vary_on;
                }
            }
            else {
                $cacheControlHeader .= ',no-vary';
                if ( ($this->_cacheVars['flag'] & self::CHBM_ONLY_CACHE_EMPTY) != 0)
                    $cacheControlHeader .= ',set-blank';
            }

            if ( ($cacheTagHeader = $this->_getCacheTagHeader($notEsiReq)) ) {
                $extraHeaders[self::LSHEADER_CACHE_TAG] = $cacheTagHeader;
            }
        }

        if ((($flag & self::CHBM_ESI_REQ) == 0)    // for non-esi request
                && ((($flag & self::CHBM_ESI_ON) != 0)  // esi on
                        || (($flag & self::CHBM_FORMKEY_REPLACED) != 0) // formkey replaced
                        || ($this->_viewedTracker != NULL))) { // has view tracker
            $this->_updateResponseBody($response) ;
        }

        if ( ($flag & self::CHBM_ESI_ON) != 0 ) {
            if ($cacheControlHeader != '')
                $cacheControlHeader .= ',';
            $cacheControlHeader .= 'esi=on' ;
        }

        if ($cacheControlHeader != '') { // if only no-cache, no need to set header
            $extraHeaders[self::LSHEADER_CACHE_CONTROL] = $cacheControlHeader;
        }

        // due to ajax, move purge header when event happens, so already purged
        if (Mage::registry('LITEMAGE_PURGE')) {
            $extraHeaders[self::LSHEADER_PURGE] = $this->_getPurgeCacheTags();
        }

        $this->_restoreFormKey() ;

        foreach($extraHeaders as $key => $val) {
            $response->setHeader($key, $val);
            if ($this->_isDebug) {
                $this->_config->debugMesg("Header $key: $val");
            }
        }

        return $extraHeaders;
    }

    protected function _updateResponseBody( $response )
    {
        // only for non-esi request and injected
        $responseBody = $response->getBody() ;
        $updated = false ;
        $combined = '' ;
        $tracker = '' ;
        $sharedParams = $this->getEsiSharedParams();
        $esiIncludeTag = $this->_config->esiTag('include');

        if ( (($this->_cacheVars['flag'] & self::CHBM_FORMKEY_REPLACED) != 0) && strpos($responseBody, self::FORMKEY_REPLACE) ) {
			// use single quote for pagespeed module
            $replace = '<' . $esiIncludeTag . " src='" . $this->getEsiBaseUrl() . "litemage/esi/getFormKey' as-var='1' combine='sub' cache-control='no-vary,private' cache-tag='E.formkey'/>" ;
            $responseBody = str_replace(self::FORMKEY_REPLACE, $replace, $responseBody) ;
			if ($this->_isDebug) {
				$this->_config->debugMesg('FormKey replaced as ' . $replace);
			}
            $updated = true ;
        }

        if ( $this->_viewedTracker != NULL ) {
            $logOptions = $this->_viewedTracker;
            $logOptions['s'] = $sharedParams['s'];
            // no need to use comment, will be removed by minify extensions
            // if response coming from backend, no need to send separate log request
            $tracker = '<' . $esiIncludeTag . ' src="' . $this->_getSubReqUrl('litemage/esi/log', $logOptions)
                    . '" test="$(RESP_HEADER{X-LITESPEED-CACHE})!=\'\'" cache-control="no-cache" combine="sub"/>' ;
			if ($this->_isDebug) {
				$this->_config->debugMesg('Track recently viewed  as ' . $tracker);
			}
            $updated = true ;
        }

        if ( $updated )
            $this->_setEsiOn() ;

        if ( ($this->_cacheVars['flag'] & self::CHBM_ESI_ON) != 0 ) {
            // no need to use comment, will be removed by minify extensions
            $combined = '<' . $esiIncludeTag . ' src="' . $this->_getSubReqUrl('litemage/esi/getCombined', $sharedParams) . '" combine="main" cache-control="no-cache"/>' ;
			if ($this->_isDebug) {
				$this->_config->debugMesg('_updateResponseBody combined is ' . $combined);
			}
            $updated = true;
        }

        if ( $updated ) {
			// cannot insert at beginning of body, pagespeed module will complain
			$pos = stripos($responseBody, '<body');
			if ($pos) {
				$pos = strpos($responseBody, '>', $pos);
				$newbody = substr($responseBody, 0, $pos+1) . $combined . $tracker . substr($responseBody, $pos+1);
				$response->setBody($newbody);
			}
			else {
	            $response->setBody($combined . $tracker . $responseBody) ;
				if ($this->_isDebug) {
					$this->_config->debugMesg('_updateResponseBody failed to insert combined after <body>');
				}
			}
        }

    }

    protected function _getCacheTagHeader($notEsiReq)
    {
        $tags = $this->_cacheVars['tag'] ;
        if ($notEsiReq) {
            if ( count($tags) == 0 ) {
                // set tag for product id, cid, and pageid
                if ( ($curProduct = Mage::registry('current_product')) != NULL ) {
                    $tags[] = self::TAG_PREFIX_PRODUCT . $curProduct->getId() ;
                }
                elseif ( ($curCategory = Mage::registry('current_category')) != NULL ) {
                    $tags[] = self::TAG_PREFIX_CATEGORY . $curCategory->getId() ;
                }
            }

            $currStore = Mage::app()->getStore() ;
            if ($currStore->getCurrentCurrencyCode() != $currStore->getBaseCurrencyCode()) {
                $tags[] = 'CURR'; // will be purged by currency rate update event
            }
        }

        $tag = count($tags) ? implode(',', $tags) : '' ;
        return $tag ;
    }

    protected function _getPurgeCacheTags()
    {
        $tags = $this->_cacheVars['tag'] ;
        if (empty($tags)) {
            // set tag for product id, cid, and pageid
            if ( ($curProduct = Mage::registry('current_product')) != NULL ) {
                $tags[] = self::TAG_PREFIX_PRODUCT . $curProduct->getId() ;
            }
            elseif ( ($curCategory = Mage::registry('current_category')) != NULL ) {
                $tags[] = self::TAG_PREFIX_CATEGORY . $curCategory->getId() ;
            }
            else {
                // go by url
                $uri = str_replace('LITEMAGE_CTRL=PURGE', '', $_SERVER['REQUEST_URI']);
                if (substr($uri, -1) == '?') {
                    $uri = rtrim($uri, '?');
                }
                $tags[] = $uri;
            }
        }
        $tag = count($tags) ? implode(',', $tags) : '' ;
        return $tag ;
    }

    public function setEnvCookie()
    {
        $changed = false;
        $this->getDefaultEnvCookie();
        foreach ($this->_cacheVars['env'] as $name => $data) {
            $newVal = '';
            $oldVal = '';
            if ($data != NULL) {
                ksort($data); // data is array, key sorted
                foreach ($data as $k => $v) {
                    $newVal .= $k . '~' . $v . '~';
                }
            }
            if ($cookievar = $this->getCookieEnvVars($name)) {
                $oldVal = $cookievar['_ORG_'];
            }

            if ($oldVal != $newVal) {
                Mage::getSingleton('core/cookie')->set($name, $newVal);
                $changed = true;
                if ($this->_isDebug)
                    $this->_config->debugMesg('Env ' . $name . ' changed, old=' . $oldVal . '  new=' . $newVal) ;
            }
        }
        return $changed;

    }

    protected function _getCacheVaryOn()
    {
        $vary_on = array();

        foreach ($this->_cacheVars['env'] as $name => $data) {
            if ($name != $this->_defaultEnvVaryCookie) {
                $vary_on[] = 'cookie=' . $name;
            }
        }

        switch (count($vary_on)) {
            case 0: return '';
            case 1: return $vary_on[0];
            default: return implode(',', $vary_on);
        }

    }

    public function setDefaultEnvCookie()
    {
        // when calling set, always reset, as value may change during processing
        $default = array() ;
        $app = Mage::app() ;
        $currStore = $app->getStore() ;
        $currStoreId = $currStore->getId() ;
        $currStoreCurrency = $currStore->getCurrentCurrencyCode() ;
		$currStoreDefaultCurrency = $currStore->getDefaultCurrencyCode() ;

		if ($currStoreCurrency != $currStoreDefaultCurrency) {
            $default['curr'] = $currStoreCurrency ;
        }

        if ( $currStore->getWebsite()->getDefaultStore()->getId() != $currStoreId ) {
            $default['st'] = intval($currStoreId) ;
        }
        if ($diffGrp = $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_DIFFCUSTGRP)) {
            // diff cache copy peer customer group
            $currCustomerGroup = Mage::getSingleton('customer/session')->getCustomerGroupId() ;
            if ( Mage_Customer_Model_Group::NOT_LOGGED_IN_ID != $currCustomerGroup ) {
                if ($diffGrp == 1) // diff copy per group
                    $default['cgrp'] = $currCustomerGroup ;
                else    // diff copy for logged in user
                    $default['cgrp'] = 'in' ;
            }
        }
        if ($this->_config->isRestrainedIP()) {
            $default['dev'] = 1;  //developer mode for restrained IP
        }

        $this->_cacheVars['env'][$this->_defaultEnvVaryCookie] = count($default) > 0 ? $default : NULL ;
    }


    public function getDefaultEnvCookie()
    {
        if ( ! isset($this->_cacheVars['env'][$this->_defaultEnvVaryCookie]) ) {
            $this->setDefaultEnvCookie();
        }
        return $this->_cacheVars['env'][$this->_defaultEnvVaryCookie];
    }

    public function getEsiSharedParams()
    {
        if (!isset($this->_cacheVars['esiUrlSharedParams'])) {
            $design = Mage::getDesign() ;
            $currStore = Mage::app()->getStore() ;
            $urlParams = array(
                's' => $currStore->getId(),  // current store id
                'dp' => $design->getPackageName(),
                'dt' => $design->getTheme('layout') ) ;

            $currency = $currStore->getCurrentCurrencyCode();
            if ($currency != $currStore->getDefaultCurrencyCode()) {
                $urlParams['cur'] = $currency;
            }

            if ($diffGrp = $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_DIFFCUSTGRP)) {
                // diff cache copy peer customer group
                $currCustomerGroup = Mage::getSingleton('customer/session')->getCustomerGroupId() ;
                if ( Mage_Customer_Model_Group::NOT_LOGGED_IN_ID != $currCustomerGroup ) {
                    if ($diffGrp == 1) // diff copy per group
                        $urlParams['cg'] = $currCustomerGroup ;
                    else    // diff copy for logged in user
                        $urlParams['cg'] = 'in' ;
                }
            }
            // for public block, should consider vary on
            $this->_cacheVars['esiUrlSharedParams'] = $urlParams;

        }
        return $this->_cacheVars['esiUrlSharedParams'];
    }

    public function getCookieEnvVars( $cookieName )
    {
        if ( ! isset($this->_cacheVars['cookie'][$cookieName]) ) {
            $this->_cacheVars['cookie'][$cookieName] = NULL ;
            $cookieVal = Mage::getSingleton('core/cookie')->get($cookieName) ;
            if ( $cookieVal != NULL ) {
                $cv = explode('~', trim($cookieVal, '~')); // restore cookie value
                for ($i = 0 ; $i < count($cv) ; $i += 2) {
                    $this->_cacheVars['cookie'][$cookieName][$cv[$i]] = $cv[$i+1];
                }

                $this->_cacheVars['cookie'][$cookieName]['_ORG_'] = $cookieVal ;
            }
        }
        return $this->_cacheVars['cookie'][$cookieName] ;
    }

    public function addEnvVars($cookieName, $key='', $val='' )
    {
        if ( ! isset($this->_cacheVars['env'][$cookieName]) || ($this->_cacheVars['env'][$cookieName] == NULL) ) {
            $this->_cacheVars['env'][$cookieName] = array() ;
        }
        if ($key != '') {
            $this->_cacheVars['env'][$cookieName][$key] = $val ;
        }
    }

}
