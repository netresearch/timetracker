<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Activity;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;

final class SaveActivityAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/activity/save', name: 'saveActivity_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Activity::class);

        $id = (int) $request->request->get('id');
        $name = (string) ($request->request->get('name') ?? '');
        $needsTicket = (bool) $request->request->get('needsTicket');
        $factorRaw = $request->request->get('factor');
        $factor = (float) str_replace(',', '.', (string) ($factorRaw ?? '0'));

        if (0 !== $id) {
            $activity = $objectRepository->find($id);
            if (!$activity) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$activity instanceof Activity) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $activity = new Activity();
        }

        $sameNamedActivity = $objectRepository->findOneByName($name);
        if ($sameNamedActivity instanceof Activity && $activity->getId() !== $sameNamedActivity->getId()) {
            $response = new Response($this->translate('The activity name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $activity
                ->setName($name)
                ->setNeedsTicket($needsTicket)
                ->setFactor($factor);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($activity);
            $em->flush();
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $data = [$activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor()];

        return new JsonResponse($data);
    }
}



