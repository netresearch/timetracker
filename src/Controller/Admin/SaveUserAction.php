<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\UserSaveDto;
use App\Entity\Team;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

use function sprintf;

final class SaveUserAction extends BaseController
{
    /**
     * @throws \InvalidArgumentException When team IDs are invalid or missing teams are found
     * @throws \UnexpectedValueException When user data mapping fails or validation errors occur
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/user/save', name: 'saveUser_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] UserSaveDto $userSaveDto, ObjectMapperInterface $objectMapper): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        $user = 0 !== $userSaveDto->id ? $objectRepository->find($userSaveDto->id) : new User();
        if (!$user instanceof User) {
            return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        // Validation is now handled by the DTO with MapRequestPayload
        // Uniqueness checks are performed via custom validators

        $objectMapper->map($userSaveDto, $user);

        $user->resetTeams();

        // Filter out empty team IDs
        $validTeamIds = array_filter(
            array_map(static fn ($id) => (int) $id, $userSaveDto->teams),
            static fn ($id) => $id > 0,
        );

        if (!empty($validTeamIds)) {
            // Fetch all teams in a single query to avoid N+1 problem
            $teams = $this->doctrineRegistry->getRepository(Team::class)->findBy(['id' => $validTeamIds]);
            $foundTeamIds = [];

            foreach ($teams as $team) {
                $user->addTeam($team);
                $foundTeamIds[] = $team->getId();
            }

            // Check if any requested teams were not found
            $missingTeamIds = array_diff($validTeamIds, $foundTeamIds);
            if (!empty($missingTeamIds)) {
                $response = new Response(sprintf(
                    $this->translate('Could not find team(s) with ID(s): %s.'),
                    implode(', ', $missingTeamIds),
                ));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        // Team validation is handled by the DTO callback

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        $data = [$user->getId(), $userSaveDto->username, $userSaveDto->abbr, $userSaveDto->type];

        return new JsonResponse($data);
    }
}
