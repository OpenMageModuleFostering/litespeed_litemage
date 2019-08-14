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

class Litespeed_Litemage_Helper_Data extends Mage_Core_Helper_Abstract
{

    const CFGXML_DEFAULTLM = 'default/litemage' ;
    const CFGXML_ESIBLOCK = 'frontend/litemage/esiblock' ;

	const STOREXML_PUBLICTTL = 'litemage/general/public_ttl' ;
	const STOREXML_PRIVATETTL = 'litemage/general/private_ttl' ;
	const STOREXML_TRACKLASTVIEWED = 'litemage/general/track_viewed' ;
	const STOREXML_DIFFCUSTGRP = 'litemage/general/diff_customergroup' ;
	const STOREXML_WARMUP_EANBLED = 'litemage/warmup/enable_warmup' ;
	const STOREXML_WARMUP_MULTICURR = 'litemage/warmup/multi_currency' ;
	const STOREXML_WARMUP_INTERVAL = 'litemage/warmup/interval' ;
	const STOREXML_WARMUP_PRIORITY = 'litemage/warmup/priority' ;
	const STOREXML_WARMUP_CUSTLIST = 'litemage/warmup/custlist' ;
	const STOREXML_WARMUP_CUSTLIST_PRIORITY = 'litemage/warmup/custlist_priority' ;
	const STOREXML_WARMUP_CUSTLIST_INTERVAL = 'litemage/warmup/custlist_interval' ;

    const CFG_ENABLED = 'enabled' ;
    const CFG_DEBUGON = 'debug' ;
    const CFG_WARMUP = 'warmup' ;
	const CFG_WARMUP_ALLOW = 'allow_warmup' ;
    const CFG_WARMUP_EANBLED = 'enable_warmup' ;
    const CFG_WARMUP_LOAD_LIMIT = 'load_limit' ;
    const CFG_WARMUP_MAXTIME = 'max_time' ;
	const CFG_WARMUP_THREAD_LIMIT = 'thread_limit' ;
    const CFG_WARMUP_MULTICURR = 'multi_currency';
    const CFG_TRACKLASTVIEWED = 'track_viewed' ;
    const CFG_DIFFCUSTGRP = 'diff_customergroup' ;
    const CFG_PUBLICTTL = 'public_ttl' ;
    const CFG_PRIVATETTL = 'private_ttl' ;
    const CFG_ESIBLOCK = 'esiblock' ;
    const CFG_NOCACHE = 'nocache' ;
    const CFG_CACHE_ROUTE = 'cache_routes' ;
    const CFG_NOCACHE_ROUTE = 'nocache_routes' ;
    const CFG_FULLCACHE_ROUTE = 'fullcache_routes' ;
    const CFG_NOCACHE_VAR = 'nocache_vars' ;
    const CFG_NOCACHE_URL = 'nocache_urls' ;
    const CFG_ALLOWEDIPS = 'allow_ips' ;
    const CFG_ADMINIPS = 'admin_ips';
    const LITEMAGE_GENERAL_CACHE_TAG = 'LITESPEED_LITEMAGE' ;

    // config items
    protected $_conf = array() ;
    protected $_userModuleEnabled = -2 ; // -2: not set, true, false
    protected $_esiTag;
    protected $_isDebug ;
    protected $_debugTag = 'LiteMage' ;

    public function moduleEnabled()
    {
        if ( isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE'] ) {
            return $this->getConf(self::CFG_ENABLED) ;
        }
        else {
            return false ;
        }
    }

    public function moduleEnabledForUser()
    {
        if ( $this->_userModuleEnabled === -2 ) {
            $allowed = $this->moduleEnabled() ;
            if ( $allowed ) {
                $tag = '';
                $httphelper = Mage::helper('core/http') ;
                $remoteAddr = $httphelper->getRemoteAddr() ;
				if ( $httphelper->getHttpUserAgent() == 'litemage_walker' ) {
                    $tag = 'litemage_walker:';
				}
				else if ( $ips = $this->getConf(self::CFG_ALLOWEDIPS) ) {
					if ( ! in_array($remoteAddr, $ips) ) {
						$allowed = false ;
					}
                }

                if ($this->_isDebug && $allowed) {
                    $tag .= $remoteAddr ;
                    $msec = microtime();
                    $msec1 = substr($msec, 2, strpos($msec, ' ') -2);
                    $this->_debugTag .= ' [' . $tag . ':'. $_SERVER['REMOTE_PORT'] . ':' . $msec1 . ']' ;
                }
            }
            $this->_userModuleEnabled = $allowed ;
        }
        return $this->_userModuleEnabled ;
    }

