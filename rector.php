<?php

declare(strict_types=1);

use Rector\Core\Configuration\Option;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Symfony\Set\SymfonyLevelSetList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Doctrine\Set\DoctrineSetList;


return static function (ContainerConfigurator $containerConfigurator): void {
    // region Symfony Container
    $parameters = $containerConfigurator->parameters();
    $parameters->set(Option::PATHS, [
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);
    // $parameters->set(
    //     Option::SYMFONY_CONTAINER_XML_PATH_PARAMETER,
    //     __DIR__ . '/var/cache/dev/App_KernelDevDebugContainer.xml'
    // );
    // endregion

    // run them, one by one

    // PHP
    //$containerConfigurator->import(LevelSetList::UP_TO_PHP_81);

    // Symfony
    //$containerConfigurator->import(SymfonySetList::SYMFONY_50_TYPES);
    //$containerConfigurator->import(SymfonySetList::SYMFONY_52_VALIDATOR_ATTRIBUTES);
    //$containerConfigurator->import(SymfonySetList::SYMFONY_54);

    //$containerConfigurator->import(SymfonySetList::SYMFONY_STRICT);

    //$containerConfigurator->import(SymfonyLevelSetList::UP_TO_SYMFONY_60);

    //$containerConfigurator->import(SymfonySetList::SYMFONY_CODE_QUALITY);
    //$containerConfigurator->import(SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION);
    //$containerConfigurator->import(SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES);

    // Doctrine
    //$containerConfigurator->import(DoctrineSetList::DOCTRINE_25);
    //$containerConfigurator->import(DoctrineSetList::DOCTRINE_BEHAVIORS_20);
    //FAILED: $containerConfigurator->import(DoctrineSetList::DOCTRINE_CODE_QUALITY);
    $containerConfigurator->import(DoctrineSetList::DOCTRINE_COMMON_20);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_DBAL_210);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_DBAL_211);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_DBAL_30);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_GEDMO_TO_KNPLABS);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_REPOSITORY_AS_SERVICE);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_ORM_29);
    // $containerConfigurator->import(DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES);
    // $containerConfigurator->import(DoctrineSetList::DOCTRINE_ODM_23);
};
