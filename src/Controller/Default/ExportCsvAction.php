<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Model\Response;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\Request;

final class ExportCsvAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/export/{days}', name: '_export_attr', defaults: ['days' => 10000], methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 10000;

        $user = $this->managerRegistry
            ->getRepository(User::class)
            ->find($this->getUserId($request));
        if (!$user instanceof User) {
            return $this->getFailedLoginResponse();
        }

        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $entries = $objectRepository->findByRecentDaysOfUser($user, $days);

        $content = $this->renderView('export.csv.twig', [
            'entries' => $entries,
            'labels' => null,
        ]);

        $filename = strtolower(str_replace(' ', '-', (string) $user->getUsername())).'.csv';

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename='.$filename);
        $response->setContent(chr(239).chr(187).chr(191).$content);

        return $response;
    }
}


