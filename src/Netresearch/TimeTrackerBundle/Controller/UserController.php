<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\User as User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Symfony\Component\HttpFoundation\Request;

class UserController extends Controller
{
	public function addAction(Request $request)
    {
		$username = $request->request->get('username');

		if (empty($username)) {
			return new Response(json_encode(array('success' => false)));
		}

		// enforce ldap-style login names
		$username = str_replace(
            [' ','ä','ö','ü','ß','é'],
            ['.','ae','oe','ue','ss','e'],
            strtolower($username)
        );

		$user = new User();
		$user->setUsername($username);
		$user->setType('DEV');

		$em = $this->getDoctrine()->getManager();
		$em->persist($user);
		$em->flush();

		return new Response(json_encode(array('success' => true)));
	}
}
