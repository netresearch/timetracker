<?php

namespace Netresearch\TimeTrackerBundle\Model;

class JiraWorklog
{
    /* 
     * DATE_FORMAT = 'Y-m-d\TH:i:s.000Z0200';
     */
    const DATE_FORMAT = 'c';


    protected $author;

    protected $comment;

    protected $created;

    protected $groupLevel;

    protected $id;

    protected $roleLevelId;

    protected $startDate;

    protected $timeSpent;

    protected $timeSpentInSeconds;

    protected $updateAuthor;

    protected $updated;


    /*
     * Format time
     */
    public static function formatTime($time)
    {
        $time = (int) $time;

        if (1 > floor($time / 60)) {
            return '0m';
        }

        $days = floor($time / (3600 * 8));
        $time -= ($days * 3600 * 8);
        $hours = floor($time / 3600);
        $time -= ($hours * 3600);
        $minutes = floor($time / 60);

        return ($days ? $days . 'd' : '')
            . ($days && $hours ? ' ' : '')
            . ($hours ? $hours . 'h' : '')
            . (($hours || $days) && $minutes ? ' ' : '')
            . ($minutes ? $minutes . 'm' : '');
    }


    public function getAuthor() {
        return $this->author;
    }

    public function setAuthor($author) {
        $this->author = $author;
        return $this;
    }


    public function getComment() {
        return $this->comment;
    }

    public function setComment($comment) {
        $this->comment = $comment;
        return $this;
    }

    public function getCreated() {
        return $this->created;
    }

    public function setCreated($created) {
        $this->created = $created;
        return $this;
    }

    public function getGroupLevel() {
        return $this->groupLevel;
    }

    public function setGroupLevel($groupLevel) {
        $this->groupLevel = $groupLevel;
        return $this;
    }

    public function getId() {
        return $this->id;
    }

    public function setId($id) {
        $this->id = $id;
        return $this;
    }

    public function getRoleLevelId() {
        return $this->roleLevelId;
    }

    public function setRoleLevelId($roleLevelId) {
        $this->roleLevelId = $roleLevelId;
        return $this;
    }

    public function getStartDate() {
        return $this->startDate;
    }

    public function setStartDate($startDate) {
        $this->startDate = $startDate;
        return $this;
    }

    public function getTimeSpent() {
        return $this->timeSpent;
    }

    public function setTimeSpent($timeSpent) {
        $this->timeSpent = $timeSpent;
        return $this;
    }

    public function getTimeSpentInSeconds() {
        return $this->timeSpentInSeconds;
    }

    public function setTimeSpentInSeconds($timeSpentInSeconds) {
        $this->timeSpentInSeconds = $timeSpentInSeconds;
        return $this;
    }

    public function getUpdateAuthor() {
        return $this->updateAuthor;
    }

    public function setUpdateAuthor($updateAuthor) {
        $this->updateAuthor = $updateAuthor;
        return $this;
    }

    public function getUpdated() {
        return $this->updated;
    }

    public function setUpdated($updated) {
        $this->updated = $updated;
        return $this;
    }

}

