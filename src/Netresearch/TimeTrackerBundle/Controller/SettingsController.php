<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\User as User;
use Netresearch\TimeTrackerBundle\Helper\LocalizationHelper as LocalizationHelper;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function saveAction()
    {
		$request = $this->getRequest();
        if ('POST' == $request->getMethod()) {
            $userId = $this->get('request')->getSession()->get('loginId');

            $doctrine = $this->getDoctrine();
            $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($userId);

            $user->setShowEmptyLine($request->request->get('show_empty_line'));
            $user->setSuggestTime($request->request->get('suggest_time'));
            $user->setShowFuture($request->request->get('show_future'));
            $user->setLocale(LocalizationHelper::normalizeLocale($request->request->get('locale')));

            $em = $doctrine->getEntityManager();
            $em->persist($user);
            $em->flush();

            // Adapt to new locale immedtiately
            $this->getRequest()->setLocale($user->getLocale());

            return new Response(json_encode(array('success' => true, 
                    'settings' => $user->getSettings(),
                    'locale' => $user->getLocale(),
                    'message' => $this->get('translator')->trans('The configuration has been successfully saved.')
                )));
        }

        $response = new Response(json_encode(array('success' => false, 
                'message' => $this->get('translator')->trans('The configuration could not be saved.')
            )));

        $reponse->setStatusCode(503);
        return $response;

    }

}
