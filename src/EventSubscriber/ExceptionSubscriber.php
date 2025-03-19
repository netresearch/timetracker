<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionSubscriber implements EventSubscriberInterface
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => [
                ['logException', 0],
            ],
        ];
    }

    public function logException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        $this->logger->error('Uncaught PHP Exception ' . get_class($exception) . ': "' . $exception->getMessage() . '" at ' . $exception->getFile() . ' line ' . $exception->getLine(), [
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ],
            'request' => [
                'method' => $request->getMethod(),
                'uri' => $request->getUri(),
                'client_ip' => $request->getClientIp(),
                'headers' => $request->headers->all(),
            ]
        ]);
    }
}
