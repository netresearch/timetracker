<?php

namespace App\Controller;

use App\Model\JsonResponse;
use App\Service\Util\LocalizationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsController extends BaseController
{
    /** @var TranslatorInterface */
    protected $translator;

    /** @var LocalizationService */
    protected $localizationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setLocalizationService(LocalizationService $localizationService): void
    {
        $this->localizationService = $localizationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/settings/save', name: 'saveSettings', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        if ('POST' !== $request->getMethod()) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('The configuration could not be saved.'),
            ]);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_SERVICE_UNAVAILABLE);

            return $response;
        }

        $user = $this->getUser();
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

        // Adapt to new locale immediately
        $request->setLocale($user->getLocale());

        return new JsonResponse([
            'success' => true,
            'settings' => $user->getSettings(),
            'locale' => $user->getLocale(),
            'message' => $this->translator->trans('The configuration has been successfully saved.'),
        ]);
    }
}
