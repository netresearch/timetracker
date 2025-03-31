<?php
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

namespace Tests\Extension;

use App\Extension\NrArrayTranslator;
use Tests\AbstractWebTestCase;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Translation\Translator;

/**
 * Class NrArrayTranslatorTest
 *
 * @category Twig_Extenstion
 * @package  App\Tests\Extension
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class NrArrayTranslatorTest extends AbstractWebTestCase
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
     * setup the symfony translator and the NrArrayTranslator for this test
     */
    protected function setUp(): void
    {
        $this->translator = new Translator('de');
        $this->nrArrayTranslator = new NrArrayTranslator($this->translator);
    }

    /**
     * check the name value of the extension
     */
    public function testGetName(): void
    {
        $this->assertEquals(
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
        $this->assertTrue(is_array($filters));
        $this->assertTrue(array_key_exists('nr_array_translator', $filters));
        $this->assertTrue(
            $filters['nr_array_translator'] instanceof \Twig\TwigFilter
        );
    }

    /**
     * check te filterArray() functionality
     */
    public function testFilterArray(): void
    {
        $dataToTranslate = [];
        $dataToTranslate[]['activity'] = [
            'id' => 1, 'name' => 'Entwicklung'
        ];
        $dataToTranslate[]['activity'] = [
            'id' => 2, 'name' => 'QA'
        ];
        $dataToTranslate[]['activity'] = [
            'id' => 3, 'name' => 'Administration'
        ];
        $dataToTranslate[]['ignoreMe'] = [
            'id' => 3, 'name' => 'Administration'
        ];

        $dataToTranslateJson = json_encode($dataToTranslate);

        $this->assertEquals(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities',
                ['name']
            )
        );

        $this->assertEquals(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities'
            )
        );

        $this->assertEquals(
            $dataToTranslateJson,
            $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity'
            )
        );
    }
}