    public function isAdminIP()
    {
        if ($adminIps = $this->getConf(self::CFG_ADMINIPS) ) {
            $remoteAddr = Mage::helper('core/http')->getRemoteAddr() ;
            if (in_array($remoteAddr, $adminIps)) {
                return true;
            }
        }
        return false;
    }

    public function isRestrainedIP()
    {
        return ($this->getConf(self::CFG_ALLOWEDIPS) != '') ;
    }

    public function isDebug()
    {
        return $this->getConf(self::CFG_DEBUGON) ;
    }

    public function esiTag($type)
    {
        if (isset($this->_esiTag[$type])) {
            return $this->_esiTag[$type];
        }

        if ( $this->_isDebug ) {
            $this->debugMesg('Invalid type for esiTag ' . $type);
        }
    }

    public function trackLastViewed()
    {
		return Mage::getStoreConfig(self::STOREXML_TRACKLASTVIEWED);
    }

    public function getEsiConf( $type = '', $name = '' ) //type = tag, block, event
    {
        $conf = $this->getConf('', self::CFG_ESIBLOCK) ;
        if ( $type == 'event' && ! isset($conf['event']) ) {
            $events = array() ;
            foreach ( $conf['tag'] as $tag => $d ) {
                if ( isset($d['purge_events']) ) {
                    $pes = array_keys($d['purge_events']) ;
                    foreach ( $pes as $e ) {
                        if ( ! isset($events[$e]) )
                            $events[$e] = array() ;
                        $events[$e][] = $d['cache-tag'];
                    }
                }
            }
            $this->_conf[self::CFG_ESIBLOCK]['event'] = $events ;
            return $events ;
        }
        if ( $type == '' )
            return $conf ;
        elseif ( $name == '' )
            return $conf[$type] ;
        else
            return $conf[$type][$name] ;
    }

