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
use App\Service\Validation\UserValidator;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SaveUserAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/user/save', name: 'saveUser_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] UserSaveDto $dto, ObjectMapperInterface $mapper, UserValidator $userValidator, ValidatorInterface $validator): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        $user = 0 !== $dto->id ? $objectRepository->find($dto->id) : new User();
        if (!$user instanceof User) {
            return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        $name = $dto->username;
        $abbr = $dto->abbr;
        $locale = $dto->locale;
        $type = $dto->type;

        // uniqueness checks remain
        if (!$userValidator->isUsernameUnique($name, $user->getId())) {
            $response = new Response($this->translate('The user name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (!$userValidator->isAbbrUnique($abbr, $user->getId())) {
            $response = new Response($this->translate('The user name abreviation provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $mapper->map($dto, $user);

        $user->resetTeams();
        foreach ($dto->teams as $teamId) {
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

        if (0 == $user->getTeams()->count()) {
            $response = new Response($this->translate('Every user must belong to at least one team'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        $data = [$user->getId(), $name, $abbr, $type];

        return new JsonResponse($data);
    }
}



