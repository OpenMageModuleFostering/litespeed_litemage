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


class Litespeed_Litemage_Model_Layout extends Mage_Core_Model_Layout
{
    /**
     * Class constructor
     *
     * @param array $data
     */

    //protected $_isDebug;

    public function __construct()
    {
        $this->_elementClass = Mage::getConfig()->getModelClassName('core/layout_element');
        $this->setXml(simplexml_load_string('<layout/>', $this->_elementClass));
        $this->_update = Mage::getModel('litemage/layout_update');
    }

    public function getBlock($name)
    {
        if (!isset($this->_blocks[$name])) {
            $dummyblocks = array('root', 'head');
            if (in_array($name, $dummyblocks)) {
                $dummy = new Varien_Object();
                return $dummy;
            }
            return null;
        }
        return $this->_blocks[$name];
    }

    public function resetBlocks()
    {
        $this->_output = array();
        $this->_blocks = array();
    }

    /**
     * Create layout blocks hierarchy from layout xml configuration
     *
     * @param Mage_Core_Layout_Element|null $parent
     */
    public function generateBlocks($parent=null)
    {
        if (empty($parent)) {
            $root = $this->addBlock('page/html', 'esiroot');// dummy root
            $parent = $this->getNode();
            foreach ($parent as $node) {
                $node['parent'] = 'esiroot';
            }
        }
        parent::generateBlocks($parent);
    }

    public function getOutputBlock($name_alias)
    {
        $block = null;
        $mesg = '';

        if (isset($this->_blocks['esiroot']) && ($block = $this->_blocks['esiroot']->getChild($name_alias))) {
            // as alias
            if ( ($name = $block->getNameInLayout()) != $name_alias ) {
                if ($this->_blocks[$name] != $block) {
                    $mesg = 'block name in layout is not unique, please check layout xml for block name ' . $name;
                }
            }

        }
        elseif (isset($this->_blocks[$name_alias])) {
            $block = $this->_blocks[$name_alias]; // dynamic block
        }
        else {
            $mesg = 'failed to find the block by alias or name';
        }
        if ($mesg != '') {
            Mage::helper('litemage/data')->debugMesg('getOutputBlock ' . $name_alias . ' ALERT: ' . $mesg);
        }
        return $block;
    }

    // override getOutput, as the output block maybe go by alias
    public function getOutput()
    {
        $out = '';
        if (!empty($this->_output)) {
            foreach ($this->_output as $callback) {
                if ($block = $this->getOutputBlock($callback[0])) {
                    $out .= $block->$callback[1]();
                }
            }
        }

        return $out;
    }

}

