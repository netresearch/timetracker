<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\User as User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;

class UserController extends Controller
{
	public function addAction() {
		$request = $this->getRequest();
		$username = $request->request->get('username');

		if(empty($username)) {
			return new Response(json_encode(array('success' => false)));
		}

		// enforce ldap-style login names
		$username = str_replace(
					array(' ','ä','ö','ü','ß','é'), 
					array('.','ae','oe','ue','ss','e'),
					strtolower($username));

		$user = new User();
		$user->setUsername($username);
		$user->setType('DEV');

		$em = $this->getDoctrine()->getEntityManager();
		$em->persist($user);
		$em->flush();

		return new Response(json_encode(array('success' => true)));
	}
}
