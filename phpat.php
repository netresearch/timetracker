<?php

declare(strict_types=1);

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ArchitectureTest
{
    public function test_controllers_should_only_depend_on_business_logic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Controller'))
            ->canOnlyDependOn()
            ->classes(
                Selector::inNamespace('App\Entity'),
                Selector::inNamespace('App\Service'),
                Selector::inNamespace('App\Dto'),
                Selector::inNamespace('App\Enum'),
                Selector::inNamespace('App\Event'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Sensio'),
                Selector::classname('DateTime*', true),
                Selector::classname('*Exception', true),
            )
            ->because('Controllers should only depend on business logic and framework components');
    }

    public function test_entities_should_be_pure_data_models(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Entity'))
            ->canOnlyDependOn()
            ->classes(
                Selector::inNamespace('App\Enum'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Symfony\Component\Validator'),
                Selector::classname('DateTimeInterface'),
                Selector::classname('DateTime*', true),
            )
            ->because('Entities should be pure data models with minimal dependencies');
    }

    public function test_services_can_orchestrate_business_logic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Service'))
            ->canOnlyDependOn()
            ->classes(
                Selector::inNamespace('App\Entity'),
                Selector::inNamespace('App\Repository'),
                Selector::inNamespace('App\Dto'),
                Selector::inNamespace('App\Enum'),
                Selector::inNamespace('App\Event'),
                Selector::inNamespace('App\Service'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Psr'),
                Selector::inNamespace('GuzzleHttp'),
                Selector::classname('DateTime*', true),
                Selector::classname('*Exception', true),
            )
            ->because('Services should handle business logic and orchestration');
    }

    public function test_repositories_should_only_handle_data_access(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Repository'))
            ->canOnlyDependOn()
            ->classes(
                Selector::inNamespace('App\Entity'),
                Selector::inNamespace('App\Enum'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Symfony\Component\Security'),
                Selector::classname('DateTime*', true),
                Selector::classname('*Exception', true),
            )
            ->because('Repositories should only handle data access');
    }

    public function test_controllers_must_not_directly_access_repositories(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Controller'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\Repository'))
            ->because('Controllers should use Services, not Repositories directly');
    }
}
