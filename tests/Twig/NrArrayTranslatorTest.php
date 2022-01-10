<?php declare(strict_types=1);
/**
 * UnitTest for the json array translator for twig-templates
 *
 * PHP version 5
 *
 * @category  Twig_Extension
 * @package   App\Extension
 * @author    Norman Kante <norman.kante@netresearch.de>
 * @copyright 2013 Netresearch App Factory AG
 * @license   No license
 * @link      http://www.netresearch.de
 */

namespace App\Tests;

use Symfony\Component\Translation\TranslatorBagInterface;
use App\Twig\NrArrayTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Twig\TwigFilter;

/**
 * Class NrArrayTranslatorTest
 *
 * @category Twig_Extenstion
 * @package  App\Tests\Extension
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class NrArrayTranslatorTest
    extends TestCase
{

    protected ?TranslatorBagInterface $translator = null;
    protected ?NrArrayTranslator $nrArrayTranslator = null;

    /**
     * setup the symfony translator and the NrArrayTranslator for this test
     */
    protected function setUp(): void
    {
        $this->translator        = new Translator('de');
        $this->nrArrayTranslator = new NrArrayTranslator($this->translator);
    }

    /**
     * check the name value of the extension
     */
    public function testGetName(): void
    {
        static::assertSame(
            $this->nrArrayTranslator->getName(),
            'nr_array_translator'
        );
    }

    /**
     * checks the getFilters
     */
    public function testGetFilters(): void
    {
        $filters = $this->nrArrayTranslator->getFilters();
        static::assertIsArray($filters);
        static::assertArrayHasKey('nr_array_translator', $filters);
        static::assertTrue(
            $filters['nr_array_translator'] instanceof TwigFilter
        );

    }

    /**
     * check te filterArray() functionality
     */
    public function testFilterArray(): void
    {
        $dataToTranslate               = [];
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

        static::assertSame(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities',
                ['name']
            )
        );

        static::assertSame(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities'
            )
        );

        static::assertSame(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity'
            )
        );
    }

}
