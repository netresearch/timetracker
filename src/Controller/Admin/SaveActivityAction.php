<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\ActivitySaveDto;
use App\Entity\Activity;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SaveActivityAction extends BaseController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException
     * @throws Exception
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/activity/save', name: 'saveActivity_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request, #[MapRequestPayload] ActivitySaveDto $activitySaveDto, ObjectMapperInterface $objectMapper): Response|Error|JsonResponse
    {
        /** @var \App\Repository\ActivityRepository<\App\Entity\Activity> $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Activity::class);

        $id = $activitySaveDto->id;

        if (0 !== $id) {
            $activity = $objectRepository->find($id);
            if (null === $activity) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (! $activity instanceof Activity) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $activity = new Activity();
        }

        $sameNamedActivity = $objectRepository->findOneByName($activitySaveDto->name);
        if ($sameNamedActivity instanceof Activity && $activity->getId() !== $sameNamedActivity->getId()) {
            $response = new Response($this->translate('The activity name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $objectMapper->map($activitySaveDto, $activity);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($activity);
            $em->flush();
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        $data = [$activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor()];

        return new JsonResponse($data);
    }
}
