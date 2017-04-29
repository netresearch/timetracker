<?php
declare(encoding = 'UTF-8');

/**
 * COMMENT
 *
 * PHP version 5
 *
 * @category CAT
 * @package  PACK
 * @author   Tobias Hein <tobias.hein@netresearch.de>
 * @license  http://www.aida.de AIDA Copyright
 * @link     http://www.aida.de
 */

namespace Netresearch\TimeTrackerBundle\Model\Jira;

/**
 * COMMENT
 *
 * PHP version 5
 *
 * @category CAT
 * @package  PACK
 * @author   Tobias Hein <tobias.hein@netresearch.de>
 * @license  http://www.aida.de AIDA Copyright
 * @link     http://www.aida.de
 */
class Issues extends \Netresearch\TimeTrackerBundle\Model\Base
{

    protected $issues = array();

    /**
     * Jira_List_Issue constructor.
     *
     * @param $arData
     */
    public function __construct($arData)
    {
        if (empty($arData['issues'])) {
            return;
        }

        foreach ($arData['issues'] as $issue) {
            $this->issues[] = new Issue($issue);
        }
    }

    /**
     * Returns true, if the list contains at least one issue.
     *
     * @return bool
     */
    public function hasIssues()
    {
        return (bool) count($this->issues);
    }

    /**
     * Returns the first issue of the list.
     *
     * @return mixed|void
     */
    public function first()
    {
        if (false === $this->hasIssues()) {
            return;
        }

        return $this->issues[0];
    }
}

?>
