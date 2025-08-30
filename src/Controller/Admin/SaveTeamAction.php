<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\TeamSaveDto;
use App\Entity\Team;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class SaveTeamAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/team/save', name: 'saveTeam_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] TeamSaveDto $dto): Response|JsonResponse|\App\Response\Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        $id = $dto->id;
        $name = $dto->name;
        $teamLead = $dto->lead_user_id
            ? $this->doctrineRegistry->getRepository(User::class)->find($dto->lead_user_id)
            : null;

        if (0 !== $id) {
            $team = $objectRepository->find($id);
            if (!$team) {
                $message = $this->translator->trans('No entry for id.');

                return new \App\Response\Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$team instanceof Team) {
                return new \App\Response\Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $team = new Team();
        }

        $sameNamedTeam = $objectRepository->findOneByName($name);
        if ($sameNamedTeam instanceof Team && $team->getId() !== $sameNamedTeam->getId()) {
            $response = new Response($this->translate('The team name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (!$teamLead instanceof User) {
            $response = new Response($this->translate('Please provide a valid user as team leader.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $team
                ->setName($name)
                ->setLeadUser($teamLead);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($team);
            $em->flush();
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        $data = [$team->getId(), $team->getName(), $team->getLeadUser() instanceof User ? $team->getLeadUser()->getId() : ''];

        return new JsonResponse($data);
    }
}



