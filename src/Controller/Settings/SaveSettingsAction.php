<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Service\Util\LocalizationService;
use Symfony\Component\HttpFoundation\Request;

final class SaveSettingsAction extends BaseController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When request is malformed
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException When user is not authenticated
     * @throws \Doctrine\ORM\ORMException When database operations fail
     * @throws \Exception When user retrieval or persistence operations fail
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/settings/save', name: 'saveSettings', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ('POST' !== $request->getMethod()) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('The configuration could not be saved.'),
            ]);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_SERVICE_UNAVAILABLE);

            return $response;
        }

        try {
            $userId = $this->getUserId($request);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('User not found.'),
            ]);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);

            return $response;
        }

        /** @var \App\Entity\User|null $user */
        $user = $this->managerRegistry->getRepository(\App\Entity\User::class)->find($userId);
        if (!$user instanceof \App\Entity\User) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('User not found.'),
            ]);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);

            return $response;
        }

        $user->setShowEmptyLine((bool) $request->request->get('show_empty_line'));
        $user->setSuggestTime((bool) $request->request->get('suggest_time'));
        $user->setShowFuture((bool) $request->request->get('show_future'));

        $normalized = $this->localizationService->normalizeLocale((string) $request->request->get('locale'));
        $user->setLocale($normalized);

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        $request->setLocale($user->getLocale());

        return new JsonResponse([
            'success' => true,
            'settings' => $user->getSettings(),
            'locale' => $user->getLocale(),
            'message' => $this->translator->trans('The configuration has been successfully saved.'),
        ]);
    }

    private LocalizationService $localizationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setLocalizationService(LocalizationService $localizationService): void
    {
        $this->localizationService = $localizationService;
    }
}
