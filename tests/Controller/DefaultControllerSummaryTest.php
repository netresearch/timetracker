<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Entry;
use Tests\AbstractWebTestCase;

class DefaultControllerSummaryTest extends AbstractWebTestCase
{
    public function testGetSummaryActionWithProjectEstimationComputesQuota(): void
    {
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();
        $entry = $em->getRepository(Entry::class)->findOneBy([]);
        if (!$entry) {
            $this->markTestSkipped('No entries found in the database.');
        }

        $project = $entry->getProject();
        // Ensure estimation is set to a non-zero value
        $project->setEstimation(300);

        $em->persist($project);
        $em->flush();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('project', $response);
        $this->assertArrayHasKey('quota', $response['project']);
        $quota = $response['project']['quota'];
        // When estimation is set, quota should be a percentage string
        $this->assertIsString($quota);
        $this->assertStringEndsWith('%', $quota);
    }

    public function testGetSummaryActionWithoutEstimationLeavesZeroQuota(): void
    {
        $container = $this->client->getContainer();
        $em = $container->get('doctrine')->getManager();
        $entry = $em->getRepository(Entry::class)->findOneBy([]);
        if (!$entry) {
            $this->markTestSkipped('No entries found in the database.');
        }

        $project = $entry->getProject();
        // Remove estimation
        $project->setEstimation(null);

        $em->persist($project);
        $em->flush();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('project', $response);
        // Without estimation set, quota remains numeric zero according to default data
        $this->assertSame(0, $response['project']['quota'] ?? 0);
    }
}
