<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\EventListener;

use \Symfony\Component\HttpKernel\Event\FilterControllerEvent as FilterControllerEvent;
use \Symfony\Component\HttpKernel\HttpKernelInterface as HttpKernelInterface;

/**
 * Class PreExecute
 * @package App\EventListener
 */
class PreExecute
{
    public function onKernelController(FilterControllerEvent $event)
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

