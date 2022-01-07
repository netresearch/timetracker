<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ControllerEvent;
use \Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Class PreExecute
 * @package App\EventListener
 */
class PreExecute
{
    public function onKernelController(ControllerEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()) {
            $controllers = $event->getController();
            if (is_array($controllers)) {
                $controller = $controllers[0];

                if (is_object($controller) && method_exists($controller, 'preExecute')) {
                    $controller->preExecute($event->getRequest());
                }
            }
        }
    }
}

