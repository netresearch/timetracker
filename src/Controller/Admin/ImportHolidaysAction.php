<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Util\IcalHolidayParser;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\Attribute\Required;

use function count;
use function file_get_contents;
use function in_array;
use function is_string;
use function parse_url;
use function strlen;

use const PHP_URL_SCHEME;

/**
 * Imports public holidays from an iCalendar feed — either a URL (regional
 * holiday feeds) or an uploaded .ics file. Existing days are updated, new
 * days inserted (ported from the Mogic fork, TIM-135).
 */
final class ImportHolidaysAction extends BaseController
{
    /** Feeds larger than this are rejected — holiday calendars are tiny. */
    private const int MAX_ICAL_BYTES = 1_048_576;

    private HttpClientInterface $httpClient;

    private IcalHolidayParser $icalHolidayParser;

    #[Required]
    public function setImportDependencies(HttpClientInterface $httpClient, IcalHolidayParser $icalHolidayParser): void
    {
        $this->httpClient = $httpClient;
        $this->icalHolidayParser = $icalHolidayParser;
    }

    #[Route(path: '/holiday/import-ical', name: 'importHolidaysIcal_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): Response|JsonResponse|Error
    {
        $icalContent = $this->readIcalContent($request);
        if ($icalContent instanceof Error) {
            return $icalContent;
        }

        $events = $this->icalHolidayParser->parse($icalContent);
        if ([] === $events) {
            return new Error($this->translate('No events found in iCal data.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        /** @var Connection $connection */
        $connection = $this->doctrineRegistry->getConnection();

        $imported = 0;
        $updated = 0;

        try {
            foreach ($events as $day => $name) {
                if (false !== $connection->fetchOne('SELECT day FROM holidays WHERE day = ?', [$day])) {
                    $connection->update('holidays', ['name' => $name], ['day' => $day]);
                    ++$updated;
                } else {
                    $connection->insert('holidays', ['day' => $day, 'name' => $name]);
                    ++$imported;
                }
            }
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        return new JsonResponse([
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'total' => count($events),
        ]);
    }

    /**
     * Reads the iCal payload from an uploaded file (`file`) or a feed URL
     * (`url`), whichever the request carries.
     */
    private function readIcalContent(Request $request): string|Error
    {
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile) {
            $content = file_get_contents($file->getPathname());
            if (false === $content || '' === $content) {
                return new Error($this->translate('The uploaded file could not be read.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            if (strlen($content) > self::MAX_ICAL_BYTES) {
                return new Error($this->translate('The iCal data is too large.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            return $content;
        }

        $url = $request->request->get('url');
        if (!is_string($url) || '' === $url) {
            return new Error($this->translate('Provide an iCal URL or upload an .ics file.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        // Only remote http(s) feeds — no file://, no local schemes.
        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return new Error($this->translate('Only http(s) iCal URLs are supported.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_redirects' => 3,
            ]);
            $content = $response->getContent();
        } catch (ExceptionInterface $exception) {
            return new Error($this->translate('The iCal feed could not be fetched.') . ' ' . $exception->getMessage(), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_GATEWAY);
        }

        if ('' === $content) {
            return new Error($this->translate('The iCal feed is empty.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        if (strlen($content) > self::MAX_ICAL_BYTES) {
            return new Error($this->translate('The iCal data is too large.'), \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        return $content;
    }
}
