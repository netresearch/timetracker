<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use Doctrine\Persistence\ObjectManager;

/**
 * Resolve-or-create a Customer from a name and an optional stable Tempo key,
 * making the Tempo->Customer mapping idempotent across runs (ADR-026 P2).
 *
 * Extracted from {@see ProjectImportConfirmationService} so the P1 confirm flow
 * and the P3 ad-hoc import auto-create share one upsert — the confidence gate
 * lives in the callers, the idempotent write lives here.
 */
final readonly class CustomerUpserter
{
    public function __construct(
        private CustomerRepository $customerRepository,
    ) {
    }

    /**
     * Resolve-or-create a Customer:
     *
     *  1. a key given & a customer already carries it -> reuse (name drift ignored);
     *  2. else match by name -> reuse, backfilling the key when the row had none;
     *  3. else create a new Customer with the name (+ the key when supplied).
     *
     * The by-name / by-key arrays are the caller's within-batch (or within-run)
     * reuse caches, so a customer created for an earlier row/prefix is reused by
     * a later one before the flush — avoiding a UNIQUE-key collision on the
     * unflushed key.
     *
     * @param array<string, Customer> $customersByName     within-batch reuse by name
     * @param array<string, Customer> $customersByTempoKey within-batch reuse by key
     */
    public function upsert(string $name, ?string $tempoKey, array &$customersByName, array &$customersByTempoKey, ObjectManager $objectManager): Customer
    {
        if (null !== $tempoKey) {
            if (isset($customersByTempoKey[$tempoKey])) {
                return $customersByTempoKey[$tempoKey];
            }

            $byKey = $this->customerRepository->findOneByTempoCustomerKey($tempoKey);
            if ($byKey instanceof Customer) {
                $customersByTempoKey[$tempoKey] = $byKey;

                return $byKey;
            }
        }

        $customer = $customersByName[$name] ?? $this->customerRepository->findOneByName($name);
        if ($customer instanceof Customer) {
            $this->backfillTempoKey($customer, $tempoKey, $customersByTempoKey);
            $customersByName[$name] = $customer;

            return $customer;
        }

        $customer = new Customer();
        $customer->setName($name)
            ->setActive(true)
            ->setGlobal(false);
        if (null !== $tempoKey) {
            $customer->setTempoCustomerKey($tempoKey);
            $customersByTempoKey[$tempoKey] = $customer;
        }

        $objectManager->persist($customer);
        $customersByName[$name] = $customer;

        return $customer;
    }

    /**
     * Stamp the stable Tempo key onto a name-matched customer that has none, so a
     * later run resolves it by key. A customer already keyed keeps its key.
     *
     * @param array<string, Customer> $customersByTempoKey
     */
    private function backfillTempoKey(Customer $customer, ?string $tempoKey, array &$customersByTempoKey): void
    {
        if (null === $tempoKey) {
            return;
        }

        // Only cache under this key if we actually backfilled it — caching a customer that already
        // holds a DIFFERENT key would create a cache-vs-DB key mismatch.
        $existing = $customer->getTempoCustomerKey();
        if (null === $existing || '' === $existing) {
            $customer->setTempoCustomerKey($tempoKey);
            $customersByTempoKey[$tempoKey] = $customer;
        }
    }
}
