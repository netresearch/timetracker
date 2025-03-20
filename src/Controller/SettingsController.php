<?php

namespace App\Controller;

use App\Helper\LocalizationHelper as LocalizationHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsController extends AbstractController
{
    /** @var TranslatorInterface */
    protected $translator;

    /**
     * @required
     */
    public function setTranslator(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

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
        $user->setLocale(LocalizationHelper::normalizeLocale($request->request->get('locale')));

        $em = $this->getDoctrine()->getManager();
        $em->persist($user);
        $em->flush();

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
