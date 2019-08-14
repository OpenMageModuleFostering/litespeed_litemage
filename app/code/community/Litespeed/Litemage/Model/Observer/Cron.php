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

class Litespeed_Litemage_Model_Observer_Cron extends Varien_Event_Observer
{

    const WARMUP_MAP_FILE = 'litemage_warmup_urlmap' ;
    const WARMUP_META_CACHE_ID = 'litemage_warmup_meta' ;
    const USER_AGENT = 'litemage_walker' ;
    const ENV_COOKIE_NAME = '_lscache_vary' ;

    protected $_meta ; // time, curfileline
	protected $_conf;
    protected $_isDebug ;
	protected $_debugTag;
	protected $_maxRunTime;
	protected $_curThreads = -1;
	protected $_curThreadTime ;
    protected $_curList ;
	protected $_listDir;
	protected $_priority;


    protected function _construct()
    {
        $helper = Mage::helper('litemage/data') ;
        $this->_isDebug = $helper->isDebug() ;
		if ($this->_isDebug) {
			$this->_debugTag = 'LiteMage [' . self::USER_AGENT . ':';
			if (isset($_SERVER['USER']))
				$this->_debugTag .= $_SERVER['USER'];
			elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
				$this->_debugTag .= $_SERVER['HTTP_X_FORWARDED_FOR'];
			$this->_debugTag .= ':'. $_SERVER['REQUEST_TIME'] . '] ' ;
		}
		$this->_listDir = Mage::getBaseDir('var') . DS . 'litemage';

		if (!is_dir($this->_listDir)) {
			mkdir($this->_listDir);
			chmod($this->_listDir, 0777);
		}

		$this->_conf = $helper->getWarmUpConf();
    }

	public function resetCrawlerList($listId)
	{
		$adminSession = Mage::getSingleton('adminhtml/session') ;
		$meta = Mage::app()->loadCache(self::WARMUP_META_CACHE_ID);
		$updated = false;

		if ($listId) {
			$id = strtolower($listId);
			if ( $meta ) {
				$meta = unserialize($meta) ;
				if (isset($meta[$id])) {
					unset($meta[$id]);
					$updated = true;
					$adminSession->addSuccess($listId . ' ' . Mage::helper('litemage/data')->__('List has been reset and will be regenerated in next run.')) ;
				}
				else {
					$adminSession->addError($listId . ' ' . Mage::helper('litemage/data')->__('List has been reset already. It will be regenerated in next run.')) ;
				}
			}
		}
		else {
			if ($meta) {
				Mage::app()->removeCache(self::WARMUP_META_CACHE_ID);
				$updated = true;
				$adminSession->addSuccess(Mage::helper('litemage/data')->__('All lists have been reset and will be regenerated in next run.')) ;
			}
			else {
				$adminSession->addError(Mage::helper('litemage/data')->__('All lists have been reset already. It will be regenerated in next run.')) ;
			}
		}

		if ($updated) {
			$this->_saveMeta($meta);
		}
	}

	public function getCrawlerList($listId)
	{
		$output = '<h3>Generated URL List ' . $listId . '</h3>';
		if ( ($urls = $this->_getCrawlListFileData($listId)) != null ) {
			$output .= '<pre>' . $urls . '</pre>';
		}
		else {
			$output .= '<p>Cannot find generated URL list. It will be regenerated in next run.</p>';
		}
		return $output;
	}

