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
class Litespeed_Litemage_EsiController extends Mage_Core_Controller_Front_Action
{

	const ESICACHE_ID = 'litemage_esi_data' ;
	const ESICACHE_ENTRYONLY = '__' ;

	protected $_processed = array() ;
	protected $_scheduled = array() ;
	protected $_esiCache ;
	protected $_helper ;
	protected $_config ;
	protected $_isDebug ;
	protected $_layout ;
	protected $_env = array( 'shared' => false, 'fetch_all' => false ) ;

	// defaultHandles, cache_id, cache_updated, layout_unique, translate_inline, inline_tag

	protected function _construct()
	{
		$this->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_PRE_DISPATCH, true) ;
		$this->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_POST_DISPATCH, true) ;
		$this->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true) ; // postdispatch will not set last viewed url
		$this->getFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH_BLOCK_EVENT, true) ;

		$this->_config = Mage::helper('litemage/data') ;
		if ( ! $this->_config->moduleEnabledForUser() ) {
			Mage::throwException('LiteMage module not enabled for user') ;
		}

		$this->_isDebug = $this->_config->isDebug() ;
		$this->_helper = Mage::helper('litemage/esi') ;
	}

	/**
	 * Retrieve current layout object
	 *
	 * @return Mage_Core_Model_Layout
	 */
	public function getLayout()
	{
		if ( $this->_layout == null ) {
			$this->_layout = Mage::getSingleton('litemage/esiLayout') ;
		}
		return $this->_layout ;
	}

	/**
	 * It seems this has to exist so we just make it redirect to the base URL
	 * for lack of anything better to do.
	 *
	 * @return null
	 */
	public function indexAction()
	{
		Mage::log('Err: litemage come to indexaction') ;
		$this->getResponse()->setRedirect(Mage::getBaseUrl()) ;
	}

	public function noRouteAction( $coreRoute = null )
	{
		//should not come here
		$origEsiUrl = $_SERVER['REQUEST_URI'] ;
		$esiData = new Litespeed_Litemage_Model_EsiData($origEsiUrl, $this->_config) ;
		switch ( $esiData->getAction() ) {
			case 'getCombined': $this->getCombinedAction() ;
				break ;
			case 'getFormKey': $this->getFormKeyAction() ;
				break ;
			case 'getBlock': $this->getBlockAction() ;
				break ;
			case 'getMessage': $this->getMessageAction() ;
				break ;
			case 'log': $this->logAction() ;
				break ;
			default: $this->_errorExit() ;
		}
	}

	protected function _errorExit()
	{
		$resp = $this->getResponse() ;
		$resp->setHttpResponseCode(500) ;
		$resp->setBody('<!-- ESI data is not valid -->') ;
	}

	public function getFormKeyAction()
	{
		$this->_getSingle() ;
	}

	public function logAction()
	{
		$this->_getSingle() ;
	}

	public function getMessageAction()
	{
		$this->_getSingle() ;
	}

	public function getBlockAction()
	{
		$this->_getSingle() ;
	}

	protected function _getSingle()
	{
		$esiUrl = $this->_setOriginalReq() ;
		$esiData = new Litespeed_Litemage_Model_EsiData($esiUrl, $this->_config) ;

		if ( $this->_isDebug ) {
			$this->_config->debugMesg('****** EsiController process ' . $esiData->getAction()) ;
		}
		$this->_initEnv($esiData) ;
		$this->_scheduleProcess($esiData) ;
		$this->_processScheduled() ;

		$this->_sendProcessed($esiData) ;
	}

	public function getCombinedAction()
	{
		$esiUrl = $this->_setOriginalReq() ;
		$esiData = new Litespeed_Litemage_Model_EsiData($esiUrl, $this->_config) ;
		$this->_initEnv($esiData) ;

		$esiIncludes = $_REQUEST['esi_include'] ;

		if ( $this->_isDebug ) {
			$this->_config->debugMesg('combined includes = ' . print_r($esiIncludes, true)) ;
		}

		if ( ($key = array_search('*', $esiIncludes)) !== false ) {
			$this->_env['fetch_all'] = true ;
			unset($esiIncludes[$key]) ;
			// need to add getformkey
			$esiIncludes = array_unique(array_merge($esiIncludes, array_keys($this->_esiCache))) ;
		}

		//add raw header here, to handle ajax exception
		header(Litespeed_Litemage_Helper_Esi::LSHEADER_CACHE_CONTROL . ': esi=on', true) ;
		$this->_helper->setCacheControlFlag(Litespeed_Litemage_Helper_Esi::CHBM_ESI_ON | Litespeed_Litemage_Helper_Esi::CHBM_ESI_REQ) ;

		$this->_processIncoming($esiIncludes) ;

		$this->_processScheduled() ;

		$this->_sendProcessedInline() ;
	}

	protected function _renderEsiBlock( $esiData )
	{
		$blockIndex = $esiData->getLayoutAttribute('bi') ;
		$block = $this->_layout->getEsiBlock($blockIndex) ;
		if ( ! $block ) {
			if ( $this->_isDebug ) {
				$this->_config->debugMesg('cannot get esi block ' . $blockIndex) ;
			}
			return '' ;
		}
		try {
			$out = $block->toHtml() ;
			if ( $this->_env['translate_inline'] ) {
				Mage::getSingleton('core/translate_inline')->processResponseBody($out) ;
			}
		} catch ( Exception $e ) {
			if ( $this->_isDebug ) {
				$this->_config->debugMesg('_renderEsiBlock, exception for block ' . $blockIndex . ' : ' . $e->getMessage()) ;
			}
		}

		return $out ;
	}

	protected function _renderEsiBlock1( $esiData, $saveLayout )
	{
		$blockIndex = $esiData->getLayoutAttribute('bi') ;
		$isFullLayout = $saveLayout ;
		$block = $this->_layout->getEsiBlock($blockIndex, $isFullLayout) ;
		if ( ! $block ) {
			if ( $this->_isDebug ) {
				$this->_config->debugMesg('cannot get esi block ' . $blockIndex) ;
			}
			return '' ;
		}
		try {
			$out = $block->toHtml() ;
			if ( $this->_env['translate_inline'] ) {
				Mage::getSingleton('core/translate_inline')->processResponseBody($out) ;
			}
		} catch ( Exception $e ) {
			if ( $this->_isDebug ) {
				$this->_config->debugMesg('_renderEsiBlock, exception for block ' . $blockIndex . ' : ' . $e->getMessage()) ;
			}
		}

		if ( $saveLayout && Mage::app()->useCache('layout') ) {
			$cacheId = $esiData->getLayoutCacheId() ;
			if ( $cacheId ) {
				$layoutXml = $block->getData('lm_xml') ;
				if ( $layoutXml ) {
					$tags = array( Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG,
						Mage_Core_Model_Layout_Update::LAYOUT_GENERAL_CACHE_TAG ) ;
					Mage::app()->saveCache($layoutXml, $cacheId, $tags) ;
				}
			}
		}
		return $out ;
	}

	// return esiUrl
	protected function _setOriginalReq()
	{
		$origEsiUrl = $_SERVER['REQUEST_URI'] ;
		$req = $this->getRequest() ;

		//set original host url
		if ( $refererUrl = $req->getServer('ESI_REFERER') ) {
			$_SERVER['REQUEST_URI'] = $refererUrl ;
			$req->setRequestUri($refererUrl) ;
			$req->setPathInfo() ;
			if ( $this->_isDebug ) {
				$this->_config->debugMesg('****** EsiController process ' . $origEsiUrl . ' referral ' . $refererUrl) ;
			}
		}
		else {
			Mage::throwException('Illegal entrance for LiteMage module') ;
		}
		return $origEsiUrl ;
	}

	protected function _parseUrlParams( $esiUrl )
	{
		$esiUrl = urldecode($esiUrl) ;
		$pos = strpos($esiUrl, 'litemage/esi/') ;
		if ( $pos === false ) {
			Mage::throwException('LiteMage module invalid esi data ' . $esiUrl) ;
		}
		$buf = explode('/', substr($esiUrl, $pos + 13)) ;
		$c = count($buf) ;
		$param = array() ;
		$param['action'] = $buf[0] ;
		$param['url'] = $esiUrl ;
		for ( $i = 1 ; ($i + 1) < $c ; $i+=2 ) {
			$param[$buf[$i]] = $buf[$i + 1] ;
		}
		return $param ;
	}

	protected function _processIncoming( $esiUrls )
	{
		if ( (Mage::getSingleton('core/session')->getData('_litemage_user') == null) && Mage::registry('LITEMAGE_NEWVISITOR') && (Mage::registry('current_customer') == null) ) {

			$this->_env['shared'] = true ;

			$list = array() ;
			foreach ( $esiUrls as $url ) {
				if ( $html = $this->_getShared($url) ) {
					$this->_processed[$url] = $html ;
				}
				else {
					$list[] = $url ;
				}
			}
		}
		else {
			$list = $esiUrls ;
		}
		$this->_env['inline_tag'] = $this->_config->esiTag('inline') ;

		foreach ( $list as $esiUrl ) {
			$esiData = new Litespeed_Litemage_Model_EsiData($esiUrl, $this->_config) ;
			$this->_scheduleProcess($esiData) ;
		}
	}

	protected function _scheduleProcess( $esiData )
	{
		$batchId = $esiData->getBatchId() ;
		if ( $batchId != Litespeed_Litemage_Model_EsiData::BATCH_DIRECT ) {
			$batchId = $esiData->initLayoutCache($this->_env['layout_unique']) ;
		}
		if ( ! isset($this->_scheduled[$batchId]) ) {
			$this->_scheduled[$batchId] = array() ;
		}
		$this->_scheduled[$batchId][$esiData->getUrl()] = $esiData ;
	}

	protected function _sendProcessed( $esiData )
	{
		$flag = Litespeed_Litemage_Helper_Esi::CHBM_ESI_REQ ;
		$tag = '' ;

		$attr = $esiData->getCacheAttribute() ;
		if ( $attr['ttl'] > 0 ) {
			$flag |= Litespeed_Litemage_Helper_Esi::CHBM_CACHEABLE ;
			if ( $attr['access'] == 'private' )
				$flag |= Litespeed_Litemage_Helper_Esi::CHBM_PRIVATE ;
			if ( $attr['cacheIfEmpty'] )
				$flag |= Litespeed_Litemage_Helper_Esi::CHBM_ONLY_CACHE_EMPTY ;
			$tag = $attr['tag'] ;
		}
		$this->_helper->setCacheControlFlag($flag, $attr['ttl'], $tag) ;


		$this->getResponse()->setBody($esiData->getRawOutput()) ;
	}

	protected function _sendProcessedInline()
	{
		$body = '' ;
		$esiInlineTag = $this->_env['inline_tag'] ;
		$shared = $this->_env['shared'] ;

		foreach ( $this->_processed as $url => $esiData ) {
			if ( is_string($esiData) ) {
				$body .= $esiData ;
			}
			else {
				$inlineHtml = $esiData->getInlineHtml($esiInlineTag, $shared) ;
				$refreshed = $this->_refreshCacheEntry($url, $esiData, $inlineHtml) ;
				if ( $this->_isDebug ) {
					if ( $refreshed == -1 )
						$status = ':no_cache' ;
					elseif ( $refreshed == 0 )
						$status = '' ;
					elseif ( $refreshed == 1 )
						$status = ':upd_entry' ;
					elseif ( $refreshed == 2 )
						$status = ':upd_detail' ;
					elseif ( $refreshed == 3 )
						$status = ':match_shared' ;

					$status .= ' ' . $esiData->getBatchId() . ' ' ;
					$cacheId = $esiData->getLayoutCacheId() ;
					if ( $cacheId ) {
						$status .= substr($cacheId, 11, 10) . ' ' ;
					}
					$this->_config->debugMesg('out' . $status . substr($inlineHtml, 0, strpos($inlineHtml, "\n"))) ;
				}
				$body .= $inlineHtml ;
			}
		}
		$this->getResponse()->setBody($body) ;

		if ( $this->_env['cache_updated'] && Mage::app()->useCache('layout') ) {
			$tags = array( Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG ) ;
			Mage::app()->saveCache(serialize($this->_esiCache), $this->_env['cache_id'], $tags) ;
		}
	}

	protected function _processScheduled()
	{
		$this->_env['translate_inline'] = Mage::getSingleton('core/translate_inline')->isAllowed() ;

		foreach ( $this->_scheduled as $batchId => $urllist ) {
			if ( $batchId == Litespeed_Litemage_Model_EsiData::BATCH_DIRECT ) {
				$this->_processDirect($urllist) ;
			}
			elseif ( $batchId == Litespeed_Litemage_Model_EsiData::BATCH_LAYOUT_READY ) {
				$this->_processLayout($urllist);
			}
			else {
				$hanldes = $this->_env['defaultHandles'] ;
				if ( $batchId != Litespeed_Litemage_Model_EsiData::BATCH_HANLE ) {
					$hanldes = array_merge(explode(',', $batchId), $hanldes) ;
				}
				$this->_layout->loadHanleXml($hanldes) ;
				$this->_saveEsiXml($urllist);
				$this->_processLayout($urllist);
			}
		}
	}

	protected function _saveEsiXml( $urllist )
	{
		foreach ( $urllist as $url => $esiData ) {
			$bi = $esiData->getLayoutAttribute('bi') ;
			if ( $block = $this->_layout->getBiBlock($bi) ) {
				if ($layoutXml = $block->getXmlString($bi) ) {
					$esiData->saveLayoutCache($this->_env['layout_unique'], $layoutXml);
				}
			}
			else {
				if ( $this->_isDebug ) {
					$this->_config->debugMesg('cannot get esi block ' . $bi) ;
				}
			}
		}

	}

	protected function _processLayout($urllist)
	{
		$response = $this->getResponse() ;
		foreach ( $urllist as $url => $esiData ) {
			$this->_layout->loadEsiLayout($esiData) ;
			$output = $this->_renderEsiBlock($esiData, false) ;
			$esiData->setRawOutput($output, $response->getHttpResponseCode()) ;
			$this->_processed[$url] = $esiData ;
		}

	}

	protected function _processDirect( $urllist )
	{
		foreach ( $urllist as $url => $esiData ) {
			switch ( $esiData->getAction() ) {
				case Litespeed_Litemage_Model_EsiData::ACTION_GET_FORMKEY:
					$this->_procDirectFormKey($esiData) ;
					break ;
				case Litespeed_Litemage_Model_EsiData::ACTION_LOG:
					$this->_procDirectLog($esiData) ;
					break ;
				case Litespeed_Litemage_Model_EsiData::ACTION_GET_MESSAGE:
					$this->_procDirectMessage($esiData) ;
					break ;
				case Litespeed_Litemage_Model_EsiData::ACTION_GET_BLOCK ;
					$this->_procDirectBlock($esiData) ;
					break ;
				default:
				// error out
			}
			$this->_processed[$url] = $esiData ;
		}
	}

	protected function _procDirectFormKey( $esiData )
	{
		$session = Mage::getSingleton('core/session') ;
		$real_formkey = $session->getData(Litespeed_Litemage_Helper_Esi::FORMKEY_NAME) ;
		if ( ! $real_formkey ) {
			$real_formkey = $session->getFormKey() ;
		}

		$esiData->setRawOutput($real_formkey) ;
	}

	protected function _procDirectLog( $esiData )
	{
		$data = $esiData->getData() ;
		$product = new Varien_Object() ;
		$product->setId($data['product']) ;

		$responseCode = 200 ;
		try {
			Mage::dispatchEvent('catalog_controller_product_view', array( 'product' => $product )) ;
		} catch ( Exception $e ) {
			$responseCode = 500 ;
			if ( $this->_isDebug ) {
				$this->_config->debugMesg('_logData, exception for product ' . $product->getId() . ' : ' . $e->getMessage()) ;
			}
		}
		$esiData->setRawOutput('', $responseCode) ;
	}

	protected function _procDirectMessage( $esiData )
	{
		$newMessages = new Litespeed_Litemage_Block_Core_Messages() ;
		$out = $newMessages->getEsiOutput($esiData) ;
		if ( $this->_env['translate_inline'] ) {
			Mage::getSingleton('core/translate_inline')->processResponseBody($out) ;
		}
		$esiData->setRawOutput($out) ;
	}

	protected function _procDirectBlock( $esiData )
	{
		$data = $esiData->getData() ;
		$block = new $data['pc']() ;
		if ( isset($data['pt']) ) {
			$block->setTemplate($data['pt']) ;
			$out = $block->renderView() ;
		}
		else {
			$out = $block->toHtml() ;
		}
		if ( $this->_env['translate_inline'] ) {
			Mage::getSingleton('core/translate_inline')->processResponseBody($out) ;
		}
		$esiData->setRawOutput($out) ;
	}

	protected function _initEnv( $esiData )
	{
		$action = $esiData->getAction() ;
		if ( $action == Litespeed_Litemage_Model_EsiData::ACTION_GET_FORMKEY ) {
			return ;
		}

		$d = $esiData->getData() ;

		$app = Mage::app() ;
		$app->setCurrentStore($app->getStore($d['s'])) ;

		if ( $action == Litespeed_Litemage_Model_EsiData::ACTION_LOG ) {
			return ; // only need to set store
		}

		Mage::getSingleton('core/design_package')->setPackageName($d['dp'])->setTheme($d['dt']) ;

		$curLocale = $app->getLocale() ;
		$locale = Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_LOCALE) ;
		if ( $curLocale->getLocaleCode() != $locale ) {
			$curLocale->setLocale($locale) ;
			$translator = $app->getTranslator() ;
			$translator->setLocale($locale) ;
			$translator->init('frontend') ;
		}

		$customer_session = Mage::getSingleton('customer/session') ;
		$customer_session->setNoReferer(true) ;
		if ( $customer_session->isLoggedIn() ) {
			Mage::register('current_customer', $customer_session->getCustomer()) ;
			$this->_env['defaultHandles'] = array( 'customer_logged_in' ) ;
		}
		else {
			$this->_env['defaultHandles'] = array( 'customer_logged_out' ) ;
		}

		$unique = join('__', $this->_helper->getEsiSharedParams()) ;

		$this->_env['cache_id'] = self::ESICACHE_ID . '_' . md5($unique) ;
		$this->_env['cache_updated'] = false ;
		$this->_env['layout_unique'] = $unique . '__' . $this->_env['defaultHandles'][0] ;

		$this->_esiCache = array() ;
		if ( Mage::app()->useCache('layout') ) {
			if ( $data = Mage::app()->loadCache($this->_env['cache_id']) ) {
				$this->_esiCache = unserialize($data) ;
			}
		}

		$this->_layout->getUpdate()->setCachePrefix($unique) ;
	}

	protected function _getShared( $url )
	{
		if ( ! empty($this->_esiCache[$url]) && ($this->_esiCache[$url] != self::ESICACHE_ENTRYONLY) ) {
			return $this->_esiCache[$url] ;
		}
		return null ;
	}

	// return -1: no cache, 0: no update, 1: update entry, 2: update detail
	protected function _refreshCacheEntry( $url, $esiData, &$inlineHtml )
	{
		$cacheAttr = $esiData->getCacheAttribute() ;
		if ( ($cacheAttr['access'] != 'private') || ($cacheAttr['ttl'] == 0) ) {
			return -1 ;
		}

		if ( $this->_env['shared'] ) {
			if ( empty($this->_esiCache[$url]) ) {
				$this->_esiCache[$url] = $inlineHtml ;
				$this->_env['cache_updated'] = true ;
				return 2 ;
			}
			return 0 ;
		}

		if ( ! isset($this->_esiCache[$url]) ) {
			// insert if entry not exist
			if ( $esiData->getAction() == Litespeed_Litemage_Model_EsiData::ACTION_GET_FORMKEY )
				$this->_esiCache[$url] = self::ESICACHE_ENTRYONLY ;
			else
				$this->_esiCache[$url] = '' ;

			$this->_env['cache_updated'] = true ;
			return 1 ;
		}

		$html = $this->_esiCache[$url] ;
		if ( $html != '' && $html != self::ESICACHE_ENTRYONLY ) {
			// check if same as shared
			$raw = '">' . $esiData->getRawOutput() . '</' . $this->_env['inline_tag'] . '>' ;
			if ( strpos($html, $raw) ) {
				$inlineHtml = $html ; // replace with shared attribute
				return 3 ;
			}
		}
		return 0 ;
	}

}
