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
        if ('POST' == $request->getMethod()) {
            $userId = $request->getSession()->get('loginId');

            $doctrine = $this->getDoctrine();
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $doctrine->getRepository(\App\Entity\User::class);
            $user = $userRepo->find($userId);

            $user->setShowEmptyLine($request->request->get('show_empty_line'));
            $user->setSuggestTime($request->request->get('suggest_time'));
            $user->setShowFuture($request->request->get('show_future'));
            $user->setLocale(LocalizationHelper::normalizeLocale($request->request->get('locale')));

            $em = $doctrine->getManager();
            $em->persist($user);
            $em->flush();

            // Adapt to new locale immediately
            $request->setLocale($user->getLocale());

            return new JsonResponse(array('success' => true,
                    'settings' => $user->getSettings(),
                    'locale' => $user->getLocale(),
                    'message' => $this->translator->trans('The configuration has been successfully saved.')
                ));
        }

        $response = new JsonResponse(array('success' => false,
                'message' => $this->translator->trans('The configuration could not be saved.')
            ));

        $response->setStatusCode(503);
        return $response;

    }

}