    public function getCrawlerStatus()
    {
		$this->_initMeta();
		$meta = $this->_meta;
		$timefmt = 'Y-m-d H:i:s';
		$status = array('lastupdate' => '', 'endreason' => '',
			'stores' => array());

		if (isset($meta['lastupdate'])) {
			$status['lastupdate'] = date($timefmt, $meta['lastupdate']);
			unset($meta['lastupdate']);
		}

		if (isset($meta['endreason'])) {
			$status['endreason'] = $meta['endreason'];
			unset($meta['endreason']);
		}

		$lists = array();
		$priority = array();
		foreach ($meta as $listId => $store_stat) {
			$disp = array();
			$disp['priority'] = intval($store_stat['priority'] + 0.5);
			$disp['id'] = strtoupper($listId);
			$disp['store_name'] = $store_stat['store_name'];
			$disp['default_curr'] = $store_stat['default_curr'];
			$disp['baseurl'] = $store_stat['baseurl'];
			$disp['file'] = (isset($store_stat['file']) ? $store_stat['file'] : '');
			$disp['ttl'] = $store_stat['ttl'];
			$disp['interval'] = $store_stat['interval'];
			$disp['gentime'] = ($store_stat['gentime'] > 0) ? date($timefmt, $store_stat['gentime']) : 'N/A';
			$disp['tmpmsg'] = isset($store_stat['tmpmsg']) ? $store_stat['tmpmsg'] : '';
			$disp['lastquerytime'] = ($store_stat['lastquerytime'] > 0) ? date($timefmt, $store_stat['lastquerytime']) : 'N/A';
			$disp['endtime'] = ($store_stat['endtime'] > 0) ? date($timefmt, $store_stat['endtime']) : 'N/A';
			$disp['listsize'] = ($store_stat['listsize'] > 0) ? $store_stat['listsize'] : 'N/A';
			$disp['curpos'] = $store_stat['curpos'];
			$disp['env'] = $store_stat['env'];
			$disp['curvary'] = preg_replace("/_lscache_vary=.+;/", '', $store_stat['curvary']);
			$disp['queried'] = $store_stat['queried'];
			$priority[$listId] = $store_stat['priority'];
			$lists[$listId] = $disp;
		}
		asort($priority, SORT_NUMERIC);
		foreach ($priority as $id => $pri) {
			$status['stores'][] = $lists[$id];
		}
		return $status;
    }

    /**
     * called by cron job
     */

    public function warmCache()
    {
		if ($errmsg = $this->_init()) {
			$this->_meta['endreason'] = 'Skipped this round - ' . $errmsg;
			$this->_saveMeta();
			$this->_debugLog('Cron warmCache skip this round - ' . $errmsg);
			return;
		}

        $options = array(
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_TIMEOUT		=> 180
        );

        $client = new Varien_Http_Adapter_Curl();
		$curCookie = '';
		$endReason = '';

		while ($urls = $this->_getNextUrls($curCookie)) {
			$curlOptions = $options;
			if ($curCookie) {
				$curlOptions[CURLOPT_COOKIE] = $curCookie;
			}

			if ($this->_isDebug) {
				$id = $this->_curList['id'];
				$this->_debugLog('crawling ' . $id . ' urls (cur_pos:' . $this->_meta[$id]['curpos'] . ') with cookie ' . $curCookie . ' ' . print_r($urls, true));
			}

			try {
				$client->multiRequest($urls, $curlOptions);
	          } catch ( Exception $e ) {
				  $endReason = 'Error when crawling url ' . implode(' ', $urls) . ' : ' . $e->getMessage();
				  break ;
            }


			$this->_finishCurPosition() ;

			if ($this->_meta['lastupdate'] > $this->_maxRunTime) {
				$endReason = Mage::helper('litemage/data')->__('Stopped due to exceeding defined Maximum Run Time.');
				break;
			}

			if ($this->_meta['lastupdate'] - 60 > $this->_curThreadTime) {
				$this->_adjustCurThreads();
				if ($this->_curThreads == 0) {
					$endReason = Mage::helper('litemage/data')->__('Stopped due to current system load exceeding defined load limit.');
					break;
				}
			}
		}
		$this->_meta['endreason'] = $endReason;

        $this->_saveMeta() ;
		if ( $this->_isDebug ) {
			$this->_debugLog($endReason . ' cron meta end = ' . print_r($this->_meta, true)) ;
		}
	}

	protected function _getCrawlListFileData($listId)
	{
		$filename = $this->_listDir . DS . self::WARMUP_MAP_FILE . '_' . strtolower($listId);
		if (!file_exists($filename))
			return null;
		else
			return file_get_contents($filename);
	}

	protected function _saveCrawlListFileData($listId, $data)
	{
		$filename = $this->_listDir . DS . self::WARMUP_MAP_FILE . '_' . strtolower($listId);
		if (!file_put_contents($filename, $data)) {
			$this->_debugLog('Failed to save url map file ' . $filename);
		}
		else {
			chmod($filename, 0644);
		}
	}

