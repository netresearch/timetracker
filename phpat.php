<?php

declare(strict_types=1);

use PHPat\Selector\Selector;
use PHPat\Test\Builder\RuleBuilder;

return RuleBuilder::create()
    
    // Controllers should only depend on business logic and framework components
    ->rule(
        RuleBuilder::TYPE_CAN_ONLY_DEPEND_ON
    )
    ->classesThat(Selector::haveClassName('App\Controller\*'))
    ->canOnlyDependOn()
    ->classesThat(Selector::haveClassName('App\Entity\*'))
    ->andClassesThat(Selector::haveClassName('App\Service\*'))
    ->andClassesThat(Selector::haveClassName('App\Dto\*'))
    ->andClassesThat(Selector::haveClassName('App\Enum\*'))
    ->andClassesThat(Selector::haveClassName('App\Event\*'))
    ->andClassesThat(Selector::haveClassName('Symfony\*'))
    ->andClassesThat(Selector::haveClassName('Doctrine\*'))
    ->andClassesThat(Selector::haveClassName('Sensio\*'))
    ->andClassesThat(Selector::haveClassName('DateTime*'))
    ->andClassesThat(Selector::haveClassName('Exception'))
    ->andClassesThat(Selector::haveClassName('*Exception'))
    ->because('Controllers should only depend on business logic and Symfony/Doctrine components')
    
    // Entities should be pure data models with minimal dependencies
    ->rule(
        RuleBuilder::TYPE_CAN_ONLY_DEPEND_ON
    )
    ->classesThat(Selector::haveClassName('App\Entity\*'))
    ->canOnlyDependOn()
    ->classesThat(Selector::haveClassName('App\Enum\*'))
    ->andClassesThat(Selector::haveClassName('Doctrine\*'))
    ->andClassesThat(Selector::haveClassName('Symfony\Component\Validator\*'))
    ->andClassesThat(Selector::haveClassName('DateTimeInterface'))
    ->andClassesThat(Selector::haveClassName('DateTime*'))
    ->because('Entities should be pure data models with minimal dependencies')
    
    // Services should handle business logic and orchestration
    ->rule(
        RuleBuilder::TYPE_CAN_ONLY_DEPEND_ON
    )
    ->classesThat(Selector::haveClassName('App\Service\*'))
    ->canOnlyDependOn()
    ->classesThat(Selector::haveClassName('App\Entity\*'))
    ->andClassesThat(Selector::haveClassName('App\Repository\*'))
    ->andClassesThat(Selector::haveClassName('App\Dto\*'))
    ->andClassesThat(Selector::haveClassName('App\Enum\*'))
    ->andClassesThat(Selector::haveClassName('App\Event\*'))
    ->andClassesThat(Selector::haveClassName('App\Service\*'))
    ->andClassesThat(Selector::haveClassName('Symfony\*'))
    ->andClassesThat(Selector::haveClassName('Doctrine\*'))
    ->andClassesThat(Selector::haveClassName('Psr\*'))
    ->andClassesThat(Selector::haveClassName('GuzzleHttp\*'))
    ->andClassesThat(Selector::haveClassName('DateTime*'))
    ->andClassesThat(Selector::haveClassName('Exception'))
    ->andClassesThat(Selector::haveClassName('*Exception'))
    ->because('Services should handle business logic and orchestration')
    
    // Repositories should only handle data access
    ->rule(
        RuleBuilder::TYPE_CAN_ONLY_DEPEND_ON
    )
    ->classesThat(Selector::haveClassName('App\Repository\*'))
    ->canOnlyDependOn()
    ->classesThat(Selector::haveClassName('App\Entity\*'))
    ->andClassesThat(Selector::haveClassName('App\Enum\*'))
    ->andClassesThat(Selector::haveClassName('Doctrine\*'))
    ->andClassesThat(Selector::haveClassName('Symfony\Component\Security\*'))
    ->andClassesThat(Selector::haveClassName('DateTime*'))
    ->andClassesThat(Selector::haveClassName('Exception'))
    ->andClassesThat(Selector::haveClassName('*Exception'))
    ->because('Repositories should only handle data access')
    
    // Controllers must not directly access repositories (should use services)
    ->rule(
        RuleBuilder::TYPE_SHOULD_NOT_DEPEND_ON
    )
    ->classesThat(Selector::haveClassName('App\Controller\*'))
    ->shouldNotDependOn()
    ->classesThat(Selector::haveClassName('App\Repository\*'))
    ->because('Controllers should use Services, not Repositories directly')
    
    ->build();