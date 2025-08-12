<?php

namespace App\Controller;

use App\Helper\LocalizationHelper;
use App\Service\Util\LocalizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Annotation\Route;

class SettingsController extends AbstractController
{
    /** @var TranslatorInterface */
    protected $translator;

    /** @var LocalizationService */
    protected $localizationService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setLocalizationService(LocalizationService $localizationService): void
    {
        $this->localizationService = $localizationService;
    }

    /**
     * @Route("/settings/save", name="saveSettings", methods={"POST"})
     */
    public function saveAction(Request $request)
    {
        if ('POST' != $request->getMethod()) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('The configuration could not be saved.')
            ]);
            $response->setStatusCode(503);
            return $response;
        }

        $user = $this->getUser();
        if (!$user) {
            $response = new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('User not found.')
            ]);
            $response->setStatusCode(404);
            return $response;
        }

        $user->setShowEmptyLine($request->request->get('show_empty_line'));
        $user->setSuggestTime($request->request->get('suggest_time'));
        $user->setShowFuture($request->request->get('show_future'));
        $normalized = ($this->localizationService ? $this->localizationService->normalizeLocale((string) $request->request->get('locale')) : null) ?? LocalizationHelper::normalizeLocale($request->request->get('locale'));
        $user->setLocale($normalized);

        $objectManager = $this->getDoctrine()->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        // Adapt to new locale immediately
        $request->setLocale($user->getLocale());

        return new JsonResponse([
            'success' => true,
            'settings' => $user->getSettings(),
            'locale' => $user->getLocale(),
            'message' => $this->translator->trans('The configuration has been successfully saved.')
        ]);
    }
}
