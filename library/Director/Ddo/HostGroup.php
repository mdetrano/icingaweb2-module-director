<?php

namespace Icinga\Module\Director\Ddo;

use Icinga\Data\Db\DbConnection;

/**
 * A DDO host group
 */
class HostGroup extends DdoObject
{
    /**
     * {@inheritdoc}
     */
    protected $table = 'ddo_host_group';

    /**
     * {@inheritdoc}
     */
    protected $keyName = 'checksum';

    /**
     * The host note
     *
     * @var Note
     */
    protected $note;

    /**
     * {@inheritdoc}
     */
    protected $defaultProperties = array(
        'checksum'  => null,
        'name'      => null,
        'name_ci'   => null,
        'label'     => null
    );

    /**
     * Create a DDO host group from an Icinga 2 API host object
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
            'label'                     => $apiObject->attrs->display_name
        );

        return static::create($properties, $connection);
    }
}

