<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\TicketSystemSaveDto;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

final class SaveTicketSystemAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/ticketsystem/save', name: 'saveTicketSystem_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] TicketSystemSaveDto $dto, ObjectMapperInterface $mapper): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);

        $id = $dto->id;

        if (0 !== $id) {
            $ticketSystem = $objectRepository->find($id);
            if (!$ticketSystem) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$ticketSystem instanceof TicketSystem) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $ticketSystem = new TicketSystem();
        }

        if (strlen($dto->name) < 3) {
            $response = new Response($this->translate('Please provide a valid ticket system name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $sameNamedSystem = $objectRepository->findOneByName($dto->name);
        if ($sameNamedSystem instanceof TicketSystem && $ticketSystem->getId() != $sameNamedSystem->getId()) {
            $response = new Response($this->translate('The ticket system name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $mapper->map($dto, $ticketSystem);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        return new JsonResponse($ticketSystem->toArray());
    }
}



