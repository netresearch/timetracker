<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\CustomerSaveDto;
use App\Entity\Customer;
use App\Entity\Team;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Validation\CustomerValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SaveCustomerAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/customer/save', name: 'saveCustomer_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] CustomerSaveDto $customerSaveDto, ObjectMapperInterface $objectMapper, CustomerValidator $customerValidator, ValidatorInterface $validator): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $customerId = $customerSaveDto->id;
        $teamIds = $customerSaveDto->teams;

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        if (0 !== $customerId) {
            $customer = $objectRepository->find($customerId);
            if (!$customer) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$customer instanceof Customer) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $customer = new Customer();
        }

        if (!$customerValidator->isNameUnique($customerSaveDto->name, $customer->getId())) {
            $response = new Response($this->translate('The customer name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectMapper->map($customerSaveDto, $customer);

        $customer->resetTeams();
        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            $team = $this->doctrineRegistry->getRepository(Team::class)->find((int) $teamId);
            if ($team instanceof Team) {
                $customer->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (0 == $customer->getTeams()->count() && false === $customerSaveDto->global) {
            $response = new Response($this->translate('Every customer must belong to at least one team if it is not global.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($customer);
        $objectManager->flush();

        $data = [$customer->getId(), $customerSaveDto->name, $customerSaveDto->active, $customerSaveDto->global, $teamIds];

        return new JsonResponse($data);
    }
}



