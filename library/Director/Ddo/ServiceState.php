<?php

namespace Icinga\Module\Director\Ddo;

class ServiceState extends StateObject
{
    protected $table = 'service_state';

    protected $keyName = 'checksum';

    protected $defaultProperties = array(
        // active ?
        'checksum'              => null,
        // 'host_checksum'         => null,
        'host'                  => null,
        'service'               => null,
        'state'                 => null,
        'hard_state'            => null,
        'state_type'            => null,
        'attempt'               => null,
        'problem'               => null,
        'reachable'             => null,
        'severity'              => null,
        'acknowledged'          => null,
        'in_downtime'           => null,
        'last_update'           => null, // only on store if modified
        'last_state_change'     => null,
        'last_comment_checksum' => null,
        'check_source_checksum' => null,
    );

    protected $booleans = array(
        'problem',
        'reachable',
        'acknowledged',
        'in_downtime'
    );

    protected $timestamps = array(
        'last_update',
        'last_state_change',
    );

    protected function calculateSeverity()
    {
        $sev = parent::calculateSeverity();

        // TODO: add host state to the mix

        return $sev;
    }

    protected function getSortingState()
    {
        return self::$serviceStateSortMap[$this->state];
    }
}