	protected function _prepareCurList()
	{
		$id = array_shift($this->_priority);
		if ($id == null) {
			return false;
		}

		$m = $this->_meta[$id];
		// parse env & get all possible varies
		$vary = array();
		$fixed = $this->_parseEnvCookies($m['env'], $vary);
		if (!in_array($m['curvary'], $vary) || $m['curpos'] > $m['listsize']) {
			// reset current pointer
			$this->_meta[$id]['curvary'] = $vary[0];
			$this->_meta[$id]['curpos'] = 0;
			if ( $this->_isDebug ) {
				$this->_debugLog('Reset current position pointer to 0. curvary is ' . $m['curvary']);
			}
		}

		while ($this->_meta[$id]['curvary'] != $vary[0]) {
			array_shift($vary);
		}

		$this->_curList = array('id' => $id, 'fixed' => $fixed, 'vary' => $vary, 'working' => 0);
		if ($m['gentime'] > 0 && $m['endtime'] == 0
				&& ($urls = $this->_getCrawlListFileData($id)) != null ) {
			$allurls = explode("\n", $urls) ;
			// verify data
			$header = explode("\t", array_shift($allurls));
			if (($m['gentime'] == $header[0])
					&& ($m['listsize'] == $header[1])
					&& ($m['env'] == $header[2])
					&& count($allurls) == $m['listsize']) {
				$this->_curList['urls'] = $allurls;
			}
			else if ( $this->_isDebug ) {
				$this->_debugLog('load saved url list, header does not match, will regenerate') ;
			}
		}

		if (!isset($this->_curList['urls'])) {
			// regenerate
			$this->_curList['urls'] = $this->_generateUrlList($id);
		}

		if ($this->_meta[$id]['listsize'] > 0) {
			return true;
		}
		else {
			// get next list
			return $this->_prepareCurList();
		}
	}

    protected function _parseEnvCookies( $env, &$vary )
    {
		$fixed = 'litemage_cron=' . self::USER_AGENT .';';
        if ( $env ) {
			$lsvary = array();
			$multiCurr = array('-') ; // default currency
			$multiCgrp = array('-') ; // default user group

			$env = trim($env, '/');
			$envs = explode('/', $env) ;
            $envVars = array() ;
            $cnt = count($envs) ;
            for ( $i = 0 ; ($i + 1) < $cnt ; $i+=2 ) {
                $envVars[$envs[$i]] = $envs[$i + 1] ;
            }
            if ( isset($envVars['vary_dev']) ) {
                $lsvary['dev'] = 1 ;
            }

            if ( isset($envVars['store']) ) {
                $fixed .= Mage_Core_Model_Store::COOKIE_NAME . '=' . $envVars['store'] . ';';
                $lsvary['st'] = $envVars['storeId'] ;
            }

            if ( isset($envVars['vary_cgrp']) ) {
                $multiCgrp = explode(',', $envVars['vary_cgrp']) ;
            }

            if ( isset($envVars['vary_curr']) ) {
                $multiCurr = explode(',', $envVars['vary_curr']) ;
            }

            foreach ( $multiCurr as $currency ) {
                $cookie_vary = '';
				$lsvary1 = $lsvary;

                if ( $currency != '-' ) {
                    $lsvary1['curr'] = $currency ;
                    $cookie_vary .= Mage_Core_Model_Store::COOKIE_CURRENCY . '=' . $currency . ';' ;
                }

                foreach ( $multiCgrp as $cgrp ) {
                    if ( $cgrp != '-' ) {
                        // need to set user id
                        $lsvary1['cgrp'] = $cgrp ;
                    }

					if (!empty($lsvary1)) {
	                    ksort($lsvary1) ;
		                $lsvary1_val = '';
						foreach ($lsvary1 as $k => $v) {
							$lsvary1_val .= $k . '%7E' . urlencode($v) . '%7E'; // %7E is "~"
						}
						$cookie_vary .= self::ENV_COOKIE_NAME . '=' . $lsvary1_val . ';';
					}
					$vary[] = $cookie_vary; // can be empty string for default no vary
                }
            }

        }
		else {
			$vary[] = ''; // no vary
		}

        return $fixed;
    }

