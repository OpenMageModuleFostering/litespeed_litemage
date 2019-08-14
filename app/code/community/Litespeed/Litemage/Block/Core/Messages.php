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


class Litespeed_Litemage_Block_Core_Messages extends Mage_Core_Block_Messages
{

    protected $_isEsiInject = false ;
    protected $_hasSaved = false ;
    protected $_outputCall ;

    /*Override Mage_Core_Block_Abstract:getMessagesBlock */
    public function getMessagesBlock()
    {
        return $this;
    }

    public function initByPeer( $peer, $esiHtml )
    {
        $this->_isEsiInject = true ;
        $this->setData('esiHtml', $esiHtml) ;
        $peer->setData('litemageInjected', 1);
        $this->_layout = $peer->_layout;
        if ( $peer instanceof Mage_Core_Block_Messages ) {
            $this->_messagesBlock = $peer ;
        }
        else {
            $this->_messagesBlock = $this->_layout->getMessagesBlock();
        }
        $this->_nameInLayout = $peer->_nameInLayout ;
        $this->_alias = $peer->_alias;
        $parent = $peer->getParentBlock();
        if ($parent != null) {
            $parent->setChild($peer->_alias, $this) ;
        }
        $this->_layout->setBlock($this->_nameInLayout, $this) ;

        if (Mage::registry('LITEMAGE_SHOWHOLES')) {
            $tip = 'LiteMage ESI message block ' . $this->_nameInLayout;
            $wrapperBegin = '<div style="position:relative;border:1px dotted red;background-color:rgba(255,255,0,0.3);margin:2px;padding:18px 2px 2px 2px;zoom:1;" title="' . $tip
                    . '"><div style="position: absolute; left: 0px; top: 0px; padding: 2px 5px; color: green; font-style: normal; font-variant: normal; font-weight: normal; font-size: 11px; line-height: normal; font-family: Arial; z-index: 998; text-align: left !important;">' . $tip . '</div>';

            $this->setData('lmwrapperBegin', $wrapperBegin);
            $this->setData('lmwrapperEnd', '</div>');
        }
    }

    public function initByEsi( $storageNames, $outputCall, $peer )
    {
        $this->_layout = $peer->_layout;
        if ( $peer instanceof Mage_Core_Block_Messages ) {
            $this->_messagesBlock = $peer ;
        }
        else {
            $this->_messagesBlock = $this->_layout->getMessagesBlock();
            if ($tmpl = $peer->getTemplate()) {
                $this->setTemplate($tmpl);
            }
        }

        $this->_usedStorageTypes = $storageNames ;
        $this->_nameInLayout = $peer->getNameInLayout() ;
        $this->_outputCall = $outputCall ;
        $this->_alias = $peer->_alias;
        $parent = $peer->getParentBlock();
        if ($parent != null) {
            $parent->setChild($peer->_alias, $this) ;
        }
        $this->_layout->setBlock($this->_nameInLayout, $this) ;
    }

    public function renderView()
    {
        if ( ! $this->_hasSaved ) {
            $messages = $this->getMessages() ;
            if ( count($messages) > 0 ) {
                // maybe multiple places point to same mesg block if use getMessageBlock(), use the name of the this->_messagesBlock, not itself
                Mage::getSingleton('litemage/session')->saveMessages($this->_messagesBlock->_nameInLayout, $messages) ;
                $this->getMessageCollection()->clear();
            }

            $this->_hasSaved = true ;
        }
        if ($this->_isEsiInject) {
            $esiHtml = $this->getData('esiHtml');
            Mage::helper('litemage/data')->debugMesg('Injected ESI Message block ' . $this->_nameInLayout . ' ' . $esiHtml) ;

            if (Mage::registry('LITEMAGE_SHOWHOLES'))
                return $this->getData('lmwrapperBegin') . $esiHtml . $this->getData('lmwrapperEnd') ;
            else
                return $esiHtml;
        }
        else
            return parent::renderView() ;
    }

    public function getGroupedHtml()
    {
        if ( $this->_isEsiInject ) {
            $this->_adjustEsiUrl('getGroupedHtml') ;
            return $this->renderView() ;
        }
        else {
            $this->_loadMessages() ;
            return $this->_messagesBlock->getGroupedHtml() ;
        }
    }

    public function getHtml( $type = null )
    {
        if ( $this->_isEsiInject ) {
            $this->_adjustEsiUrl('getHtml', $type) ;
            return $this->renderView() ;
        }
        else {
            $this->_loadMessages($type) ;
            return $this->_messagesBlock->getHtml($type) ;
        }
    }

    public function _prepareLayout()
    {
        // do nothing, as data already carried over
        return $this ;
    }

    protected function _loadCache()
    {
        if ( $this->_isEsiInject && $this->hasData('esiHtml') ) {
            $html = $this->getData('esiHtml') ;
            if ( strpos($html, '/getMessage/') ) {
                Mage::helper('litemage/data')->debugMesg('Injected ESI Message block ' . $this->_nameInLayout . ' ' . $html) ;

                if (Mage::registry('LITEMAGE_SHOWHOLES'))
                    return $this->getData('lmwrapperBegin') . $html . $this->getData('lmwrapperEnd') ;
                else
                    return $html ;
            }
        }
        return false ;
    }

