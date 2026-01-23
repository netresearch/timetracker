<?php

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Entity\Entry;
use App\Entity\User;
use App\Event\EntryEvent;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Util\RequestEntityHelper;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\Required;

final class DeleteEntryAction extends BaseTrackingController
{
    private ?EventDispatcherInterface $eventDispatcher = null;

    #[Required]
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @throws BadRequestException When request parameters are invalid
     */
    #[Route(path: '/tracking/delete', name: 'timetracking_delete_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $currentUser,
    ): Response|JsonResponse|Error {
        $entryId = RequestEntityHelper::id($request, 'id');
        if ($entryId > 0) {
            $doctrine = $this->managerRegistry;
            $entry = RequestEntityHelper::findById($doctrine, Entry::class, $entryId);

            if (!$entry instanceof Entry) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            $day = $entry->getDay()->format('Y-m-d');

            // Dispatch event before removal (subscriber handles Jira worklog deletion)
            if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                $this->eventDispatcher->dispatch(new EntryEvent($entry), EntryEvent::DELETED);
            }

            $manager = $doctrine->getManager();
            $manager->remove($entry);
            $manager->flush();

            $this->calculateClasses($currentUser->getId() ?? 0, $day);
        }

        return new JsonResponse(['success' => true]);
    }
}