	public function getWarmUpConf()
	{
		if (!isset($this->_conf[self::CFG_WARMUP])) {

			$storeInfo = array();
			if ( $this->getConf(self::CFG_ENABLED) ) {
				$this->getConf('', self::CFG_WARMUP);
				$app = Mage::app();
				$storeIds = array_keys($app->getStores());
				$vary_dev = $this->isRestrainedIP() ? '/vary_dev/1' : '';

				foreach ($storeIds as $storeId) {
					$isEnabled = Mage::getStoreConfig(self::STOREXML_WARMUP_EANBLED, $storeId);
					if ($isEnabled) {
						$store = $app->getStore($storeId);
						if (!$store->getIsActive()) {
							continue;
						}
						$site = $store->getWebsite();
						$is_default_store = ($site->getDefaultStore()->getId() == $storeId); // cannot use $app->getDefaultStoreView()->getId();
						$is_default_site = $site->getIsDefault();
						$orderAdjust = 0.0;
						if ($is_default_site)
							$orderAdjust -= 0.25;
						if ($is_default_store)
							$orderAdjust -= 0.25;

						$vary_curr = '';
						$curr = trim(Mage::getStoreConfig(self::STOREXML_WARMUP_MULTICURR, $storeId));
						if ($curr) {
							// get currency vary
							$availCurrCodes = $store->getAvailableCurrencyCodes() ;
							$default_currency = $store->getDefaultCurrencyCode() ;

							$currs = preg_split("/[\s,]+/", strtoupper($curr), null, PREG_SPLIT_NO_EMPTY) ;
							if (in_array('ALL', $currs)) {
								$currs = $availCurrCodes;
							}
							else {
								$currs = array_unique($currs);
							}

							foreach ($currs as $cur) {
								if ( $cur != $default_currency && in_array($cur, $availCurrCodes) ) {
									$vary_curr .= ',' . $cur ;
								}
							}
							if ($vary_curr) {
								$vary_curr = '/vary_curr/-' . $vary_curr; // "-" means default
							}
						}

						$vary_cgrp = '' ;
						/*if ( $diffGrp = Mage::getStoreConfig(self::STOREXML_DIFFCUSTGRP, $storeId)) ) {
						  //  $crawlgrp = 'out' ;
							 $crawlUsers = array(138, 137);
							  if ($crawlUsers) {
							  if ($diffGrp == 2) {
							  // for in & out
							  $crawlgrp .= ',in_138';
							  }
						 * '/vary_cgrp/' . $vary_customergroup ;
							  }
						//}*/

						$env = '';

						$priority  = Mage::getStoreConfig(self::STOREXML_WARMUP_PRIORITY, $storeId) + $orderAdjust;
						if (!$is_default_store) {
							$env .= '/store/' . $store->getCode() . '/storeId/' . $storeId ;
						}
						$env .= $vary_curr . $vary_cgrp . $vary_dev;
						$baseurl = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
						$ttl = Mage::getStoreConfig(self::STOREXML_PUBLICTTL, $storeId);
						$interval = Mage::getStoreConfig(self::STOREXML_WARMUP_INTERVAL, $storeId);
						if ($interval == '' || $interval < 600) { // for upgrade users, not refreshed conf
							$interval = $ttl;
						}

						if ($isEnabled == 1) {
							$listId = 'store' . $storeId;
							$storeInfo[$listId] = array(
								'id' => $listId,
								'storeid' => $storeId,
								'default_store' => $is_default_store,
								'default_site' => $is_default_site,
								'env' => $env,
								'interval' => $interval,
								'ttl' => $ttl,
								'priority' => $priority,
								'baseurl' => $baseurl );
						}

						// check custom list
						$custlist = Mage::getStoreConfig(self::STOREXML_WARMUP_CUSTLIST, $storeId);
						if (is_readable($custlist)) {
							$priority = Mage::getStoreConfig(self::STOREXML_WARMUP_CUSTLIST_PRIORITY, $storeId) + $orderAdjust;
							$custInterval = Mage::getStoreConfig(self::STOREXML_WARMUP_CUSTLIST_INTERVAL, $storeId);
							$listId = 'cust' . $storeId;
							$storeInfo[$listId] = array(
								'id' => $listId,
								'storeid' => $storeId,
								'env' => $env,
								'interval' => $custInterval,
								'ttl' => $ttl,
								'priority' => $priority,
								'baseurl' => $baseurl,
								'file' => $custlist	);
						}
					}
				}
			}
			else {
				$this->_conf[self::CFG_WARMUP] = array();
			}

			if (empty($storeInfo)) {
				if ( $this->_isDebug ) {
					$this->debugMesg('Cron warm up skipped due to configuration not enabled.') ;
				}
			}
			else {
				$load = sys_getloadavg() ;
				$limit = $this->_conf[self::CFG_WARMUP][self::CFG_WARMUP_LOAD_LIMIT] ;
				if ( $load[0] > $limit ) {
					if ( $this->_isDebug ) {
						$this->debugMesg('Cron warm up skipped due to load. Limit is ' . $limit . ', current load is ' . $load[0]) ;
					}
				}
				else {
					$this->_conf[self::CFG_WARMUP]['store'] = $storeInfo;
				}
			}
		}

		return $this->_conf[self::CFG_WARMUP];

	}

    public function isEsiBlock( $block )
    {
        $blockName = $block->getNameInLayout();
        $tag = null;
        $valueonly = 0;
        $blockType = null;

        $ref = $this->_conf[self::CFG_ESIBLOCK]['block'];
        if (isset($ref['bn'][$blockName])) {
            $tag = $ref['bn'][$blockName]['tag'];
            $valueonly = $ref['bn'][$blockName]['valueonly'];
        }
        else {
            foreach ($ref['bt'] as $bt => $bv) {
                if ($block instanceof $bt) {
                    $tag = $bv['tag'];
                    $valueonly = $bv['valueonly'];
                    $blockType = $bt;
                    break;
                }
            }
        }
        if ($tag == null) {
            return null;
        }
        else {
            $tagdata = $this->_conf[self::CFG_ESIBLOCK]['tag'][$tag];
            $bconf = array(
                'tag' => $tag,
                'cache-tag' => $tagdata['cache-tag'],
                'access' => $tagdata['access'],
                'valueonly' => $valueonly,
                'bn' => $blockName,
                'bt' => $blockType
                );
            return $bconf;
        }
    }

    public function getNoCacheConf( $name = '' )
    {
        return $this->getConf($name, self::CFG_NOCACHE) ;
    }

