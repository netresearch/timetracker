<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\BaseController;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Service\Util\LocalizationService;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

final class SaveSettingsAction extends BaseController
{
    /**
     * @throws BadRequestException   When request is malformed
     * @throws AccessDeniedException When user is not authenticated
     * @throws Exception             When database operations fail
     * @throws Exception             When user retrieval or persistence operations fail
     */
    #[Route(path: '/settings/save', name: 'saveSettings', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        if ('POST' !== $request->getMethod()) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('The configuration could not be saved.'),
            ]);
            $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);

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

    #[Required]
    public function setLocalizationService(LocalizationService $localizationService): void
    {
        $this->localizationService = $localizationService;
    }
}
