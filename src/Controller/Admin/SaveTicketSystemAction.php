<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\TicketSystemSaveDto;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\TicketSystemRepository;
use App\Response\Error;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class SaveTicketSystemAction extends BaseController
{
    public function __construct(private readonly ObjectMapperInterface $objectMapper)
    {
    }

    /**
     * @throws BadRequestException              When request payload is malformed
     * @throws UnprocessableEntityHttpException When DTO validation fails
     * @throws Exception                        When database operations fail
     * @throws Exception                        When object mapping or persistence operations fail
     */
    #[Route(path: '/ticketsystem/save', name: 'saveTicketSystem_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] TicketSystemSaveDto $ticketSystemSaveDto): Response|Error|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        assert($objectRepository instanceof TicketSystemRepository);

        $id = $ticketSystemSaveDto->id;

        if (null !== $id) {
            $ticketSystem = $objectRepository->find($id);
            if (null === $ticketSystem) {
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

        $sameNamedSystem = $objectRepository->findOneByName($ticketSystemSaveDto->name);
        if ($sameNamedSystem instanceof TicketSystem && $ticketSystem->getId() !== $sameNamedSystem->getId()) {
            $response = new Response($this->translate('The ticket system name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $this->objectMapper->map($ticketSystemSaveDto, $ticketSystem);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        return new JsonResponse($ticketSystem->toArray());
    }
}
