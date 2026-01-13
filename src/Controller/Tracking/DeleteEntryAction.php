<?php

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Entity\Entry;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Util\RequestEntityHelper;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DeleteEntryAction extends BaseTrackingController
{
    /**
     * @throws BadRequestException When request parameters are invalid
     * @throws Exception           When database operations fail
     * @throws JiraApiException    When Jira API operations fail
     * @throws Exception           When entry processing or deletion fails
     */
    #[Route(path: '/tracking/delete', name: 'timetracking_delete_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $currentUser,
    ): Response|JsonResponse|Error {
        $alert = null;

        $entryId = RequestEntityHelper::id($request, 'id');
        if ($entryId > 0) {
            $doctrine = $this->managerRegistry;
            $entry = RequestEntityHelper::findById($doctrine, Entry::class, $entryId);

            if (!$entry instanceof Entry) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            try {
                $this->deleteJiraWorklog($entry);
            } catch (JiraApiUnauthorizedException $e) {
                return new Error($e->getMessage(), \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN, $e->getRedirectUrl());
            } catch (JiraApiException $e) {
                $alert = $e->getMessage() . '<br />' .
                    $this->translator->trans('Dataset was modified in Timetracker anyway');
            }

            $day = $entry->getDay()->format('Y-m-d');
            $manager = $doctrine->getManager();
            $manager->remove($entry);
            $manager->flush();

            $this->calculateClasses($currentUser->getId() ?? 0, $day);
        }

        return new JsonResponse(['success' => true, 'alert' => $alert]);
    }
}
