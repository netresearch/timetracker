<?php
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
namespace Netresearch\TimeTrackerBundle\Model;

/**
 * Class Response
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Model
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class Response extends \Symfony\Component\HttpFoundation\Response
{

    /**
     * Add addtional headers before sending an ajax reply to the client
     *
     * @return void
     */
    public function send()
    {
        $this->headers->set('Access-Control-Allow-Origin', '*');
        $this->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $this->headers->set('Access-Control-Max-Age', 3600);

        parent::send();
    }
}