    protected function _getNextUrls(&$curCookie)
    {
		$id = $this->_curList['id'];
		if ($this->_meta[$id]['endtime'] > 0) {
			if ($this->_prepareCurList()) {
				return $this->_getNextUrls($curCookie);
			}
			else {
				return null;
			}
		}

		$curpos = $this->_meta[$id]['curpos'];
		$curCookie = $this->_curList['fixed'] . $this->_meta[$id]['curvary'];
		$urls = array_slice($this->_curList['urls'],
				$this->_meta[$id]['curpos'],
				$this->_curThreads);
		$this->_curList['working'] = count($urls);

		if (empty($urls)) {
			return null;
		}
		else {
			$baseurl = $this->_meta[$id]['baseurl'];
			foreach ($urls as $key => $val) {
				$urls[$key] = $baseurl . $val;
			}
			return $urls ;
		}
    }

    protected function _finishCurPosition()
    {
		$now = time();
		$id = $this->_curList['id'];
		if (($this->_meta[$id]['curpos'] + $this->_curList['working']) < $this->_meta[$id]['listsize']) {
			$this->_meta[$id]['curpos'] += $this->_curList['working'];
		}
		else {
			if (count($this->_curList['vary']) > 1) {
				array_shift($this->_curList['vary']);
				$this->_meta[$id]['curvary'] = $this->_curList['vary'][0];
				$this->_meta[$id]['curpos'] = 0;
			}
			else {
				$this->_meta[$id]['endtime'] = $now;
				$this->_meta[$id]['curpos'] = $this->_meta[$id]['listsize'];
			}
		}
		$this->_meta[$id]['queried'] += $this->_curList['working'];
		$this->_meta[$id]['lastquerytime'] = $now;
		$this->_meta['lastupdate'] = $now;
		$this->_curList['working'] = 0;
    }

	protected function _newStoreMeta($storeInfo, $tmpmsg)
	{
		$meta = array(
			'id' => $storeInfo['id'], // store1, custom1, delta
			'storeid' => $storeInfo['storeid'],
			'store_name' => $storeInfo['store_name'],
			'default_curr' => $storeInfo['default_curr'],
			'baseurl' => $storeInfo['baseurl'],
			'ttl' => $storeInfo['ttl'],
			'interval' => $storeInfo['interval'],
			'priority' => $storeInfo['priority'],
			'gentime' => 0,
			'listsize' => 0,
			'env' => $storeInfo['env'],
			'curpos' => 0,
			'curvary' => '',
			'queried' => 0,
			'lastquerytime' => 0,
			'endtime' => 0);
		if (isset($storeInfo['file'])) {
			$meta['file'] = $storeInfo['file'];
		}
		if ($tmpmsg) {
			$meta['tmpmsg'] = $tmpmsg;
		}

		return $meta;
	}

    protected function _saveMeta($meta=null)
    {
		if ($meta == null) {
			$meta = $this->_meta ;
		}
        $tags = array( Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG, self::WARMUP_META_CACHE_ID ) ;
        Mage::app()->saveCache(serialize($meta), self::WARMUP_META_CACHE_ID, $tags) ;
    }

	protected function _initMeta()
	{
		$this->_meta = array();

		$saved = array();
        if ( $meta = Mage::app()->loadCache(self::WARMUP_META_CACHE_ID) ) {
            $saved = unserialize($meta) ;
			if (isset($saved['lastupdate'])) {
				$this->_meta['lastupdate'] = $saved['lastupdate'];
			}
			if (isset($saved['endreason'])) {
				$this->_meta['endreason'] = $saved['endreason'];
			}
        }

		if (empty($this->_conf['store']))  {
			return array();
		}

		$unfinished = array();
		$expired = array();
		$curtime = time();

		foreach( $this->_conf['store'] as $listId => $info) {
			$tmpmsg = '';
			if (isset($saved[$listId])) {
				// validate saved
				$m = $saved[$listId];
				if (isset($m['storeid']) && ($m['storeid'] == $info['storeid'])
					&& isset($m['env']) && ($m['env'] == $info['env'])
					&& isset($m['interval']) && ($m['interval'] == $info['interval'])
					&& isset($m['priority']) && ($m['priority'] == $info['priority'])
					&& isset($m['baseurl']) && ($m['baseurl'] == $info['baseurl'])) {

					if ($m['gentime'] == 0) {
						$tmpmsg = 'New list will be generated';
						$unfinished[$listId] = $m['priority'];
					}
					elseif ($m['endtime'] == 0) {
						// not finished
						$unfinished[$listId] = $m['priority'];
						$tmpmsg = 'Has not finished, will continue.';
					}
					elseif (($m['endtime'] + $m['interval'] < $curtime)) {
						// expired
						$expired[$listId] = $m['priority'];
						$tmpmsg = 'Run interval passed, will restart.';
					}
					else {
						$tmpmsg = 'Still fresh within interval.';
					}
					$m['tmpmsg'] = $tmpmsg;
					$this->_meta[$listId] = $m;
				}
				else  {
					$tmpmsg = 'Saved configuration does not match current configuration. List will be regenerated.';
					$m['gentime'] = 0;
					if ($m['endtime'] == 0)
						$unfinished[$listId] = $m['priority'];
					else
						$expired[$listId] = $m['priority'];
				}
			}
			else {
				$tmpmsg = 'New list will be generated';
				$m['gentime'] = 0;
				$unfinished[$listId] = $info['priority'];
			}
			if (!isset($this->_meta[$listId])) {
				$this->_meta[$listId] = $this->_newStoreMeta($info, $tmpmsg);
			}
		}

		asort($unfinished, SORT_NUMERIC);
		asort($expired, SORT_NUMERIC);
		$priority = array_merge(array_keys($unfinished), array_keys($expired));

		return $priority;
	}

