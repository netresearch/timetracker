<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Model\Response;
use App\Repository\EntryRepository;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;
use function chr;

final class ExportCsvAction extends BaseController
{
    /**
     * @throws Exception
     */
    #[Route(path: '/export/{days}', name: '_export_attr', defaults: ['days' => 10000], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): Response
    {
        if (!$user instanceof User) {
            return $this->getFailedLoginResponse();
        }

        $days = $request->attributes->has('days') && is_numeric($request->attributes->get('days'))
            ? (int) $request->attributes->get('days')
            : 10000;

        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
        $entries = $objectRepository->findByRecentDaysOfUser($user, $days);

        $content = $this->renderView('export.csv.twig', [
            'entries' => $entries,
            'labels' => null,
        ]);

        $filename = strtolower(str_replace(' ', '-', (string) $user->getUsername())) . '.csv';

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename);
        $response->setContent(chr(239) . chr(187) . chr(191) . $content);

        return $response;
    }
}
