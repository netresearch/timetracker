<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\User\Types;
use App\Model\Response;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends BaseController
{
    #[Route(path: '/user/add', name: 'user_add')]
    public function addAction()
    {
        $username = $this->request->get('username');

        if (empty($username)) {
            return new Response(json_encode(['success' => false]));
        }

        // enforce ldap-style login names
        $username = str_replace(
            [' ', 'ä', 'ö', 'ü', 'ß', 'é'],
            ['.', 'ae', 'oe', 'ue', 'ss', 'e'],
            strtolower($username)
        );

        $user = new User();
        $user->setUsername($username);
        $user->setType(Types::DEV);

        $this->em->persist($user);
        $this->em->flush();

        return new Response(json_encode(['success' => true]));
    }
}
