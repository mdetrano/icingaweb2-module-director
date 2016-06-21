<?php

namespace Icinga\Module\Director\Ddo;

use Icinga\Data\Db\DbConnection;

// TODO(el): Respect ctime and mtime columns w/o influencing the hasBeenModified magic

/**
 * A DDO host object
 */
class HostObject extends DdoObject
{
    /**
     * {@inheritdoc}
     */
//    protected $timestamps = array('ctime', 'mtime');

    /**
     * {@inheritdoc}
     */
    protected $table = 'ddo_host';

    /**
     * {@inheritdoc}
     */
    protected $keyName = 'checksum';

    /**
     * {@inheritdoc}
     */
    protected $defaultProperties = array(
        'checksum'                  => null,
        'name'                      => null,
        'name_ci'                   => null,
        'label'                     => null,
        'action_url'                => null,
        'notes_url'                 => null,
        'address'                   => null,
        'address6'                  => null,
        'address_bin'               => null,
        'address6_bin'              => null,
        'active_checks_enabled'     => null,
        'event_handler_enabled'     => null,
        'flapping_enabled'          => null,
        'notifications_enabled'     => null,
        'passive_checks_enabled'    => null,
        'perfdata_enabled'          => null,
        'check_command'             => null,
        'check_interval'            => null,
        'check_retry_interval'      => null
    );

    /**
     * Create a DDO host object from an Icinga 2 API host object
     *
     * @param   string          $name       The name of the object
     * @param   object          $apiObject  The API object record
     * @param   DbConnection    $connection The connection to the DDO database
     *
     * @return  static
     */
    public static function fromApiObject($name, $apiObject, DbConnection $connection = null)
    {
        $properties = array(
            'checksum'                  => hex2bin(sha1($name)),
            'name'                      => $name,
            'name_ci'                   => $name,
            'label'                     => $apiObject->attrs->display_name,
            'action_url'                => $apiObject->attrs->action_url,
            'notes_url'                 => $apiObject->attrs->notes_url,
            'address'                   => $apiObject->attrs->address,
            'address6'                  => $apiObject->attrs->address6,
            'address_bin'               => $apiObject->attrs->address,
            'address6_bin'              => $apiObject->attrs->address6,
            'active_checks_enabled'     => $apiObject->attrs->enable_active_checks,
            'event_handler_enabled'     => $apiObject->attrs->enable_event_handler,
            'flapping_enabled'          => $apiObject->attrs->enable_flapping,
            'notifications_enabled'     => $apiObject->attrs->enable_notifications,
            'passive_checks_enabled'    => $apiObject->attrs->enable_passive_checks,
            'perfdata_enabled'          => $apiObject->attrs->enable_perfdata,
            'check_command'             => $apiObject->attrs->check_command,
            'check_interval'            => $apiObject->attrs->check_interval,
            'check_retry_interval'      => $apiObject->attrs->retry_interval,
        );

        return static::create($properties, $connection);
    }

    /**
     * {@inheritdoc}
     * Interpret properties ending w/ _enabled as boolean
     */
    public function propertyIsBoolean($property)
    {
        if (substr($property, -8) === '_enabled') {
            return true;
        }
        return parent::propertyIsBoolean($property);
    }

    public function getAddressBin()
    {
        $value = $this->properties['address_bin'];
        if ($value !== null) {
            $value = inet_ntop($value);
        }

        return $value;
    }

    public function setAddressBin($address)
    {
        if (! empty($address)) {
            $value = @inet_pton($address);
            if ($value === false) {
                $value = null;
            }
        } else {
            $value = null;
        }
        return $this->reallySet('address_bin', $value);
    }

    public function getAddress6Bin()
    {
        $value = $this->properties['address6_bin'];
        if ($value !== null) {
            $value = inet_ntop($value);
        }

        return $value;
    }

    public function setAddress6Bin($address6)
    {
        if (! empty($address6)) {
            $value = @inet_pton($address6);
            if ($value === false) {
                $value = null;
            }
        } else {
            $value = null;
        }

        return $this->reallySet('address6_bin', $value);
    }
}

