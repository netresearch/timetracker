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
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use UnexpectedValueException;

use function sprintf;

final class SaveCustomerAction extends BaseController
{
    /**
     * @throws InvalidArgumentException                                        When team IDs are invalid or missing teams are found
     * @throws UnexpectedValueException                                        When customer data mapping fails or validation errors occur
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException
     * @throws Exception
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/customer/save', name: 'saveCustomer_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request, #[MapRequestPayload] CustomerSaveDto $customerSaveDto, ObjectMapperInterface $objectMapper): Response|Error|JsonResponse
    {

        $customerId = $customerSaveDto->id;
        $teamIds = $customerSaveDto->teams;

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        if (0 !== $customerId) {
            $customer = $objectRepository->find($customerId);
            if (null === $customer) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (! $customer instanceof Customer) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $customer = new Customer();
        }

        // Validation is now handled by the DTO with MapRequestPayload

        $objectMapper->map($customerSaveDto, $customer);

        $customer->resetTeams();

        // Filter out empty team IDs
        $validTeamIds = array_filter(
            array_map(static fn (int|string $id): int => (int) $id, $teamIds),
            static fn (int $id): bool => $id > 0,
        );

        if ([] !== $validTeamIds) {
            // Fetch all teams in a single query to avoid N+1 problem
            $teams = $this->doctrineRegistry->getRepository(Team::class)->findBy(['id' => $validTeamIds]);
            $foundTeamIds = [];

            foreach ($teams as $team) {
                $customer->addTeam($team);
                $foundTeamIds[] = $team->getId();
            }

            // Check if any requested teams were not found
            $missingTeamIds = array_diff($validTeamIds, $foundTeamIds);
            if ([] !== $missingTeamIds) {
                $response = new Response(sprintf(
                    $this->translate('Could not find team(s) with ID(s): %s.'),
                    implode(', ', $missingTeamIds),
                ));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (0 === $customer->getTeams()->count() && false === $customerSaveDto->global) {
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
