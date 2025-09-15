<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Response\Error;
use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Security\Http\Attribute\Security;

final class DeleteUserAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/user/delete', name: 'deleteUser_attr', methods: ['POST'])]
    #[Security("is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and user.getType().value == 'PL')")]
    public function __invoke(Request $request, #[MapRequestPayload] IdDto $idDto): JsonResponse|Error|\App\Model\Response
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
