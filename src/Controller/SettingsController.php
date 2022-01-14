<?php declare(strict_types=1);

namespace App\Controller;

use App\Helper\LocalizationHelper as LocalizationHelper;
use App\Model\Response;
use Symfony\Component\Routing\Annotation\Route;

class SettingsController extends BaseController
{
    #[Route(path: '/settings/save')]
    public function saveAction()
    {
        if ('POST' === $this->request->getMethod()) {
            $user = $this->getWorkUser();

            $user->setShowEmptyLine($this->request->request->getBoolean('show_empty_line'));
            $user->setSuggestTime($this->request->request->getBoolean('suggest_time'));
            $user->setShowFuture($this->request->request->getBoolean('show_future'));
            $user->setLocale(LocalizationHelper::normalizeLocale($this->request->get('locale')));

            $this->em->persist($user);
            $this->em->flush();

            // Adapt to new locale immediately
            $this->request->setLocale($user->getLocale());

            return new Response(json_encode(['success' => true,
                'settings'                             => $user->getSettings(),
                'locale'                               => $user->getLocale(),
                'message'                              => $this->t('The configuration has been successfully saved.'),
            ], \JSON_THROW_ON_ERROR));
        }

        $response = new Response(json_encode(['success' => false,
            'message'                                   => $this->t('The configuration could not be saved.'),
        ], \JSON_THROW_ON_ERROR));

        $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_SERVICE_UNAVAILABLE);

        return $response;
    }
}
