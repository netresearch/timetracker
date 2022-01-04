<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user contract (working hours)
 *
 * @ORM\Entity(repositoryClass="App\Repository\ContractRepository")
 * @ORM\Table(name="contracts")
 */
class Contract
{

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;


    /**
     * @ORM\ManyToOne(targetEntity="User", inversedBy="contracts")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
     */
    protected $user;


    /**
     * @ORM\Column(type="date")
     */
    protected $start;


    /**
     * @ORM\Column(type="date")
     */
    protected $end;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_0;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_1;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_2;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_3;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_4;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_5;


    /**
     * @ORM\Column(type="float")
     */
    protected $hours_6;


    /**
     * Set id
     *
     * @param integer $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }


    /**
     * Get id
     *
     * @return integer $id
     */
    public function getId()
    {
        return $this->id;
    }


    /**
     * Set user
     *
     *
     * @return $this
     */
    public function setUser(User $user)
    {
        $this->user = $user;
        return $this;
    }


    /**
     * Get user
     *
     * @return User $user
     */
    public function getUser()
    {
        return $this->user;
    }


    /**
     * @return mixed
     */
    public function getStart()
    {
        return $this->start;
    }


    /**
     * @param mixed $start
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
    public function getEnd()
    {
        return $this->end;
    }


    /**
     * @param mixed $end
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
    public function getHours0()
    {
        return $this->hours_0;
    }


    /**
     * @param float $hours_0
     * @return $this
     */
    public function setHours0($hours_0)
    {
        $this->hours_0 = $hours_0;
        return $this;
    }


    /**
     * @return float
     */
    public function getHours1()
    {
        return $this->hours_1;
    }


    /**
     * @param float $hours_1
     * @return $this
     */
    public function setHours1($hours_1)
    {
        $this->hours_1 = $hours_1;
        return $this;
    }


    /**
     * @return float
     */
    public function getHours2()
    {
        return $this->hours_2;
    }


    /**
     * @param float $hours_2
     * @return $this
     */
    public function setHours2($hours_2)
    {
        $this->hours_2 = $hours_2;
        return $this;
    }


    /**
     * @return float
     */
    public function getHours3()
    {
        return $this->hours_3;
    }


    /**
     * @param float $hours_3
     * @return $this
     */
    public function setHours3($hours_3)
    {
        $this->hours_3 = $hours_3;
        return $this;
    }


    /**
     * @return float
     */
    public function getHours4()
    {
        return $this->hours_4;
    }


    /**
     * @param float $hours_4
     * @return $this
     */
    public function setHours4($hours_4)
    {
        $this->hours_4 = $hours_4;
        return $this;
    }


    /**
     * @return float
     */
    public function getHours5()
    {
        return $this->hours_5;
    }


    /**
     * @param float $hours_5
     * @return $this
     */
    public function setHours5($hours_5)
    {
        $this->hours_5 = $hours_5;
        return $this;
    }


    /**
     * @return float
     */
    public function getHours6()
    {
        return $this->hours_6;
    }


    /**
     * @param float $hours_6
     * @return $this
     */
    public function setHours6($hours_6)
    {
        $this->hours_6 = $hours_6;
        return $this;
    }
}
