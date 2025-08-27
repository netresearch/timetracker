<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\Activity;
use App\Model\JsonResponse;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class DeleteActivityAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/activity/delete', name: 'deleteActivity_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] IdDto $dto): JsonResponse|Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = $dto->id;
            $doctrine = $this->doctrineRegistry;

            $activity = $doctrine->getRepository(Activity::class)
                ->find($id);

            $em = $this->doctrineRegistry->getManager();
            if ($activity) {
                $em->remove($activity);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
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



