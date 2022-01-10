<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
;

$config = new PhpCsFixer\Config();
return $config->setRules([
        '@PHP80Migration:risky' => true,
        '@PHP81Migration' => true,
        
        '@PhpCsFixer:risky' => true,
        //'@PhpCsFixer' => true,

        // included in @PhpCsFixer:risky
        '@Symfony:risky' => true,
        //'@Symfony' => true,

        // included in @Symfony:risky
        '@PSR12:risky' => true,
        //'@PSR12' => true,

        '@DoctrineAnnotation' => true,

        '@PHPUnit84Migration:risky' => true,

        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],

        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align',
                '=' => 'align'
            ]
        ],

        'simplified_null_return' => true,
        'simplified_if_return' => true,
        'regular_callable_call' => true,
        'phpdoc_to_return_type' => true,
        'phpdoc_to_property_type' => true,
        'phpdoc_to_param_type' => true,
        'phpdoc_tag_casing' => true,
    ])
    //->setFinder($finder)
    ->setIndent("    ")
    ->setLineEnding("\n")
    ->setFinder($finder)
;
