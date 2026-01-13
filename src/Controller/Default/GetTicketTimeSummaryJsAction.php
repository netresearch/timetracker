<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

final class GetTicketTimeSummaryJsAction extends BaseController
{
    #[Route(path: '/scripts/timeSummaryForJira', name: '_getTicketTimeSummaryJs_attr', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $ttUrl = $this->generateUrl('_start', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $inline = sprintf('%s%s', $ttUrl, 'getTicketTimeSummary/');

        return new JsonResponse($inline);
    }
}
