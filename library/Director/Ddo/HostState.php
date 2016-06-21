<?php

namespace Icinga\Module\Director\Ddo;

class HostState extends StateObject
{
    protected $table = 'ddo_host_state';

    protected $keyName = 'checksum';

    protected $defaultProperties = array(
        // active ?
        'checksum'              => null,
        'host'                  => null,
        'state'                 => null,
        'state_type'            => null,
        'hard_state'            => null,
        'severity'              => null,
        'acknowledged'          => null,
        'in_downtime'           => null,
        'last_update'           => null, // only on store if modified
        'last_state_change'     => null,
        'last_comment_checksum' => null,
        'attempt'               => null,
        'check_source_checksum' => null,
    );

    protected function getSortingState()
    {
        return self::$hostStateSortMap[$this->state];
    }
}
