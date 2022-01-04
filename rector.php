<?php

declare(strict_types=1);

use Rector\Core\Configuration\Option;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Symfony\Set\SymfonyLevelSetList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Rector\Set\ValueObject\LevelSetList;


return static function (ContainerConfigurator $containerConfigurator): void {
    // region Symfony Container
    $parameters = $containerConfigurator->parameters();
    $parameters->set(Option::PATHS, [
        __DIR__ . '/app',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);
    // $parameters->set(
    //     Option::SYMFONY_CONTAINER_XML_PATH_PARAMETER,
    //     __DIR__ . '/var/cache/dev/App_KernelDevDebugContainer.xml'
    // );
    // endregion

    //$containerConfigurator->import(LevelSetList::UP_TO_PHP_81);

    $containerConfigurator->import(SymfonySetList::SYMFONY_50_TYPES);
    $containerConfigurator->import(SymfonySetList::SYMFONY_52);

    //$containerConfigurator->import(SymfonySetList::SYMFONY_STRICT);
    //$containerConfigurator->import(SymfonySetList::SYMFONY_52_VALIDATOR_ATTRIBUTES);

    //$containerConfigurator->import(SymfonyLevelSetList::UP_TO_SYMFONY_54);

    // $containerConfigurator->import(SymfonySetList::SYMFONY_CODE_QUALITY);
    // $containerConfigurator->import(SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION);
    // $containerConfigurator->import(SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES);
};