    protected function _init()
    {
		if (empty($this->_conf['store'])) {
			return 'configuration not enabled.';
		}

		$this->_priority = $this->_initMeta();
		if ( empty($this->_priority) ) {
            return 'no url list available for warm up';
        }

		$maxTime = (int) ini_get('max_execution_time') ;
		if ( $maxTime == 0 )
			$maxTime = 300 ; // hardlimit
		else
			$maxTime -= 5 ;

		$configed = $this->_conf[Litespeed_Litemage_Helper_Data::CFG_WARMUP_MAXTIME];
		if ( $maxTime > $configed )
			$maxTime = $configed ;
		$this->_maxRunTime = $maxTime + time();

		$this->_adjustCurThreads();

		if ($this->_curThreads == 0) {
			return 'load over limit' ;
		}

		if ($this->_prepareCurList())
			return ''; // no err msg
		else {
			return 'No url list available';
		}
    }

	protected function _adjustCurThreads()
	{
		$max = $this->_conf[Litespeed_Litemage_Helper_Data::CFG_WARMUP_THREAD_LIMIT];
		$limit = $this->_conf[Litespeed_Litemage_Helper_Data::CFG_WARMUP_LOAD_LIMIT] ;

		$load = sys_getloadavg() ;
		$curload = $load[0];

		if ($this->_curThreads == -1) {
			// init
			if ($curload > $limit) {
				$curthreads = 0;
			}
			elseif ($curload >= ($limit - 1)) {
				$curthreads = 1;
			}
			else {
				$curthreads = intval($limit - $curload);
				if ($curthreads > $max) {
					$curthreads = $max;
				}
			}
		}
		else {
			// adjust
			$curthreads = $this->_curThreads;
			if ($curload >= $limit + 1 ) {
				sleep(5);  // sleep 5 secs
				if ($curthreads >= 1)
					$curthreads --;
			}
			elseif ($curload >= $limit) {
				if ($curthreads > 1)	// if already 1, keep
					$curthreads --;
			}
			elseif ( ($curload + 1) < $limit ) {
				if ($curthreads < $max)
					$curthreads ++;
			}
		}


		if ($this->_isDebug) {
			$this->_debugLog('set current threads = ' . $curthreads . ' previous=' . $this->_curThreads
					. ' max_allowed=' . $max . ' load_limit=' . $limit . ' current_load=' . $curload);
		}

		$this->_curThreads = $curthreads;
		$this->_curThreadTime = time();

	}

	protected function _generateUrlList($listId)
	{
		if ($listId{0} == 's') {
			// store
			return $this->_generateStoreUrlList($listId);
		}
		else {
			return $this->_generateCustUrlList($listId);
		}
	}

