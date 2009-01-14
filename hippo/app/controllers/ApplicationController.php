<?php
/**
 * @category Horde
 * @package  Hippo
 */

/**
 * @category Horde
 * @package  Hippo
 */
class Hippo_ApplicationController extends Horde_Controller_Base
{
    /**
     */
    protected function _initializeApplication()
    {
        $GLOBALS['conf']['sql']['adapter'] = $GLOBALS['conf']['sql']['phptype'] == 'mysqli' ? 'mysqli' : 'pdo_' . $GLOBALS['conf']['sql']['phptype'];
        $this->db = Horde_Db_Adapter::factory($GLOBALS['conf']['sql']);
    }

}
