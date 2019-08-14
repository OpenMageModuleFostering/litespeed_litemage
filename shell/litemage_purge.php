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
 * @copyright  Copyright (c) 2016 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

require_once 'abstract.php';

class Litespeed_Litemage_Shell_Purge extends Mage_Shell_Abstract
{
	protected $_modules;
	protected $_modifiedFiles		= array();
	protected $_fileReplacePatterns	= array();
	public static $_errors			= array();
	
	
	public function run()
	{
		$helper = Mage::helper('litemage/data');
		if (!$helper->getConf($helper::CFG_ENABLED)) {
			static::log("Abort - litemage module is not enabled.");
			return;
		}
		$params = $this->_inputParams();
		if ($params == null) {
			return;
		}
		
		$options = array(
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_TIMEOUT => 180,
			CURLOPT_USERAGENT => 'litemage_walker'
		) ;

		$server_ip = $helper->getConf($helper::CFG_WARMUP_SERVER_IP, $helper::CFG_WARMUP);

		$base = Mage::getBaseUrl() ;
		if ($pos = strpos($base, 'litemage_purge')) {
			$base = substr($base, 0, $pos);
		}
		if ($server_ip) {
			$pattern = "/:\/\/([^\/^:]+)(\/|:)?/";
			if (preg_match($pattern, $base, $m)) {
				$domain = $m[1];
				$pos = strpos($base, $domain);
				$base = substr($base, 0, $pos) . $server_ip . substr($base, $pos + strlen($domain));
				$options[CURLOPT_HTTPHEADER] = array("Host: $domain");
			}
		}
		
		$url = $base . 'litemage/admin/shell/' . implode('/', $params);
		
		try {
			static::log("purge url is " . $url);
			$client = new Varien_Http_Adapter_Curl() ;
			$urls = array($url);
			$result = $client->multiRequest($urls, $options) ;
			static::log('result back is ' . print_r($result, true));
		} catch ( Exception $e ) {
			static::log('Error when send purge url : ' . $e->getMessage()) ;
		}
	}
	
	protected function _cleanInput($type, $ids)
	{
		$clean = array();
		if ($ids !== true) {
			$pattern = "/[\s,]+/";
			$check	 = preg_split($pattern, $ids, null, PREG_SPLIT_NO_EMPTY);
			foreach ($check as $id) {
				if ($type == 'tags' || strval(intval($id)) === $id) {
					$clean[] = $id;
				}
				else {
					static::log("found invalid parameters $id for $type", true);
				}
			}
		}
		return $clean;
	}

	protected function _inputParams()
	{
		$params = array();
		if ($this->getArg('all')) {
			$params[] = 'all';
			$params[] = 1;
		}
		else {
			$types = array('products', 'cats', 'stores', 'tags');
			foreach ($types as $type) {
				if ($value = $this->getArg($type)) {
					$ids = $this->_cleanInput($type, $value);
					if (empty($ids)) {
						static::log("No value provided for param $type", true);
					}
					else {
						$params[] = $type;
						$params[] = implode(',', $ids);
					}
				}
			}
		}
		if (empty($params)) {
			echo "Abort - No valid parameters found.\n\n";
			echo $this->usageHelp();
			return null;
		}
		return $params;
	}

	/**
	 * Retrieve Usage Help Message
	 * 
	 * @return void
	 */
	public function usageHelp()
	{
		return <<<USAGE
Usage:  php litemage_purge.php -- [options]
		
  --products <product IDs>    Comma delimited product IDs
  --cats <category_IDs>       Comma delimited category IDs
  --stores <store_IDs>        Comma delimited store IDs
  --tags <raw tags>           Comma delimited raw tags. You need to understand LiteMage internals to use this.
  --all                       Flush all cached files in LiteSpeed Web Server.	  

You can use this tool to flush LiteMage cache from the command line.

USAGE;
	}
	
	/**
	 * Write the given message to a log file and to screen.
	 *
	 * @param  mixed $message Message to log
	 * @param  boolean $isError If true, log the error for summary.
	 * @return void
	 */
	public static function log( $message, $isError=false )
	{
		// Record errors to repeat in the summary.
		if( $isError === true ) {
			static::$_errors[] = $message;
			
			$message = 'ERROR: ' . $message;
		}
		
		Mage::log( $message, null, 'litemage_shell.log', true );
		
		if( !is_string( $message ) ) {
			$message = print_r( $message, 1 );
		}
		
		echo $message . "\n";
	}
}

$litemageShell = new Litespeed_Litemage_Shell_Purge();
$litemageShell->run();