    protected function _generateStoreUrlList($listId)
    {
        $app = Mage::app() ;
		$storeId = $this->_meta[$listId]['storeid'];
		$store = $app->getStore($storeId);
		$app->setCurrentStore($store) ;

		$baseUrl = $this->_meta[$listId]['baseurl'];
		$basen = strlen($baseUrl);

		$urls = array(''); // first line is empty for base url

        $visibility = array(
            Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
        ) ;
        $catModel = Mage::getModel('catalog/category') ;

		$activeCat = $catModel->getCollection($storeId)->addIsActiveFilter() ;

		$produrls = array();

		// url with cat in path
		foreach ( $activeCat as $cat ) {
			$caturl = $cat->getUrl() ;
			if (strncasecmp($baseUrl, $caturl, $basen) == 0) {
				$urls[] = substr($caturl, $basen);
			}
			foreach ( $cat->getProductCollection($storeId)
					->addUrlRewrite($cat->getId())
					->addAttributeToFilter('visibility', $visibility)
			as $prod ) {
				$produrl = $prod->getProductUrl() ;
				if (strncasecmp($baseUrl, $produrl, $basen) == 0) {
					$produrls[] = substr($produrl, $basen);
				}
			}
		}

		// url with no cat info
		foreach ( $activeCat as $cat ) {
			foreach ( $cat->getProductCollection($storeId)
					->addAttributeToFilter('visibility', $visibility)
			as $prod ) {
				$produrl = $prod->getProductUrl() ;
				if (strncasecmp($baseUrl, $produrl, $basen) == 0) {
					$produrls[] = substr($produrl, $basen);
				}
			}
		}

		$sitemap = 'sitemap/cms_page' ;
		$sitemap = (Mage::getConfig()->getNode('modules/MageWorx_XSitemap') !== false) ?
                    'xsitemap/cms_page' : 'sitemap/cms_page' ;

		$sitemodel = Mage::getResourceModel($sitemap);
		if ($sitemodel != null) {
			foreach ( $sitemodel->getCollection($storeId) as $item ) {
				$sitemapurl = $item->getUrl();
				$urls[] = $sitemapurl;
			}
		}

		$produrls = array_unique($produrls);
		$urls = array_merge($urls, $produrls);

		$this->_meta[$listId]['listsize'] = count($urls);
		$this->_meta[$listId]['gentime'] = time();
		$this->_meta[$listId]['curpos'] = 0;
		//$this->_meta[$listId]['queried'] = 0;
		$this->_meta['lastupdate'] = $this->_meta[$listId]['gentime'];
		$header = $this->_meta[$listId]['gentime'] . "\t"
				. $this->_meta[$listId]['listsize'] . "\t"
				. $this->_meta[$listId]['env'] . "\n";

		if ( $this->_isDebug ) {
			$this->_debugLog('Generate url map for ' . $listId . ' url count =' . $this->_meta[$listId]['listsize']);
		}

		$buf = $header . implode("\n", $urls);

		$this->_saveCrawlListFileData($listId, $buf);

		return $urls;
    }

    protected function _generateCustUrlList($listId)
    {
		$baseUrl = $this->_meta[$listId]['baseurl'];
		$basen = strlen($baseUrl);
		$custlist = $this->_meta[$listId]['file'];
		$lines = file($custlist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$urls = array();
		if ($lines === false) {
			if ( $this->_isDebug ) {
				$this->_debugLog('Fail to read custom URL list file ' . $custlist);
			}
		}
		else if (!empty($lines)) {
			$urls[] = ''; // always add home page
			foreach ($lines as $line) {
				$line = ltrim(trim($line), '/');
				if ($line != '') {
					if (strpos($line, 'http') !== false) {
						if (strncasecmp($baseUrl, $line, $basen) == 0) {
							$urls[] = substr($line, $basen);
						}
					}
					else {
						$urls[] = $line;
					}
				}
			}
			$urls = array_unique($urls);
		}

		$this->_meta[$listId]['listsize'] = count($urls);
		$this->_meta[$listId]['gentime'] = time();
		$this->_meta['lastupdate'] = $this->_meta[$listId]['gentime'];
		$header = $this->_meta[$listId]['gentime'] . "\t"
				. $this->_meta[$listId]['listsize'] . "\t"
				. $this->_meta[$listId]['env'] . "\n";

		if ( $this->_isDebug ) {
			$this->_debugLog('Generate url map for ' . $listId . ' url count =' . $this->_meta[$listId]['listsize']);
		}

		$buf = $header . implode("\n", $urls);
		$this->_saveCrawlListFileData($listId, $buf);

		return $urls;
    }

    protected function _debugLog( $message, $level = 0 )
    {
        if ( $this->_isDebug ) {
            $message = str_replace("\n", ("\n" . $this->_debugTag . '  '), $message);
            Mage::log($this->_debugTag . ' '. $message ) ;
        }

    }

}
