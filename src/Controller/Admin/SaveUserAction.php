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
        foreach ($userSaveDto->teams as $teamId) {
            if (!$teamId) {
                continue;
            }

            $team = $this->doctrineRegistry->getRepository(Team::class)->find((int) $teamId);
            if ($team instanceof Team) {
                $user->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
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
