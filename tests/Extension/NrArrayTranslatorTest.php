<?php

declare(strict_types=1);
/**
 * UnitTest for the json array translator for twig-templates.
 *
 * PHP version 5
 *
 * @category  Twig_Extension
 *
 * @author    Norman Kante <norman.kante@netresearch.de>
 * @copyright 2013 Netresearch App Factory AG
 * @license   No license
 *
 * @see      http://www.netresearch.de
 */

namespace Tests\Extension;

use App\Extension\NrArrayTranslator;
use Symfony\Component\Translation\Translator;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function is_array;

/**
 * Class NrArrayTranslatorTest.
 *
 * @category Twig_Extenstion
 *
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 *
 * @see     http://www.netresearch.de
 *
 * @internal
 *
 * @coversNothing
 */
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
     * checks the getFilters.
     */
    public function testGetFilters(): void
    {
        $filters = $this->nrArrayTranslator->getFilters();
        self::assertTrue(is_array($filters));
        self::assertTrue(array_key_exists('nr_array_translator', $filters));
        self::assertTrue(
            $filters['nr_array_translator'] instanceof \Twig\TwigFilter,
        );
    }

    /**
     * check te filterArray() functionality.
     */
    public function testFilterArray(): void
    {
        $dataToTranslate = [];
        $dataToTranslate[]['activity'] = [
            'id' => 1, 'name' => 'Entwicklung',
        ];
        $dataToTranslate[]['activity'] = [
            'id' => 2, 'name' => 'QA',
        ];
        $dataToTranslate[]['activity'] = [
            'id' => 3, 'name' => 'Administration',
        ];
        $dataToTranslate[]['ignoreMe'] = [
            'id' => 3, 'name' => 'Administration',
        ];

        $dataToTranslateJson = json_encode($dataToTranslate);

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
}
