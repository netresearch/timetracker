<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Response\Error;
use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Security\Http\Attribute\Security;

use function sprintf;

final class DeleteTicketSystemAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/ticketsystem/delete', name: 'deleteTicketSystem_attr', methods: ['POST'])]
    #[Security("is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and user.getType().value == 'PL')")]
    public function __invoke(Request $request, #[MapRequestPayload] IdDto $idDto): JsonResponse|Error|\App\Model\Response
    {

        try {
            $id = $idDto->id;
            $doctrine = $this->doctrineRegistry;

            $ticketSystem = $doctrine->getRepository(TicketSystem::class)->find($id);

            $em = $doctrine->getManager();
            if ($ticketSystem instanceof TicketSystem) {
                $em->remove($ticketSystem);
                $em->flush();
            } else {
                throw new RuntimeException('Already deleted');
            }
        } catch (Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }
}
