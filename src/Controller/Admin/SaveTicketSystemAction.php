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

        if (null !== $id) {
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

        // Basic length validation handled by DTO constraints via MapRequestPayload (422)

        $sameNamedSystem = $objectRepository->findOneByName($dto->name);
        if ($sameNamedSystem instanceof TicketSystem && $ticketSystem->getId() != $sameNamedSystem->getId()) {
            $response = new Response($this->translate('The ticket system name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            // Map via ObjectMapper; id is not submitted on create and is ignored by mapping for non-positive values
            $mapper->map($dto, $ticketSystem);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (\Exception $exception) {
            try {
                if (isset($this->container) && $this->container->has('logger')) {
                    $this->container->get('logger')->error('SaveTicketSystemAction failed', [
                        'exception' => $exception,
                        'message' => $exception->getMessage(),
                    ]);
                }
            } catch (\Throwable) {
            }
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        return new JsonResponse($ticketSystem->toArray());
    }
}