    public function getConf( $name, $type = '' )
    {
        if ( ($type == '' && ! isset($this->_conf[$name])) || ($type != '' && ! isset($this->_conf[$type])) ) {
            $this->_initConf($type) ;
        }

		// get store override, because store id may change after init
		if ($name == self::CFG_DIFFCUSTGRP) {
            $this->_conf[self::CFG_DIFFCUSTGRP] = Mage::getStoreConfig(self::STOREXML_DIFFCUSTGRP);
		}
		elseif ($name == self::CFG_PUBLICTTL) {
			$this->_conf[self::CFG_PUBLICTTL] = Mage::getStoreConfig(self::STOREXML_PUBLICTTL);
		}
		elseif ($name == self::CFG_PRIVATETTL) {
            $this->_conf[self::CFG_PRIVATETTL] = Mage::getStoreConfig(self::STOREXML_PRIVATETTL);
		}
		elseif ($name == self::CFG_TRACKLASTVIEWED) {
		    $this->_conf[self::CFG_TRACKLASTVIEWED] = Mage::getStoreConfig(self::STOREXML_TRACKLASTVIEWED);
		}

        if ( $type == '' )
            return $this->_conf[$name] ;
        else if ( $name == '' )
            return $this->_conf[$type] ;
        else
            return $this->_conf[$type][$name] ;
    }

