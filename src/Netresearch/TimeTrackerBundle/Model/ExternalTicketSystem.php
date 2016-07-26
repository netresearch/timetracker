<?php
/**
 * @author      Michael Lühr <michael.luehr@netresearch.de>
 * @category    Netresearch
 * @package     ${MODULENAME}
 * @copyright   Copyright (c) 2013 Netresearch GmbH & Co. KG (http://www.netresearch.de)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */


namespace Netresearch\TimeTrackerBundle\Model;


class ExternalTicketSystem
{
    protected $url = null;

    protected $login = null;

    protected $password = null;

    protected $tickets = array();

    public function __construct($url = null, $login = null, $password = null)
    {
        $this->setUrl($url);
        $this->setLogin($login);
        $this->setPassword($password);
    }

    /**
     * @param null $login
     */
    public function setLogin($login)
    {
        $this->login = $login;
    }

    /**
     * @return null
     */
    public function getLogin()
    {
        return $this->login;
    }

    /**
     * @param null $password
     *
     * @return $this
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @return null
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param null $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return null
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param null $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return null
     */
    public function getId()
    {
        return $this->id;
    }

    public function addTicket($ticketNr)
    {
        if (!in_array($ticketNr, $this->getTickets())) {
            $this->tickets[] = $ticketNr;
        }

        return $this;
    }

    public function getTickets()
    {
        return $this->tickets;
    }
}
