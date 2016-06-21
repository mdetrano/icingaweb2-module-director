<?php

namespace Icinga\Module\Director\Ddo;

abstract class StateObject extends DdoObject
{
    const FLAG_DOWNTIME   = 1;
    const FLAG_ACK        = 2;
    const FLAG_HOST_ISSUE = 4;
    const FLAG_NONE       = 8;
    const SHIFT_FLAGS     = 4;

    const ICINGA_OK          = 0;
    const ICINGA_WARNING     = 1;
    const ICINGA_CRITICAL    = 2;
    const ICINGA_UNKNOWN     = 3;
    const ICINGA_UP          = 0;
    const ICINGA_DOWN        = 1;
    const ICINGA_UNREACHABLE = 2;
    const ICINGA_PENDING     = 99;

    protected static $hostStateSortMap = array(
        self::ICINGA_PENDING     => 1,
        self::ICINGA_UNREACHABLE => 3,
        self::ICINGA_DOWN        => 4,
        self::ICINGA_UP          => 0,
    );

    protected static $serviceStateSortMap = array(
        self::ICINGA_PENDING  => 1,
        self::ICINGA_UNKNOWN  => 3,
        self::ICINGA_CRITICAL => 4,
        self::ICINGA_WARNING  => 2,
        self::ICINGA_OK       => 0,
    );

    public function processCheckResult($result)
    {
        $checkResult = $result->check_result;
        $vars = $checkResult->vars_after;

        $this->state      = $checkResult->state;
        $this->state_type = $vars->state_type;
        $this->reachable  = $vars->reachable;
        $this->attempt    = $vars->attempt;
        $this->severity   = $this->calculateSeverity();
    }

    /*
    // Draft for history updates
    public function storeStateChange()
    {
        $this->db->insert('state_history', array(
            'timestamp' => $this->timestamp,
            'state'     => '',
            '' => '',
            '' => '',
        ));
    }
    */

    /*
    // Draft, showing how we could deal with sla history
    public function refreshSlaTable()
    {
        $db = $this->db;

        'UPDATE sla_table SET duration = ? - start_time, end_time = ?'
        . ' WHERE object_checksum = ? AND end_time = ?',
        $this->timestamp,
        $this->checksum,
        self::TIME_INFINITY

        $db->insert(
            'sla_table',
            array(
                'object_checksum' => $this->checksum,
                'acknowledged'    => $this->acknowledged,
                'in_downtime'     => $this->in_downtime,
            )
        );
    }
    */

    protected function calculateSeverity()
    {
        $sev = $this->getSortingState() << self::SHIFT_FLAGS
            + ($this->isInDowntime() ? self::FLAG_DOWNTIME : 0)
            + ($this->isAcknowledged() ? self::FLAG_ACK : 0);

        if (! ($sev & (self::FLAG_DOWNTIME | self::FLAG_ACK))) {
            $sev |= self::FLAG_NONE;
        }

        return $sev;
    }

    protected function isInDowntime()
    {
        return $this->get('in_downtime') === 'y';
    }

    protected function isAcknowledged()
    {
        return $this->get('acknowledged') === 'y';
    }

    abstract protected function getSortingState();
}
