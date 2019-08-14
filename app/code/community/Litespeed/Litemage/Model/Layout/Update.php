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


class Litespeed_Litemage_Model_Layout_Update extends Mage_Core_Model_Layout_Update
{
    protected $_esiBlockName;
    protected $_storeId;
    protected $_filtered = false;
    protected $_blockNames = array();
    protected $_peerFullXml;
    protected $_isDebug;

    public function getPackageLayout()
    {
        if (empty($this->_packageLayout)) {
            $this->fetchFileLayoutUpdates();
        }
        return $this->_packageLayout;
    }

    public function setStoreId($storeId)
    {
        $this->_storeId = $storeId;
        $this->_isDebug = Mage::helper('litemage/data')->isDebug() ;
    }

    public function getEsiBlockName()
    {
        return $this->_esiBlockName;
    }

    public function setBlockNames($blockNames)
    {
        $this->_blockNames = $blockNames;
        $this->_esiBlockName = $blockNames[0];
        $this->_cacheId = null;
        $this->_filtered = false;
        $this->resetHandles();
        $this->resetUpdates();
    }

    public function getBlockNames()
    {
        return $this->_blockNames;
    }

    public function importLayoutUpdate($blockNames, $blockHandles, $layout )
    {
        $this->setBlockNames($blockNames);
        if ($this->_peerFullXml == null)
            $this->_peerFullXml = $layout->getUpdate()->asSimplexml() ;

        $this->_filterUpdates($this->_peerFullXml);
        $this->addHandle($blockHandles);
        $this->saveCache();
        return $this->_blockNames;
    }

    protected function _filterUpdates($layoutUpdateXml)
    {
        $this->resetUpdates();
        $blockUsed = array();

        foreach ($this->_blockNames as $blockName) {
            $xpath = '//*[@name="' . $blockName . '"]';
            $nameUsed = false;
            if ($els = $layoutUpdateXml->xpath($xpath)) {
                foreach ($els as $el) {
                    if (!$el->getAttribute('litemage_used')) {
                        $this->addUpdate($el->asNiceXml());
                        $nameUsed = true;
                        $this->_markNodeUsed($el);
                    }
                }
            }
            if ($nameUsed)
                $blockUsed[] = $blockName;
        }

        // check if children blocks being removed
        $removeNodes = $layoutUpdateXml->xpath("//remove");

        if (is_array($removeNodes)) {
            $xml = $this->asSimplexml();

            foreach ($removeNodes as $removeNode) {
                $attributes = $removeNode->attributes();
                $blockName = (string)$attributes->name;
                if ($blockName) {
                    $ignoreNodes = $xml->xpath("//block[@name='".$blockName."']");
                    if (is_array($ignoreNodes) && count($ignoreNodes) > 0) {
                        $this->addUpdate($removeNode->asNiceXml());
                    }
                }
            }
        }

        $this->_blockNames = $blockUsed;
        $this->_filtered = true;
    }

    protected function _markNodeUsed($node)
    {
        $node->addAttribute('litemage_used', 'esi');
        $children = $node->children();
        foreach ($children as $child) {
            $this->_markNodeUsed($child);
        }
    }

    /**
     * Get cache id
     *
     * @return string
     */
    public function getCacheId()
    {
        if (!$this->_cacheId) {
            $tags = $this->getHandles();
            if (count($tags) > 1) {
                sort($tags);
            }
            $tags[] = 'LITEMAGE_ESI_' . $this->_esiBlockName;

            $this->_cacheId = 'LAYOUT_' . $this->_storeId . md5(join('__', $tags));
        }
        return $this->_cacheId;
    }

    /*
     * @return -1: no layout cache allowed, 0: nocache, 1: has cache
     */

    public function loadEsiBlockCache($blockName, $handles)
    {
        if (!Mage::app()->useCache('layout')) {
            return -1;
        }
        //reset internals
        $this->setBlockNames(array($blockName));
        $this->addHandle($handles);

        if ($this->loadCache())
            return 1;
        else
            return 0;
    }

    public function loadCache()
    {
        if (!Mage::app()->useCache('layout')) {
            return false;
        }

        if (!$result = Mage::app()->loadCache($this->getCacheId())) {
            return false;
        }

        $pos = strpos($result, "\n");
        $filteredBlocks = substr($result, 0, $pos);
        $this->_filtered = true;
        if ($this->_isDebug) {
            Mage::helper('litemage/data')->debugMesg('LU_Load B=' . $filteredBlocks . ' H=' . join(',',$this->getHandles()) . ' ID=' . substr($this->getCacheId(), 8, 10));
        }
        $this->_blockNames = explode(',', $filteredBlocks);
        $this->addUpdate(substr($result, $pos+1));

        return true;
    }

    public function saveCache()
    {
        if (!$this->_filtered) {
            $this->_filterUpdates($this->asSimplexml());
        }

        if (!Mage::app()->useCache('layout')) {
            return false;
        }

        $tags = $this->getHandles();
        $tags[] = Mage_Core_Model_Layout_Update::LAYOUT_GENERAL_CACHE_TAG;
        $tags[] = Litespeed_Litemage_Helper_Data::LITEMAGE_GENERAL_CACHE_TAG;
        $firstLine = join(',', $this->_blockNames);
        if ($this->_isDebug) {
            Mage::helper('litemage/data')->debugMesg('LU_Save B=' . $firstLine . ' H=' . join(',',$this->getHandles()) . ' ID=' . substr($this->getCacheId(), 8, 10));
        }

        $content = $firstLine . "\n" . $this->asString();
        return Mage::app()->saveCache($content, $this->getCacheId(), $tags, null);
    }

}
