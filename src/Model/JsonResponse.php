<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

/**
 * Netresearch Timetracker
 *
 * PHP version 5
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Model
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
namespace App\Model;

/**
 * JSON response
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Model
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class JsonResponse extends Response
{
    public function setContent($content)
    {
        return parent::setContent(json_encode($content));
    }

    /**
     * Add additional headers before sending an JSON reply to the client
     */
    public function send(): \App\Model\Response
    {
        $this->headers->set('Content-Type', 'application/json');
        parent::send();
        return $this;
    }
}
