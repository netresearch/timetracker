<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContractRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user contract (working hours).
 */
#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'contracts')]
class Contract
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: 'User', inversedBy: 'contracts')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    protected $user;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    protected $start;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    protected $end;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_0;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_1;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_2;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_3;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_4;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_5;

    #[ORM\Column(type: Types::FLOAT)]
    protected $hours_6;

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return $this
     */
    public function setId(int $id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int $id
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Set user.
     *
     * @return $this
     */
    public function setUser(User $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user.
     *
     * @return User $user
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return mixed
     */
    public function getStart(): mixed
    {
        return $this->start;
    }

    /**
     * @param mixed $start
     *
     * @return $this
     */
    public function setStart($start)
    {
        $this->start = $start;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getEnd(): mixed
    {
        return $this->end;
    }

    /**
     * @param mixed $end
     *
     * @return $this
     */
    public function setEnd($end)
    {
        $this->end = $end;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours0(): float
    {
        return $this->hours_0;
    }

    /**
     * @param float $hours_0
     *
     * @return $this
     */
    public function setHours0(float $hours_0)
    {
        $this->hours_0 = $hours_0;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours1(): float
    {
        return $this->hours_1;
    }

    /**
     * @param float $hours_1
     *
     * @return $this
     */
    public function setHours1(float $hours_1)
    {
        $this->hours_1 = $hours_1;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours2(): float
    {
        return $this->hours_2;
    }

    /**
     * @param float $hours_2
     *
     * @return $this
     */
    public function setHours2(float $hours_2)
    {
        $this->hours_2 = $hours_2;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours3(): float
    {
        return $this->hours_3;
    }

    /**
     * @param float $hours_3
     *
     * @return $this
     */
    public function setHours3(float $hours_3)
    {
        $this->hours_3 = $hours_3;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours4(): float
    {
        return $this->hours_4;
    }

    /**
     * @param float $hours_4
     *
     * @return $this
     */
    public function setHours4(float $hours_4)
    {
        $this->hours_4 = $hours_4;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours5(): float
    {
        return $this->hours_5;
    }

    /**
     * @param float $hours_5
     *
     * @return $this
     */
    public function setHours5(float $hours_5)
    {
        $this->hours_5 = $hours_5;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours6(): float
    {
        return $this->hours_6;
    }

    /**
     * @param float $hours_6
     *
     * @return $this
     */
    public function setHours6(float $hours_6)
    {
        $this->hours_6 = $hours_6;

        return $this;
    }
}
