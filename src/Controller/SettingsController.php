<?php

namespace App\Controller;

use App\Helper\LocalizationHelper as LocalizationHelper;

use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SettingsController extends BaseController
{
    #[Route(path: '/settings/save')]
    public function saveAction()
    {
        if ('POST' == $this->request->getMethod()) {
            $userId = $this->session->get('loginId');

            $doctrine = $this->doctrine;
            $user = $doctrine->getRepository('App:User')->find($userId);

            $user->setShowEmptyLine($this->request->get('show_empty_line'));
            $user->setSuggestTime($this->request->get('suggest_time'));
            $user->setShowFuture($this->request->get('show_future'));
            $user->setLocale(LocalizationHelper::normalizeLocale($this->request->get('locale')));

            $em = $doctrine->getManager();
            $em->persist($user);
            $em->flush();

            // Adapt to new locale immediately
            $this->request->setLocale($user->getLocale());

            return new Response(json_encode(array('success' => true,
                    'settings' => $user->getSettings(),
                    'locale' => $user->getLocale(),
                    'message' => $this->t('The configuration has been successfully saved.')
                ), JSON_THROW_ON_ERROR));
        }

        $response = new Response(json_encode(array('success' => false,
                'message' => $this->t('The configuration could not be saved.')
            ), JSON_THROW_ON_ERROR));

        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_SERVICE_UNAVAILABLE);
        return $response;

    }

}
