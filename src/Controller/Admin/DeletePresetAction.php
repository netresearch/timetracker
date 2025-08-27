<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\Preset;
use App\Model\JsonResponse;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class DeletePresetAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/preset/delete', name: 'deletePreset_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] IdDto $dto): JsonResponse|Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = $dto->id;
            $doctrine = $this->doctrineRegistry;

            $preset = $doctrine->getRepository(Preset::class)->find($id);

            $em = $doctrine->getManager();
            if ($preset) {
                $em->remove($preset);
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