    protected function _toHtml()
    {
        if ( $this->_isEsiInject ) {
            $this->_adjustEsiUrl('_toHtml') ;
            return $this->renderView() ;
        }
        else {

            if ($this->getTemplate()) {
                $this->_loadMessages();
                $this->setScriptPath(Mage::getBaseDir('design'));
                $html = $this->fetchView($this->getTemplateFile());
                return $html;
            }

            // default is getGroupedHtml
            if ( strncmp($this->_outputCall, 'getHtml', 7) == 0 ) {
                $type = ($this->_outputCall == 'getHtml') ? null : substr($this->_outputCall, 7) ;
                return $this->getHtml($type) ;
            }
            else {
                return $this->getGroupedHtml() ;
            }
        }
    }


    protected function _adjustEsiUrl( $caller, $type = null )
    {
        $esiHtml = $this->getData('esiHtml') ;

        if ( strpos($esiHtml, '/getBlock/') ) {
            $types = join(',', $this->_getStorageTypes());
            $param = array( $types, $caller . $type ) ;
            $param1 = str_replace('/session', '--', $param) ;
            $param1 = str_replace('/', '-', $param1) ;
            $replaced = '/getMessage/st/' . $param1[0] . '/call/' . $param1[1] . '/' ;

            $esiHtml = str_replace('/getBlock/', $replaced, $esiHtml) ;
            $this->setData('esiHtml', $esiHtml) ;
            Mage::helper('litemage/esi')->setEsiBlockHtml($this->_nameInLayout, $esiHtml);
        }
    }

    protected function _loadMessages( $type = null )
    {
        $session = Mage::getSingleton('litemage/session') ;
        if ( ($savedMessages = $session->loadMessages($this->_messagesBlock->_nameInLayout)) != null ) {
            foreach ( $savedMessages as $savedMessage ) {
                $this->addMessage($savedMessage) ;
            }
        }

        $types = ($type == null) ? $this->_usedStorageTypes : (is_array($type) ? $type : array( $type )) ;

        foreach ( $types as $storageName ) {
            if ( ($storage = Mage::getSingleton($storageName)) != null ) {
                $this->addMessages($storage->getMessages(true)) ;
                $this->setEscapeMessageFlag($storage->getEscapeMessages(true)) ;
            }
        }
    }

    public function setEscapeMessageFlag( $flag )
    {
        $this->_messagesBlock->setEscapeMessageFlag($flag) ;
        return $this ;
    }

    /**
     * Set messages collection
     *
     * @param   Mage_Core_Model_Message_Collection $messages
     * @return  Mage_Core_Block_Messages
     */
    public function setMessages( Mage_Core_Model_Message_Collection $messages )
    {
        $this->_messagesBlock->setMessages($messages) ;
        return $this ;
    }

    /**
     * Add messages to display
     *
     * @param Mage_Core_Model_Message_Collection $messages
     * @return Mage_Core_Block_Messages
     */
    public function addMessages( Mage_Core_Model_Message_Collection $messages )
    {
        if ( $messages->count() > 0 ) {
            $this->_messagesBlock->addMessages($messages) ;
        }
        return $this ;
    }

    /**
     * Retrieve messages collection
     *
     * @return Mage_Core_Model_Message_Collection
     */
    public function getMessageCollection()
    {
        return $this->_messagesBlock->getMessageCollection() ;
    }

    /**
     * Adding new message to message collection
     *
     * @param   Mage_Core_Model_Message_Abstract $message
     * @return  Mage_Core_Block_Messages
     */
    public function addMessage( Mage_Core_Model_Message_Abstract $message )
    {
        $this->_messagesBlock->addMessage($message) ;
        return $this ;
    }

    /**
     * Retrieve messages array by message type
     *
     * @param   string $type
     * @return  array
     */
    public function getMessages( $type = null )
    {
        return $this->_messagesBlock->getMessages($type) ;
    }

    /**
     * Set messages first level html tag name for output messages as html
     *
     * @param string $tagName
     */
    public function setMessagesFirstLevelTagName( $tagName )
    {
        $this->_messagesBlock->setMessagesFirstLevelTagName($tagName) ;
    }

    /**
     * Set messages first level html tag name for output messages as html
     *
     * @param string $tagName
     */
    public function setMessagesSecondLevelTagName( $tagName )
    {
        $this->_messagesBlock->setMessagesSecondLevelTagName($tagName) ;
    }

    /**
     * Get cache key informative items
     *
     * @return array
     */
    public function getCacheKeyInfo()
    {
        return $this->_messagesBlock->getCacheKeyInfo() ;
    }

    /**
     * Add used storage type
     *
     * @param string $type
     */
    public function addStorageType( $type )
    {
        $this->_messagesBlock->addStorageType($type) ;
        if ( ! in_array($type, $this->_usedStorageTypes) )
            $this->_usedStorageTypes[] = $type ;
    }

    protected function _getStorageTypes()
    {
        // it's possible, messageblock already replaced with esi one, when using layout->getMessageBlock
        $types = array_merge($this->_usedStorageTypes, $this->_messagesBlock->_usedStorageTypes);
        if ( ($this->_messagesBlock instanceof Litespeed_Litemage_Block_Core_Messages) && ($this->_messagesBlock->_messagesBlock != null)) {
            $types = array_merge($types, $this->_messagesBlock->_messagesBlock->_usedStorageTypes);
        }
        return array_unique($types);
    }

}
