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

namespace App\Extension;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class NrArrayTranslator
 *
 * @category Twig_Extension
 * @package  App\Extension
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 * @link     http://www.netresearch.de
 */
class NrArrayTranslator extends \Twig\Extension\AbstractExtension
{

    /**
     * constructor
     *
     * @param TranslatorInterface $translator symfony translator
     */
    public function __construct(protected \Symfony\Contracts\Translation\TranslatorInterface $translator)
    {
    }

    /**
     * Returns the name of the extension.
     *
     * @return string the extension name
     */
    public function getName(): string
    {
        return 'nr_array_translator';
    }

    /**
     * Returns the filters of the extension.
     *
     * @return array
     */
    /**
     * @return array<int, \Twig\TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            'nr_array_translator' => new \Twig\TwigFilter('nr_array_translator', $this->filterArray(...)),
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
    public function filterArray(
        $string,
        $arrayKey,
        ?string $languageFile = 'messages',
        array $keys = ['name']
    ) {
        $data = json_decode($string, true);
        unset($string);

        foreach ($data as $rowKey => $row) {
            if (!array_key_exists($arrayKey, $row)) {
                continue;
            }

            foreach ($row[$arrayKey] as $key => $value) {
                if (in_array($key, $keys)) {
                    $data[$rowKey][$arrayKey][$key] = $this->translator->trans(
                        $value,
                        [],
                        $languageFile
                    );
                }
            }
        }

        return json_encode($data);
    }
}
