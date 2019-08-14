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

    const ESIDATA_CACHE_ID = 'litemage_esi_data' ;
    const ESIDATA_CACHE_NOTSET = '__NOTSET__' ;

    protected $_esiData ;
    protected $_curData ;
    protected $_env = array() ;
    protected $_helper ;
    protected $_config ;
    protected $_isDebug ;
    protected $_layout ;

    protected function _construct()
    {
      $this->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_PRE_DISPATCH, true);
      $this->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_POST_DISPATCH, true);
    }

    /**
     * Retrieve current layout object
     *
     * @return Mage_Core_Model_Layout
     */
    public function getLayout()
    {
        if ( $this->_layout == null ) {
            $this->_layout = Mage::getSingleton('litemage/layout') ;
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
        //set esi off
        $origEsiUrl = $_SERVER['REQUEST_URI'] ;
        if ( $param = $this->_parseUrlParams($origEsiUrl) ) {
            switch ( $param['action'] ) {
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
        else {
            $this->_errorExit() ;
        }
    }

    protected function _errorExit()
    {
        $resp = $this->getResponse() ;
        $resp->setHttpResponseCode(500) ;
        $resp->setBody('<!-- ESI data is not valid -->') ;
    }

    /**
     * Spit out the form key for this session
     *
     * @return null
     */
    public function getFormKeyAction()
    {
        $action = 'getFormKey' ;
        if ( $this->_initUserParams($action) ) {
            $this->_setEsiFlag($this->_esiData[$action]) ;
            $data = $this->_esiData[$action]['raw_output'] ;
            $this->getResponse()->setBody($data) ;
        }
        else {
            $this->_errorExit() ;
        }
    }

    protected function _setEsiFlag( $esiData )
    {
        $flag = Litespeed_Litemage_Helper_Esi::CHBM_ESI_REQ ;
        $tag = '' ;

        if ( $esiData['ttl'] > 0 ) {
            $flag |= Litespeed_Litemage_Helper_Esi::CHBM_CACHEABLE ;
            if ( $esiData['access'] == 'private' )
                $flag |= Litespeed_Litemage_Helper_Esi::CHBM_PRIVATE ;
            if ( isset($esiData['cacheIfEmpty']) && $esiData['cacheIfEmpty'] )
                $flag |= Litespeed_Litemage_Helper_Esi::CHBM_ONLY_CACHE_EMPTY ;
            $tag = $esiData['tag'] ;
        }
        $this->_helper->setCacheControlFlag($flag, $esiData['ttl'], $tag) ;
    }

    public function getBlockAction()
    {
        if ( ! $this->_initUserParams('getBlock') ) {
            $this->_errorExit() ;
            return ;
        }

        $esiDatas = array_values($this->_esiData) ;
        $esiData = $esiDatas[0] ;
        if ( count($this->_esiData) > 1 ) {
            // not sure when this will happen
            $includeEsi = '<' . $this->_config->esiTag('include') . ' src="' . $esiData['url'] . '" combine="sub" cache-control="no-vary,private"/>' ;
            $this->_esiData['include'] = array( 'output' => $includeEsi ) ;
            $this->_getCombinedData() ;
        }
        else {
            $this->_setEsiFlag($esiData) ;
            if ( isset($esiData['output']) && ($esiData['output'] != self::ESIDATA_CACHE_NOTSET) ) {
                $this->getResponse()->setBody($esiData['output']) ;
            }
            else {
                $this->_blockData($esiData) ;
                if ( $this->_env['update_cache'] && Mage::app()->useCache('layout') ) {
                    if ( $this->_env['update_cache'] & 2 ) {
                        $this->_env['esiUrls'][$esiData['n']][$esiData['url']] = $this->getResponse()->getBody() ;
                    }
                    $tags = array( Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG ) ;
                    Mage::app()->saveCache(serialize($this->_env['esiUrls']), $this->_env['cache_id'], $tags) ;
                }
            }
        }
    }

    protected function _blockData( $esiData )
    {
        $this->_curData = $esiData ;
        if (isset($esiData['type'])) {
            // dynamic block
            $this->_layout->resetBlocks() ;
            if ($block = $this->_layout->createBlock($esiData['type'], $esiData['n'])) {
                if (isset($esiData['template'])) {
                    $block->setTemplate($esiData['template']);
                }
                $this->renderLayout($esiData['n']) ;
            }
        }
        else {
            $this->loadLayoutUpdates() ;
            $this->generateLayoutXml() ;
            $this->generateLayoutBlocks() ;
            $name_alias = isset($esiData['alias']) ? $esiData['alias'] : $esiData['n'];
            $this->renderLayout($name_alias) ;
        }
    }

    public function renderLayout( $output = '' )
    {
        // override, output can be block name or alias
        if ( $output != '' && $this->_layout->getOutputBlock($output) != null ) {
            try {
                parent::renderLayout($output) ;
            } catch ( Exception $e ) {
                if ( $this->_isDebug ) {
                    $this->_config->debugMesg('renderLayout, exception for block ' . $output  . ' : ' . $e->getMessage()) ;
                }
            }
        }
        else {
            if ( $this->_isDebug ) {
                $this->_config->debugMesg('renderLayout, not output for ' . $output) ;
            }
        }
        return $this ;
    }

    public function getMessageAction()
    {
        if ( $this->_initUserParams('getMessage') ) {
            $esiData = array_values($this->_esiData) ;
            $this->_setEsiFlag($esiData[0]) ;
            $this->_messageData($esiData[0]) ;
        }
        else {
            $this->_errorExit() ;
        }
    }

    protected function _messageData( $esiData )
    {
        $this->_curData = $esiData ;
        $this->loadLayoutUpdates() ;
        $this->generateLayoutXml() ;
        $this->generateLayoutBlocks() ;

        $name_alias = isset($esiData['alias']) ? $esiData['alias'] : $esiData['n'];

        $block = $this->_layout->getOutputBlock($name_alias) ;
        $newMessages = new Litespeed_Litemage_Block_Core_Messages() ;
        $newMessages->initByEsi($esiData['st'], $esiData['call'], $block) ;
        $this->renderLayout($name_alias) ;
    }

    public function getCombinedAction()
    {
        if ( ! $this->_initUserParams('getCombined') ) {
            $this->_errorExit() ;
            return ;
        }
        //add raw header here, to handle ajax exception
        header(Litespeed_Litemage_Helper_Esi::LSHEADER_CACHE_CONTROL . ': esi=on', true) ;
        $this->_getCombinedData() ;
    }

    protected function _getCombinedData()
    {
        $this->_helper->setCacheControlFlag(Litespeed_Litemage_Helper_Esi::CHBM_ESI_ON | Litespeed_Litemage_Helper_Esi::CHBM_ESI_REQ) ;
        $response = $this->getResponse() ;
        $response->clearBody() ;
        $body = '' ;

        foreach ( $this->_esiData as $key => $esiData ) {

            if ( isset($esiData['output']) && ($esiData['output'] != self::ESIDATA_CACHE_NOTSET) ) {
                $body .= $esiData['output'] ;
                continue ;
            }

            if ( $esiData['action'] == 'log' ) {
                $this->_logData($esiData) ;
                $esiData['raw_output'] = '' ;
            }

            if ( isset($esiData['raw_output']) ) { //getFormKey & log
                $inlineBody = $this->_getInlineBody($esiData, $esiData['raw_output'], 200) ;
            }
            else {
                if ( $esiData['action'] == 'getMessage' ) {
                    $this->_messageData($esiData) ;
                }
                else {
                    // getBlock
                    $this->_blockData($esiData) ;
                }
                // env['update_cache'] can be changed by logged_in handle
                $inlineBody = $this->_getInlineBody($esiData, $response->getBody(), $response->getHttpResponseCode(), ($this->_env['update_cache'] & 2)) ;
                $response->clearBody() ;
            }


            $body .= $inlineBody ;
        }
        $response->setBody($body) ;
        if ( $this->_env['update_cache'] && Mage::app()->useCache('layout')) {
            $tags = array( Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG ) ;
            Mage::app()->saveCache(serialize($this->_env['esiUrls']), $this->_env['cache_id'], $tags) ;
        }
    }

    protected function _getInlineBody( $esiData, $output, $responseCode, $updateShared = -1 )
    {
        $output = trim($output) ;
        $esiInlineTag = $this->_config->esiTag('inline');
        $initShared = false ;

        if ( ($updateShared != -1) && ($esiData['access'] == 'private') && ($esiData['ttl'] > 0) ) {
            if ( $updateShared == 0 ) { // check if same as shared
                $out2 = '">' . $output . "</$esiInlineTag>" ;
                if ( strpos($this->_env['esiUrls'][$esiData['n']][$esiData['url']], $out2) ) {
                    $buf = $this->_env['esiUrls'][$esiData['n']][$esiData['url']] ;
                    if ( $this->_isDebug ) {
                        $this->_config->debugMesg('Inline0m ' . substr($buf, 0, strpos($buf, "\n"))) ;
                    }
                    return $buf ;
                }
            }
            else if ( $updateShared == 2 ) {
                $initShared = true ;
            }
        }


        $buf = '<' . $esiInlineTag . ' name="' . $esiData['url'] . '" cache-control="' ;

        $ttl = $esiData['ttl'] ;
        if ( $ttl > 0 && ! in_array($responseCode, array( 200, 301, 404 )) ) {
            $ttl = 0 ;
        }

        if ( $ttl == 0 ) {
            $buf .= 'no-cache' ;
        }
        else {
            $buf .= $esiData['access'] . ',max-age=' . $esiData['ttl'] . ',no-vary' ;
            if ( $esiData['cacheIfEmpty'] )
                $buf .= ',set-blank' ;
            elseif ( $initShared ) {
                $buf .= ',shared' ;
            }

            $buf .= '" cache-tag="' . $esiData['tag'] ;
        }

        $buf .= '">' . $output . "</$esiInlineTag>\n" ;
        if ( $initShared ) {
            $this->_env['esiUrls'][$esiData['n']][$esiData['url']] = $buf ;
        }

        if ( $this->_isDebug ) {
            $this->_config->debugMesg('Inline' . $updateShared . ' ' . substr($buf, 0, strpos($buf, "\n"))) ;
        }

        return $buf ;
    }

    public function logAction()
    {
        $action = 'log' ;
        if ( $this->_initUserParams($action) ) {
            $this->_setEsiFlag($this->_esiData[$action]) ;
            $this->_logData($this->_esiData[$action]) ;
        }
        else
            $this->_errorExit() ;
    }

    protected function _logData( $esiData )
    {
        if ( isset($esiData['product']) ) {
            if ( isset($this->_env['s']) && ! isset($this->_env['dp']) ) {
                Mage::app()->setCurrentStore(Mage::app()->getStore($this->_env['s'])) ;
            }

            $product = new Varien_Object() ;
            $product->setId($esiData['product']) ;
            try {
                Mage::dispatchEvent('catalog_controller_product_view', array( 'product' => $product )) ;
            } catch ( Exception $e ) {
                if ( $this->_isDebug ) {
                    $this->_config->debugMesg('_logData, exception for product ' . $product->getId() . ' : ' . $e->getMessage()) ;
                }
            }
        }
    }

    protected function _initUserParams( $action )
    {
        $this->_config = Mage::helper('litemage/data') ;
        $this->_isDebug = $this->_config->isDebug() ;
        if ( ! $this->_config->moduleEnabledForUser() ) {
            return false ;
        }

        $this->_helper = Mage::helper('litemage/esi') ;


        $origEsiUrl = $_SERVER['REQUEST_URI'] ;
        $req = $this->getRequest() ;

        //set original host url
        if ( $refererUrl = $req->getServer('ESI_REFERER') ) {
            $_SERVER['REQUEST_URI'] = $refererUrl ;
            $req->setRequestUri($refererUrl) ;
            $req->setPathInfo() ;
        }
        else {
            // illegal entrance, only allow from lsws
            if ( $this->_isDebug ) {
                $this->_config->debugMesg('Illegal Entrance') ;
            }

            return false ;
        }

        if ( $this->_isDebug )
            $this->_config->debugMesg('****** EsiController init url ' . $origEsiUrl) ;


        $this->setFlag('', Mage_Core_Controller_Varien_Action::FLAG_NO_START_SESSION, true); // only set here for postdispatch, do not set last viewed url

        $this->_env['update_cache'] = 0 ;
        $this->_esiData = array() ;

        if ( $action == 'getCombined' ) {
            if ( ! isset($_REQUEST['esi_include']) ) {
                if ( $this->_isDebug ) {
                    $this->_config->debugMesg('Missing param esi_include') ;
                }

                return false ;
            }

            $esiIncludes = $_REQUEST['esi_include'] ;
            unset($_REQUEST['esi_include']) ;

            if ( $this->_isDebug ) {
                $this->_config->debugMesg('combined from ' . $refererUrl . ' includes = ' . print_r($esiIncludes, true)) ;
            }

            $this->_initEnv($req->getUserParams()) ;
            $res = $this->_parseEsiUrlList($esiIncludes, false) ;
            if ( ! $res )
                return false ;
        }
        else {
            if ( $param = $this->_parseUrlParams($origEsiUrl) ) {
                if ( $esiData = $this->_parseEsiData($param) ) {
                    $this->_esiData[$esiData['id']] = $esiData ;
                }
                else {
                    return false ;
                }
            }
            else {
                return false ;
            }
        }
        if ( isset($this->_env['fetch_all']) ) {
            $this->_fetchAll() ;
        }
        return true ;
    }

    protected function _fetchAll()
    {
        $extra = array() ;

        $session = Mage::getSingleton('core/session') ;
        $is_new_user = (($session->getData('_litemage_user') == null) && Mage::registry('LITEMAGE_NEWVISITOR') && (Mage::registry('current_customer') == null)) ;
        if ( $is_new_user ) {
            // new, return all blocks as non-logged in user first visit
            $session->setData('_litemage_user', 1) ;
            if ( method_exists($session, 'getFormKey') && ! isset($this->_esiData['getFormKey']) ) {
                $formkey_url = 'litemage/esi/getFormKey' ;
                $this->_esiData['getFormKey'] = $this->_getFormKeyEsiData($formkey_url) ;
            }
            foreach ( $this->_env['esiUrls'] as $blockname => $urls ) {
                foreach ( $urls as $url => $output ) {
                    if ( $output == self::ESIDATA_CACHE_NOTSET ) {
                        $this->_env['update_cache'] |= 2 ; // 2: full update, 1: update url only
                        if ( ! isset($this->_esiData[$url]) ) {
                            $extra[] = $url ;
                        }
                    }
                    else {
                        if ( isset($this->_esiData[$url]) ) {
                            $this->_esiData[$url]['output'] = $output ;
                        }
                        else {
                            $this->_esiData[$url] = array( 'output' => $output ) ;
                        }
                    }
                }
            }
        }
        else {
            // regenerate all blocks for that store
            foreach ( $this->_env['esiUrls'] as $blockname => $urls ) {
                foreach ( array_keys($urls) as $url ) {
                    if ( ! isset($this->_esiData[$url]) ) {
                        $extra[] = $url ;
                    }
                }
            }
        }

        if ( count($extra) ) {
            $res = $this->_parseEsiUrlList($extra, true) ;
        }

        if ( $this->_isDebug ) {
            $this->_config->debugMesg(($is_new_user ? 'New user' : 'Existing user') . ' fetch extra = ' . count($extra)) ;

            //if ($this->_isDebug)
            //Mage::log('extra = ' . print_r($extra, true))            ;
        }
    }

    protected function _parseUrlParams( $esiUrl )
    {
        $esiUrl = urldecode($esiUrl) ;
        if ( ($pos = strpos($esiUrl, 'litemage/esi/')) !== false ) {
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
        else
            return null ;
    }

    protected function _parseEsiUrlList( $esiUrls, $ignoreErr = false )
    {
        $res = true ;
        foreach ( $esiUrls as $esiUrl ) {

            if ( $esiUrl == '*' ) {
                $this->_env['fetch_all'] = 1 ;
                continue ;
            }
            $param = $this->_parseUrlParams($esiUrl) ;
            if ( $param == null ) {
                $res = false ;
                if ( $ignoreErr )
                    continue ;
                else
                    break ;
            }
            if ( $esiData = $this->_parseEsiData($param) ) {
                $this->_esiData[$esiData['id']] = $esiData ;
            }
            else {
                $res = false ;
                if ( $ignoreErr ) {
                    continue ;
                }
                else {
                    break ;
                }
            }
        }
        return $res ;
    }

    protected function _getFormKeyEsiData( $url )
    {
        $session = Mage::getSingleton('core/session') ;
        $real_formkey = $session->getData(Litespeed_Litemage_Helper_Esi::FORMKEY_NAME) ;
        if ( ! $real_formkey ) {
            $real_formkey = $session->getFormKey() ;
        }

		$pri_ttl = isset($this->_env['pri_ttl']) ? $this->_env['pri_ttl'] : $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_PRIVATETTL) ;

        return array( 'action' => 'getFormKey',
            'cacheIfEmpty' => false,
            'url' => $url,
            'ttl' => $pri_ttl,
            'tag' => 'E.formkey',
            'access' => 'private',
            'id' => 'getFormKey',
            'raw_output' => $real_formkey ) ;
    }

    protected function _parseEsiData( $params )
    {
        $action = $params['action'] ;
        $url = $params['url'] ;
        if ( $action == 'getFormKey' ) {
            return $this->_getFormKeyEsiData($url) ;
        }

        $esiData = array( 'action' => $action, 'cacheIfEmpty' => false, 'url' => $url ) ;

        if ( $action == 'log' ) {
            if ( isset($params['product']) )
                $esiData['product'] = $params['product'] ;
            if ( isset($params['s']) && ! isset($this->_env['s']) )
                $this->_env['s'] = $params['s'] ;
            $esiData['ttl'] = 0 ; // no cache
            $esiData['id'] = $action ;
        }
        else {

            if ( ! isset($this->_env['dp']) ) {
                if ( ! $params['s'] || ! $params['dp'] || ! $params['dt'] ) {
                    if ( $this->_isDebug ) {
                        $this->_config->debugMesg('Missing param s_dp_dt') ;
                    }
                    return false ;
                }

                $this->_initEnv($params) ;
            }

            $tag = $params['t'] ;
            if ( $tag == null ) {
                if ( $this->_isDebug ) {
                    $this->_config->debugMesg('Missing param t') ;
                }
                return false ;
            }
            else {
                $conf = $this->_config->getEsiConf('tag', $tag) ;
                if ($conf == null) {
                    if ( $this->_isDebug ) {
                        $this->_config->debugMesg('Missing config for tag '. $tag) ;
                    }
                    return false ;
                }
            }

            $esiData['b'] = explode(',', $params['b']) ;
            if (count($esiData['b']) == 0) {
                    if ( $this->_isDebug ) {
                        $this->_config->debugMesg('Missing param b') ;
                    }
                    return false ;
            }
            $blockName = $esiData['b'][0];
            $esiData['n'] = $blockName ;
            $esiData['h'] = isset($params['h']) ? explode(',', $params['h']) : array() ;
            $esiData['access'] = $conf['access'] ;
            $esiData['ttl'] = isset($conf['ttl']) ? $conf['ttl'] :
                    (($conf['access'] == 'private') ? $this->_env['pri_ttl'] : $this->_env['pub_ttl'] ) ;
            $esiData['tag'] = $conf['cache-tag'] ;
            if (isset($params['a'])) {
                $esiData['alias'] = $params['a'];
            }
            if (isset($params['p'])) {
                $esiData['type'] = str_replace('--', '/', $params['p']); // dynamic block type
                if (isset($params['l']))
                    $esiData['template'] = str_replace('--', '/', $params['l']);
            }

            if ( isset($params['st']) ) {
                $param1 = array( $params['st'], $params['call'] ) ; // session type and call func
                $param1 = str_replace('--', '/session', $param1) ;
                $param1 = str_replace('-', '/', $param1) ;
                $esiData['st'] = explode(',', $param1[0]) ;
                $esiData['call'] = $param1[1] ;
                $esiData['cacheIfEmpty'] = true ;
            }
            $esiData['id'] = $url ;
            if ( ($conf['access'] == 'private') && ($esiData['ttl'] > 0) ) {
                if ( ! isset($this->_env['esiUrls'][$blockName]) ) {
                    $this->_env['esiUrls'][$blockName] = array( $url => self::ESIDATA_CACHE_NOTSET ) ;
                    $this->_env['update_cache'] |= 1 ; // 2: full update, 1: update url only
                }
                elseif ( ! isset($this->_env['esiUrls'][$blockName][$url]) ) {
                    $this->_env['esiUrls'][$blockName][$url] = self::ESIDATA_CACHE_NOTSET ;
                    $this->_env['update_cache'] |= 1 ; // 2: full update, 1: update url only
                }
            }
        }

        return $esiData ;
    }

    protected function _initLayout()
    {
        $layout = $this->getLayout() ;
        $update = $layout->getUpdate() ;

        $update->setStoreId($this->_env['s']) ;
        // dispatch event for adding handles to layout update, this will auto add customer_logged_in or out
        Mage::dispatchEvent(
                'controller_action_layout_load_before', array( 'action' => $this, 'layout' => $layout )
        ) ;
        $this->_env['defaultHandles'] = $update->getHandles() ;
        if ( (($this->_env['update_cache'] & 2) == 2) && in_array('customer_logged_in', $this->_env['defaultHandles']) ) {
            $this->_env['update_cache'] &= ~2 ; // 2: full update, 1: update url only
            //Mage::log('customer logged in, unset update_cahce 2 to 1 = ' . $this->_env['update_cache']);
        }
    }

    public function loadLayoutUpdates()
    {
        //$_profilerKey = self::PROFILER_KEY . '::' . $this->getFullActionName() . $this->_curData['n'] ;

        if ( ! isset($this->_env['defaultHandles']) ) {
            $this->_initLayout() ;
        }

        $this->_layout->resetBlocks() ;
        $update = $this->_layout->getUpdate() ;
        $update->setBlockNames($this->_curData['b']) ;

        $update->addHandle($this->_curData['h']) ;
        $update->addHandle($this->_env['defaultHandles']) ;

        // load layout updates by specified handles
        //Varien_Profiler::start("$_profilerKey::layout_load") ;
        $update->load() ;
        //Varien_Profiler::stop("$_profilerKey::layout_load") ;

        return $this ;
    }

    protected function _initEnv( $params )
    {
        if (isset($this->_env['cache_id']))
            return;

        $this->_env['s'] = $params['s'] ;
        $this->_env['dp'] = $params['dp'] ;
        $this->_env['dt'] = $params['dt'] ;

        $app = Mage::app() ;
        $app->setCurrentStore($app->getStore($this->_env['s'])) ;
        Mage::getSingleton('core/design_package')
                ->setPackageName($this->_env['dp'])
                ->setTheme($this->_env['dt']) ;

        $curLocale = $app->getLocale() ;
        $locale = Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_LOCALE) ;
        if ( $curLocale->getLocaleCode() != $locale ) {
            $curLocale->setLocale($locale) ;
            $translator = $app->getTranslator() ;
            $translator->setLocale($locale) ;
            $translator->init('frontend') ;
        }

        $customer_session = Mage::getSingleton('customer/session') ;
		$customer_session->setNoReferer(true);
        if ( $customer_session->isLoggedIn() ) {
            Mage::register('current_customer', $customer_session->getCustomer()) ;
        }

        $tags = $this->_helper->getEsiSharedParams();
        $this->_env['cache_id'] = self::ESIDATA_CACHE_ID . '_' . md5(join('__', $tags)) ;

        $this->_env['esiUrls'] = array() ;
        if (Mage::app()->useCache('layout')) {
            if ( $data = Mage::app()->loadCache($this->_env['cache_id']) ) {
                $this->_env['esiUrls'] = unserialize($data) ;
            }
        }

		$this->_env['pri_ttl'] = $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_PRIVATETTL) ;
        $this->_env['pub_ttl'] = $this->_config->getConf(Litespeed_Litemage_Helper_Data::CFG_PUBLICTTL) ;
    }

}
