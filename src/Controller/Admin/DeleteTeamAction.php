<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\Team;
use App\Model\JsonResponse;
use App\Response\Error;
use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

use function sprintf;

final class DeleteTeamAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/team/delete', name: 'deleteTeam_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] IdDto $idDto): JsonResponse|Error|\App\Model\Response
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = $idDto->id;
            $doctrine = $this->doctrineRegistry;

            $team = $doctrine->getRepository(Team::class)->find($id);

            $em = $doctrine->getManager();
            if ($team instanceof Team) {
                $em->remove($team);
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
