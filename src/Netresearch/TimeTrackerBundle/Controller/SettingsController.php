<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Helper\LocalizationHelper as LocalizationHelper;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SettingsController extends Controller
{
    public function saveAction(Request $request)
    {
        if ('POST' == $request->getMethod()) {
            $userId = $request->getSession()->get('loginId');

            $doctrine = $this->getDoctrine();
            $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($userId);

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
                    'message' => $this->get('translator')->trans('The configuration has been successfully saved.')
                ));
        }

        $response = new JsonResponse(array('success' => false,
                'message' => $this->get('translator')->trans('The configuration could not be saved.')
            ));

        $response->setStatusCode(503);
        return $response;

    }

}
