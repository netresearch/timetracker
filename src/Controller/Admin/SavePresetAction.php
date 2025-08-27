<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;

final class SavePresetAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/preset/save', name: 'savePreset_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id = (int) $request->request->get('id');
        $name = (string) ($request->request->get('name') ?? '');
        $customer = $this->doctrineRegistry->getRepository(Customer::class)
            ->find($request->request->get('customer'));
        $project = $this->doctrineRegistry->getRepository(Project::class)
            ->find($request->request->get('project'));
        $activity = $this->doctrineRegistry->getRepository(Activity::class)
            ->find($request->request->get('activity'));
        $description = (string) ($request->request->get('description') ?? '');

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

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

            $preset->setName($name)
                ->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity)
                ->setDescription($description);

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



