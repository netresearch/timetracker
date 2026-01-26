<?php

declare(strict_types=1);

namespace Tests\Extension;

use App\Extension\NrArrayTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

/**
 * Unit tests for NrArrayTranslator.
 *
 * @internal
 */
#[CoversClass(NrArrayTranslator::class)]
final class NrArrayTranslatorTest extends TestCase
{
    /**
     * @var Translator symfony translator
     */
    protected $translator;

    /**
     * @var NrArrayTranslator
     */
    protected $nrArrayTranslator;

    /**
     * setup the symfony translator and the NrArrayTranslator for this test.
     */
    protected function setUp(): void
    {
        $this->translator = new Translator('de');
        $this->nrArrayTranslator = new NrArrayTranslator($this->translator);
    }

    /**
     * check the name value of the extension.
     */
    public function testGetName(): void
    {
        self::assertSame(
            $this->nrArrayTranslator->getName(),
            'nr_array_translator',
        );
    }

    /**
     * check te filterArray() functionality.
     */
    public function testFilterArray(): void
    {
        /** @var array<int, array<string, mixed>> $dataToTranslate */
        $dataToTranslate = [];
        $activity1 = ['activity' => ['id' => 1, 'name' => 'Entwicklung']];
        $activity2 = ['activity' => ['id' => 2, 'name' => 'QA']];
        $activity3 = ['activity' => ['id' => 3, 'name' => 'Administration']];
        $ignoreMe = ['ignoreMe' => ['id' => 3, 'name' => 'Administration']];
        $dataToTranslate[] = $activity1;
        $dataToTranslate[] = $activity2;
        $dataToTranslate[] = $activity3;
        $dataToTranslate[] = $ignoreMe;

        $dataToTranslateJson = json_encode($dataToTranslate);
        self::assertNotFalse($dataToTranslateJson, 'JSON encoding should not fail');

        self::assertSame(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities',
                ['name'],
            ),
        );

        self::assertSame(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities',
            ),
        );

        self::assertSame(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
            ),
        );
    }

    // ==================== Edge case tests ====================

    public function testFilterArrayReturnsEmptyArrayForInvalidJson(): void
    {
        $result = $this->nrArrayTranslator->filterArray(
            'not valid json',
            'activity',
        );

        self::assertSame('[]', $result);
    }

    public function testFilterArraySkipsNonArrayRows(): void
    {
        // Data with non-array elements
        $data = [
            ['activity' => ['name' => 'Test']],
            'string_not_array',
            123,
            null,
        ];

        $json = json_encode($data);
        self::assertNotFalse($json);

        // Should not fail, just skip non-array rows
        $result = $this->nrArrayTranslator->filterArray($json, 'activity');

        // Result should be valid JSON
        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
    }

    public function testFilterArraySkipsRowsWithoutTargetKey(): void
    {
        $data = [
            ['activity' => ['name' => 'Test']],
            ['other_key' => ['name' => 'Skipped']],
        ];

        $json = json_encode($data);
        self::assertNotFalse($json);

        $result = $this->nrArrayTranslator->filterArray($json, 'activity');

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
    }

    public function testFilterArraySkipsNonIterableNestedElements(): void
    {
        $data = [
            ['activity' => 'not_iterable_string'],
            ['activity' => 123],
        ];

        $json = json_encode($data);
        self::assertNotFalse($json);

        $result = $this->nrArrayTranslator->filterArray($json, 'activity');

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
    }

    public function testFilterArraySkipsNonStringValues(): void
    {
        $data = [
            ['activity' => ['name' => 123, 'id' => 1]],
            ['activity' => ['name' => ['nested'], 'id' => 2]],
        ];

        $json = json_encode($data);
        self::assertNotFalse($json);

        $result = $this->nrArrayTranslator->filterArray($json, 'activity', 'messages', ['name']);

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
    }

    public function testFilterArrayHandlesEmptyInput(): void
    {
        $result = $this->nrArrayTranslator->filterArray('[]', 'activity');

        self::assertSame('[]', $result);
    }

    public function testFilterArrayHandlesEmptyString(): void
    {
        // Empty string is not valid JSON, should return empty array
        $result = $this->nrArrayTranslator->filterArray('', 'activity');

        self::assertSame('[]', $result);
    }
}
