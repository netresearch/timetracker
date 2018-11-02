<?php
/**
 * UnitTest for the json array translator for twig-templates
 *
 * PHP version 5
 *
 * @category  Twig_Extension
 * @package   Netresearch\TimeTrackerBundle\Extension
 * @author    Norman Kante <norman.kante@netresearch.de>
 * @copyright 2013 Netresearch App Factory AG
 * @license   No license
 * @link      http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Tests;

use Netresearch\TimeTrackerBundle\Extension\NrArrayTranslator;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Translation\Translator;

/**
 * Class NrArrayTranslatorTest
 *
 * @category Twig_Extenstion
 * @package  Netresearch\TimeTrackerBundle\Tests\Extension
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class NrArrayTranslatorTest
    extends TestCase
{

    /**
     * @var Translator symfony translator
     */
    protected $translator = null;

    /**
     * @var NrArrayTranslator
     */
    protected $nrArrayTranslator = null;

    /**
     * setup the symfony translator and the NrArrayTranslator for this test
     *
     * @return void
     */
    public function setUp()
    {
        $this->translator = new Translator('de');
        $this->nrArrayTranslator = new NrArrayTranslator($this->translator);
    }

    /**
     * check the name value of the extension
     *
     * @return void
     */
    public function testGetName()
    {
        $this->assertEquals(
            $this->nrArrayTranslator->getName(),
            'nr_array_translator'
        );
    }

    /**
     * checks the getFilters
     */
    public function testGetFilters()
    {
        $filters = $this->nrArrayTranslator->getFilters();
        $this->assertTrue(is_array($filters));
        $this->assertTrue(array_key_exists('nr_array_translator', $filters));
        $this->assertTrue(
            $filters['nr_array_translator'] instanceof \Twig_SimpleFilter
        );

    }

    /**
     * check te filterArray() functionality
     *
     * @return void
     */
    public function testFilterArray()
    {
        $dataToTranslate = array();
        $dataToTranslate[]['activity'] = array(
            'id' => 1, 'name' => 'Entwicklung'
        );
        $dataToTranslate[]['activity'] = array(
            'id' => 2, 'name' => 'QA'
        );
        $dataToTranslate[]['activity'] = array(
            'id' => 3, 'name' => 'Administration'
        );
        $dataToTranslate[]['ignoreMe'] = array(
            'id' => 3, 'name' => 'Administration'
        );

        $dataToTranslateJson = json_encode($dataToTranslate);

        $this->assertEquals(
            $dataToTranslateJson, $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities',
                array('name')
            )
        );

        $this->assertEquals(
            $dataToTranslateJson, $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity',
                'activities'
            )
        );

        $this->assertEquals(
            $dataToTranslateJson, $this->nrArrayTranslator->filterArray(
                $dataToTranslateJson,
                'activity'
            )
        );
    }

}
