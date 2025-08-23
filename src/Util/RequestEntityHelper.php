<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\TicketSystem;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;

final class RequestEntityHelper
{
    /**
     * Extract a scalar id value from request for a given key; returns null for empty/non-scalar values.
     */
    public static function id(Request $request, string $key): ?string
    {
        $raw = $request->request->get($key);
        if (!is_scalar($raw)) {
            return null;
        }

        $id = (string) $raw;

        return $id === '' ? null : $id;
    }

    /**
     * Generic find by id on a managed entity, returning typed or null.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public static function findById(ManagerRegistry $registry, string $entityClass, ?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        $entity = $registry->getRepository($entityClass)->find($id);

        return $entity instanceof $entityClass ? $entity : null;
    }

    public static function user(Request $request, ManagerRegistry $registry, string $key): ?User
    {
        /** @var User|null $user */
        $user = self::findById($registry, User::class, self::id($request, $key));

        return $user;
    }

    public static function ticketSystem(Request $request, ManagerRegistry $registry, string $key): ?TicketSystem
    {
        /** @var TicketSystem|null $ts */
        $ts = self::findById($registry, TicketSystem::class, self::id($request, $key));

        return $ts;
    }
}


