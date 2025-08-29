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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class SavePresetAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/preset/save', name: 'savePreset_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] PresetSaveDto $dto, ObjectMapperInterface $mapper): Response|JsonResponse|\App\Response\Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id = $dto->id;
        $name = $dto->name;
        $customer = null !== $dto->customer ? $this->doctrineRegistry->getRepository(Customer::class)->find($dto->customer) : null;
        $project = null !== $dto->project ? $this->doctrineRegistry->getRepository(Project::class)->find($dto->project) : null;
        $activity = null !== $dto->activity ? $this->doctrineRegistry->getRepository(Activity::class)->find($dto->activity) : null;
        $description = $dto->description;

        // Basic length validation now handled by DTO constraints via MapRequestPayload (422)

        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        if (0 !== $id) {
            $preset = $objectRepository->find($id);
            if (!$preset) {
                $message = $this->translator->trans('No entry for id.');

                return new \App\Response\Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$preset instanceof Preset) {
                return new \App\Response\Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $preset = new Preset();
        }

        try {
            if (!$customer instanceof Customer || !$project instanceof Project || !$activity instanceof Activity) {
                throw new \Exception('Please choose a customer, a project and an activity.');
            }

            // Map scalar fields (name, description)
            $mapper->map($dto, $preset);
            // Relations set explicitly
            $preset->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($preset);
            $em->flush();
        } catch (\Exception) {
            $response = new Response($this->translate('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        return new JsonResponse($preset->toArray());
    }
}



