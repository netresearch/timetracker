<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Exception;
use RuntimeException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DeleteUserAction extends BaseController
{
    #[Route(path: '/user/delete', name: 'deleteUser_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] IdDto $idDto): JsonResponse|Error|Response
    {
        try {
            $id = $idDto->id;
            $doctrine = $this->doctrineRegistry;

            $user = $doctrine->getRepository(User::class)->find($id);

            $em = $doctrine->getManager();
            if ($user instanceof User) {
                $em->remove($user);
                $em->flush();
            } else {
                throw new RuntimeException('Already deleted');
            }
        } catch (Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = 'Der Datensatz konnte nicht enfernt werden! ' . $reason;

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }
}
