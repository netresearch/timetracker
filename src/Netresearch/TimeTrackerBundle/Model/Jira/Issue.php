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
class Issue
{

    /**
     * The issue data.
     *
     * @var array
     */
    protected $data = array();


    /**
     * Jira_Issue constructor
     *
     *  @param array $arData the issue data
     *                       <code>
     *                         array {
     *                          "expand" => "operations,versionedRepresentations,editmeta,changelog,renderedFields",
     *                           "id"     => "64380",
     *                           "self"   => string(50) "https://jira.netresearch.de/rest/api/2/issue/64380",
     *                           "key"    => "OPSA-9",
     *                          }
     *                       <code>
     *
     */
    public function __construct(array $arData)
    {
        $this->data = $arData;
    }


    /**
     * Function to retrieve values.
     *
     * @param string $strName the key to retrieve
     *
     * @return mixed|null
     */
    public function __get($strName)
    {
        if (!isset($this->data[$strName])) {
            return null;
        }

        return $this->data[$strName];
    }


    /**
     * Returns the key of the issue.
     *
     * @return mixed|null
     */
    public function getKey()
    {
        return $this->key;
    }


    /**
     * Returns true, if there ar errors present for the issue response.
     *
     * @return bool
     */
    public function hasErrors()
    {
        return is_array($this->errors);
    }


    /**
     * Returns the content of the error message delivered by this issue.
     *
     * @return string
     */
    public function getErrorMessage()
    {
        if (false === $this->hasErrors()) {
            return;
        }

        return json_encode($this->errors);

    }

}

?>
