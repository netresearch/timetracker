<?php

declare(strict_types=1);

/**
 * Json array translator for twig-templates.
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

namespace App\Extension;

use App\Service\TypeSafety\ArrayTypeHelper;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function in_array;
use function is_array;

/**
 * Class NrArrayTranslator.
 *
 * @category Twig_Extension
 *
 * @author   Norman Kante <norman.kante@netresearch.de>
 * @license  No license
 *
 * @see     http://www.netresearch.de
 */
class NrArrayTranslator extends \Twig\Extension\AbstractExtension
{
    /**
     * constructor.
     *
     * @param TranslatorInterface $translator symfony translator
     */
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    /**
     * Returns the name of the extension.
     *
     * @return string the extension name
     *
     * @psalm-return 'nr_array_translator'
     */
    public function getName(): string
    {
        return 'nr_array_translator';
    }

    /**
     * Returns the filters of the extension.
     *
     * @return \Twig\TwigFilter[]
     *
     * @psalm-return array{nr_array_translator: \Twig\TwigFilter}
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
     * @param string             $string       json string
     * @param string             $arrayKey     key value in the string
     * @param string             $languageFile language file for translation
     * @param array<int, string> $keys
     */
    public function filterArray(
        string $string,
        string $arrayKey,
        ?string $languageFile = 'messages',
        array $keys = ['name'],
    ): string {
        $data = json_decode($string, true);
        unset($string);

        if (!is_array($data)) {
            return (string) json_encode([]);
        }

        foreach ($data as $rowKey => $row) {
            // Ensure $row is an array before checking keys
            if (!is_array($row) || !array_key_exists($arrayKey, $row)) {
                continue;
            }

            // Ensure the nested element is iterable
            if (!is_iterable($row[$arrayKey])) {
                continue;
            }

            foreach ($row[$arrayKey] as $key => $value) {
                // Ensure key is string and in the allowed keys
                if (!is_string($key) || !in_array($key, $keys, true)) {
                    continue;
                }
                
                // Ensure value is string before translation
                if (!is_string($value)) {
                    continue;
                }
                
                // Ensure we have array access to the nested structure
                if (!is_array($data[$rowKey] ?? null) || !is_array($data[$rowKey][$arrayKey] ?? null)) {
                    continue;
                }
                
                $data[$rowKey][$arrayKey][$key] = $this->translator->trans(
                    $value,
                    [],
                    $languageFile,
                );
            }
        }

        return (string) json_encode($data);
    }
}