    protected function _initConf( $type = '' )
    {
		$storeId = Mage::app()->getStore()->getId();
        if ( ! isset($this->_conf['defaultlm']) ) {
            $this->_conf['defaultlm'] = $this->_getConfigByPath(self::CFGXML_DEFAULTLM) ;
        }
        $pattern = "/[\s,]+/" ;

        switch ( $type ) {
            case self::CFG_ESIBLOCK:
                $this->_conf[self::CFG_ESIBLOCK] = array() ;
                $this->_conf[self::CFG_ESIBLOCK]['tag'] = $this->_getConfigByPath(self::CFGXML_ESIBLOCK) ;

                $custblocks = array();
                $custblocks['welcome'] = preg_split($pattern, $this->_conf['defaultlm']['donotcache']['welcome'], null, PREG_SPLIT_NO_EMPTY) ;
                $custblocks['toplinks'] = preg_split($pattern, $this->_conf['defaultlm']['donotcache']['toplinks'], null, PREG_SPLIT_NO_EMPTY) ;
                $custblocks['messages'] = preg_split($pattern, $this->_conf['defaultlm']['donotcache']['messages'], null, PREG_SPLIT_NO_EMPTY) ;

                $allblocks = array('bn' => array(), 'bt' => array());
                foreach ( $this->_conf[self::CFG_ESIBLOCK]['tag'] as $tag => $d ) {
                    $this->_conf[self::CFG_ESIBLOCK]['tag'][$tag]['cache-tag'] = Litespeed_Litemage_Helper_Esi::TAG_PREFIX_ESIBLOCK . $tag ;
                    $blocks = preg_split($pattern, $d['blocks'], null, PREG_SPLIT_NO_EMPTY) ;
                    if (!empty($custblocks[$tag])) {
                        $blocks = array_merge($blocks, $custblocks[$tag]);
                    }

                    foreach ( $blocks as $b ) {
                        $valueonly = 0;
                        if (substr($b, -2) == '$v') {
                            $valueonly = 1;
                            $b = substr($b, 0, -2);
                        }
                        $bc = array('tag' => $tag, 'valueonly' => $valueonly);
                        if (substr($b,0,2) == 'T:') {
                            $b = substr($b,2);
                            $allblocks['bt'][$b] = $bc;
                        }
                        else {
                            $allblocks['bn'][$b] = $bc;
                        }
                    }
                    if ( isset($d['purge_tags']) ) {
                        $pts = preg_split($pattern, $d['purge_tags'], null, PREG_SPLIT_NO_EMPTY) ;
                        if (!isset($d['purge_events']))
                            $this->_conf[self::CFG_ESIBLOCK]['tag'][$tag]['purge_events'] = array();
                        foreach ( $pts as $t ) {
                            if (isset($this->_conf[self::CFG_ESIBLOCK]['tag'][$t]['purge_events'])) {
                                $this->_conf[self::CFG_ESIBLOCK]['tag'][$tag]['purge_events'] =
                                    array_merge($this->_conf[self::CFG_ESIBLOCK]['tag'][$tag]['purge_events'],
                                            $this->_conf[self::CFG_ESIBLOCK]['tag'][$t]['purge_events']);
                            }
                        }

                    }

                }
                $this->_conf[self::CFG_ESIBLOCK]['block'] = $allblocks ;
                break ;

            case self::CFG_NOCACHE:
                $this->_conf[self::CFG_NOCACHE] = array() ;
                $default = $this->_conf['defaultlm']['default'] ;
                $cust = $this->_conf['defaultlm']['donotcache'] ;

                $this->_conf[self::CFG_NOCACHE][self::CFG_CACHE_ROUTE] = array_merge(preg_split($pattern, $default['cache_routes'], null, PREG_SPLIT_NO_EMPTY),
                        preg_split($pattern, $cust['cache_routes'], null, PREG_SPLIT_NO_EMPTY));
                $this->_conf[self::CFG_NOCACHE][self::CFG_NOCACHE_ROUTE] = array_merge(preg_split($pattern, $default['nocache_subroutes'], null, PREG_SPLIT_NO_EMPTY),
                        preg_split($pattern, $default['nocache_subroutes'], null, PREG_SPLIT_NO_EMPTY));
                $this->_conf[self::CFG_NOCACHE][self::CFG_FULLCACHE_ROUTE] = preg_split($pattern, $default['fullcache_routes'], null, PREG_SPLIT_NO_EMPTY) ;
                $this->_conf[self::CFG_NOCACHE][self::CFG_NOCACHE_VAR] = preg_split($pattern, $cust['vars'], null, PREG_SPLIT_NO_EMPTY) ;
                $this->_conf[self::CFG_NOCACHE][self::CFG_NOCACHE_URL] = preg_split($pattern, $cust['urls'], null, PREG_SPLIT_NO_EMPTY) ;
                break ;

            case self::CFG_WARMUP:
                $warmup = $this->_conf['defaultlm']['warmup'] ;
                $this->_conf[self::CFG_WARMUP] = array(
                    self::CFG_WARMUP_EANBLED => $warmup[self::CFG_WARMUP_EANBLED],
                    self::CFG_WARMUP_LOAD_LIMIT => $warmup[self::CFG_WARMUP_LOAD_LIMIT],
					self::CFG_WARMUP_THREAD_LIMIT => $warmup[self::CFG_WARMUP_THREAD_LIMIT],
                    self::CFG_WARMUP_MAXTIME => $warmup[self::CFG_WARMUP_MAXTIME],
                    self::CFG_WARMUP_MULTICURR => $warmup[self::CFG_WARMUP_MULTICURR]);
                break ;

            default:
                $general = $this->_conf['defaultlm']['general'] ;
                $this->_conf[self::CFG_ENABLED] = $general[self::CFG_ENABLED] ;

                $test = $this->_conf['defaultlm']['test'] ;
                $this->_conf[self::CFG_DEBUGON] = $test[self::CFG_DEBUGON] ;
                $this->_isDebug = $test[self::CFG_DEBUGON] ; // required by cron, needs to be set even when module disabled.

                if ( ! $general[self::CFG_ENABLED] )
                    break ;

				// get store override
                $this->_conf[self::CFG_TRACKLASTVIEWED] = Mage::getStoreConfig(self::STOREXML_TRACKLASTVIEWED, $storeId);
                $this->_conf[self::CFG_DIFFCUSTGRP] = Mage::getStoreConfig(self::STOREXML_DIFFCUSTGRP, $storeId);
                $this->_conf[self::CFG_PUBLICTTL] = Mage::getStoreConfig(self::STOREXML_PUBLICTTL, $storeId);
                $this->_conf[self::CFG_PRIVATETTL] = Mage::getStoreConfig(self::STOREXML_PRIVATETTL, $storeId);

                $adminIps = trim($general[self::CFG_ADMINIPS]);
                $this->_conf[self::CFG_ADMINIPS] = $adminIps ? preg_split($pattern, $adminIps, null, PREG_SPLIT_NO_EMPTY) : '' ;
                if ($general['alt_esi_syntax']) {
                    $this->_esiTag = array('include' => 'esi_include', 'inline' => 'esi_inline', 'remove' => 'esi_remove');
                }
                else {
                    $this->_esiTag = array('include' => 'esi:include', 'inline' => 'esi:inline', 'remove' => 'esi:remove');
                }
                $allowedIps = trim($test[self::CFG_ALLOWEDIPS]) ;
                $this->_conf[self::CFG_ALLOWEDIPS] = $allowedIps ? preg_split($pattern, $allowedIps, null, PREG_SPLIT_NO_EMPTY) : '' ;
        }
    }

    protected function _getConfigByPath( $xmlPath )
    {
		$node = Mage::getConfig()->getNode($xmlPath) ;
        if ( ! $node )
            Mage::throwException('Litemage missing config in xml path ' . $xmlPath) ;
        return $node->asCanonicalArray() ;
    }

    public function debugMesg( $mesg )
    {
        if ( $this->_isDebug ) {
            $mesg = str_replace("\n", ("\n" . $this->_debugTag . '  '), $mesg);
            Mage::log($this->_debugTag . ' '. $mesg ) ;
        }
    }

}
