<?php
/**
 * Json array translator for twig-templates
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

namespace App\Twig;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use \Twig\TwigFilter;
use Twig\Extension\AbstractExtension;

/**
 * Class NrArrayTranslator
 *
 * @category Twig_Extension
 * @package  App\Extension
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class NrArrayTranslator
    extends AbstractExtension
{
    public function __construct(protected TranslatorInterface $translator)
    {

    }

    /**
     * Returns the name of the extension.
     *
     * @return string the extension name
     */
    public function getName()
    {
        return 'nr_array_translator';
    }

    /**
     * Returns the filters of the extension.
     *
     * @return array
     */
    public function getFilters()
    {
        return [
            'nr_array_translator' => new TwigFilter('nr_array_translator', array($this, 'filterArray')),
        ];
    }

    /**
     * Decodes the JSON string to an array and iterates over it to translate the
     * defined keys of each row.
     *
     * @param string $string       json string
     * @param string $arrayKey     key value in the string
     * @param string $languageFile language file for translation
     * @param array  $keys         key which will be translated
     *
     * @return string
     */
    public function filterArray($string, $arrayKey, $languageFile = 'messages',
        array $keys = array('name')
    ) {
        $data = json_decode($string, true, 512, JSON_THROW_ON_ERROR);
        unset($string);

        foreach ($data as $rowKey => $row) {
            if (!array_key_exists($arrayKey, $row)) {
                continue;
            }

            foreach ($row[$arrayKey] as $key => $value) {
                if (in_array($key, $keys)) {
                    $data[$rowKey][$arrayKey][$key] = $this->translator->trans(
                        $value,
                        array(),
                        $languageFile
                    );
                }

            }
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
