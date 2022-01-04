<?php

namespace App\Controller;

use App\Helper\LocalizationHelper as LocalizationHelper;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;

class SettingsController extends AbstractController
{
    public function saveAction(Request $request)
    {
        if ('POST' == $request->getMethod()) {
            $userId = $request->getSession()->get('loginId');

            $doctrine = $this->getDoctrine();
            $user = $doctrine->getRepository('App:User')->find($userId);

            $user->setShowEmptyLine($request->request->get('show_empty_line'));
            $user->setSuggestTime($request->request->get('suggest_time'));
            $user->setShowFuture($request->request->get('show_future'));
            $user->setLocale(LocalizationHelper::normalizeLocale($request->request->get('locale')));

            $em = $doctrine->getManager();
            $em->persist($user);
            $em->flush();

            // Adapt to new locale immediately
            $request->setLocale($user->getLocale());

            return new Response(json_encode(array('success' => true,
                    'settings' => $user->getSettings(),
                    'locale' => $user->getLocale(),
                    'message' => $this->get('translator')->trans('The configuration has been successfully saved.')
                ), JSON_THROW_ON_ERROR));
        }

        $response = new Response(json_encode(array('success' => false,
                'message' => $this->get('translator')->trans('The configuration could not be saved.')
            ), JSON_THROW_ON_ERROR));

        $response->setStatusCode(503);
        return $response;

    }

}
