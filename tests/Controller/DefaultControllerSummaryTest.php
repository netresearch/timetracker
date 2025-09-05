<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Entry;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DefaultControllerSummaryTest extends AbstractWebTestCase
{
    public function testGetSummaryActionWithProjectEstimationComputesQuota(): void
    {
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();
        $entry = $em->getRepository(Entry::class)->findOneBy([]);
        if (!$entry) {
            self::markTestSkipped('No entries found in the database.');
        }

        $project = $entry->getProject();
        // Ensure estimation is set to a non-zero value
        $project->setEstimation(300);

        $em->persist($project);
        $em->flush();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('project', $response);
        self::assertArrayHasKey('quota', $response['project']);
        $quota = $response['project']['quota'];
        // When estimation is set, quota should be a percentage string
        self::assertIsString($quota);
        self::assertStringEndsWith('%', $quota);
    }

    public function testGetSummaryActionWithoutEstimationLeavesZeroQuota(): void
    {
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();
        $entry = $em->getRepository(Entry::class)->findOneBy([]);
        if (!$entry) {
            self::markTestSkipped('No entries found in the database.');
        }

        $project = $entry->getProject();
        // Remove estimation (set to 0)
        $project->setEstimation(0);

        $em->persist($project);
        $em->flush();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('project', $response);
        // Without estimation set, quota remains numeric zero according to default data
        self::assertSame(0, $response['project']['quota'] ?? 0);
    }
}
