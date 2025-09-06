<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\TicketSystem;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;

use function is_scalar;

final class RequestEntityHelper
{
    /**
     * Extract a scalar id value from request for a given key; returns null for empty/non-scalar values.
     * 
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When request is malformed
     */
    public static function id(Request $request, string $key): ?string
    {
        $raw = $request->request->get($key);
        if (!is_scalar($raw)) {
            return null;
        }

        $id = (string) $raw;

        return '' === $id ? null : $id;
    }

    /**
     * Generic find by id on a managed entity, returning typed or null.
     *
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return T|null
     * @throws \Doctrine\ORM\ORMException When database operations fail
     * @throws \InvalidArgumentException When entity class is invalid
     */
    public static function findById(ManagerRegistry $managerRegistry, string $entityClass, ?string $id): ?object
    {
        if (null === $id) {
            return null;
        }

        $entity = $managerRegistry->getRepository($entityClass)->find($id);

        return $entity instanceof $entityClass ? $entity : null;
    }

    public static function user(Request $request, ManagerRegistry $managerRegistry, string $key): ?User
    {
        return self::findById($managerRegistry, User::class, self::id($request, $key));
    }

    public static function ticketSystem(Request $request, ManagerRegistry $managerRegistry, string $key): ?TicketSystem
    {
        return self::findById($managerRegistry, TicketSystem::class, self::id($request, $key));
    }
}
