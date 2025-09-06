<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\PresetSaveDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use App\Model\JsonResponse;
use App\Model\Response;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

final class SavePresetAction extends BaseController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException
     * @throws \Exception
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/preset/save', name: 'savePreset_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] PresetSaveDto $presetSaveDto, ObjectMapperInterface $objectMapper): Response|JsonResponse|\App\Response\Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id = $presetSaveDto->id;
        $customer = null !== $presetSaveDto->customer ? $this->doctrineRegistry->getRepository(Customer::class)->find($presetSaveDto->customer) : null;
        $project = null !== $presetSaveDto->project ? $this->doctrineRegistry->getRepository(Project::class)->find($presetSaveDto->project) : null;
        $activity = null !== $presetSaveDto->activity ? $this->doctrineRegistry->getRepository(Activity::class)->find($presetSaveDto->activity) : null;

        // Basic length validation now handled by DTO constraints via MapRequestPayload (422)

        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        if (0 !== $id) {
            $preset = $objectRepository->find($id);
            if (!$preset instanceof Preset) {
                $message = $this->translator->trans('No entry for id.');

                return new \App\Response\Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

        // $preset is already instance of Preset due to the check above
        } else {
            $preset = new Preset();
        }

        try {
            if (!$customer instanceof Customer || !$project instanceof Project || !$activity instanceof Activity) {
                throw new Exception('Please choose a customer, a project and an activity.');
            }

            // Map scalar fields (name, description)
            $objectMapper->map($presetSaveDto, $preset);
            // Relations set explicitly
            $preset->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity)
            ;

            $em = $this->doctrineRegistry->getManager();
            $em->persist($preset);
            $em->flush();
        } catch (Exception) {
            $response = new Response($this->translate('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        return new JsonResponse($preset->toArray());
    }
}
