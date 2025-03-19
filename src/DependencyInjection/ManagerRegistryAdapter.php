<?php

namespace App\DependencyInjection;

use Doctrine\Persistence\ManagerRegistry as NewManagerRegistry;
use Doctrine\Common\Persistence\ObjectManager;

/**
 * Compatibility layer between Doctrine ManagerRegistry interfaces
 * to support both legacy code and modern Symfony/Doctrine versions
 */
class ManagerRegistryAdapter implements NewManagerRegistry
{
    /**
     * @var NewManagerRegistry
     */
    private $registry;

    /**
     * @param NewManagerRegistry $registry
     */
    public function __construct(NewManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultConnectionName()
    {
        return $this->registry->getDefaultConnectionName();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection($name = null)
    {
        return $this->registry->getConnection($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getConnections()
    {
        return $this->registry->getConnections();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionNames()
    {
        return $this->registry->getConnectionNames();
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultManagerName()
    {
        return $this->registry->getDefaultManagerName();
    }

    /**
     * {@inheritdoc}
     */
    public function getManager($name = null)
    {
        return $this->registry->getManager($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getManagers()
    {
        return $this->registry->getManagers();
    }

    /**
     * {@inheritdoc}
     */
    public function resetManager($name = null)
    {
        return $this->registry->resetManager($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getManagerNames()
    {
        return $this->registry->getManagerNames();
    }

    /**
     * {@inheritdoc}
     */
    public function getRepository($persistentObject, $managerName = null)
    {
        return $this->registry->getRepository($persistentObject, $managerName);
    }

    /**
     * {@inheritdoc}
     */
    public function getManagerForClass($class)
    {
        return $this->registry->getManagerForClass($class);
    }

    /**
     * {@inheritdoc}
     */
    public function getAliasNamespace($alias)
    {
        return $this->registry->getAliasNamespace($alias);
    }

    /**
     * For backward compatibility with Doctrine\Common\Persistence\ObjectManager
     *
     * @return ObjectManager
     */
    public function getEntityManager()
    {
        return $this->registry->getManager();
    }
}
