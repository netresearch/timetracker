<?php

namespace Netresearch\TimeTrackerBundle\Model;

class Response extends \Symfony\Component\HttpFoundation\Response
{

    public function send()
    {
        $this->headers->set('Access-Control-Allow-Origin', '*');
        $this->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $this->headers->set('Access-Control-Max-Age', 3600);

        parent::send();
    }
}