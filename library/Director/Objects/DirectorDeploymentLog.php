<?php

namespace Icinga\Module\Director\Objects;

use Icinga\Module\Director\Data\Db\DbObject;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\IcingaConfig\IcingaConfig;
use Icinga\Module\Director\Util;

class DirectorDeploymentLog extends DbObject
{
    protected $table = 'director_deployment_log';

    protected $keyName = 'id';

    protected $autoincKeyName = 'id';

    protected $config;

    protected $defaultProperties = array(
        'id'                     => null,
        'config_checksum'        => null,
        'last_activity_checksum' => null,
        'peer_identity'          => null,
        'start_time'             => null,
        'end_time'               => null,
        'abort_time'             => null,
        'duration_connection'    => null,
        'duration_dump'          => null,
        'stage_name'             => null,
        'stage_collected'        => null,
        'connection_succeeded'   => null,
        'dump_succeeded'         => null,
        'startup_succeeded'      => null,
        'username'               => null,
        'startup_log'            => null,
    );

    public function getConfigHexChecksum()
    {
        return Util::binary2hex($this->config_checksum);
    }

    public function getConfig()
    {
        if ($this->config === null) {
            $this->config = IcingaConfig::load($this->config_checksum, $this->connection);
        }

        return $this->config;
    }

    public function isPending()
    {
        return $this->dump_succeeded === 'y' && $this->startup_log === null;
    }

    public function succeeded()
    {
        return $this->startup_succeeded === 'y';
    }

    public function configEquals(IcingaConfig $config)
    {
        return $this->config_checksum === $config->getChecksum();
    }

    public function getDeploymentTimestamp()
    {
        return strtotime($this->start_time);
    }

    public static function hasDeployments(Db $connection)
    {
        $db = $connection->getDbAdapter();
        $query = $db->select()->from(
            'director_deployment_log',
            array('c' => 'COUNT(*)')
        );

        return (int) $db->fetchOne($query) > 0;
    }

    public static function getConfigChecksumForStageName(Db $connection, $stage)
    {
        $db = $connection->getDbAdapter();
        $query = $db->select()
            ->from(
                array('l' => 'director_deployment_log'),
                array('c' => $connection->dbHexFunc('l.config_checksum'))
            )->where('l.stage_name = ?');

        return $db->fetchOne($query, $stage);
    }

    public static function loadLatest(Db $connection)
    {
        $db = $connection->getDbAdapter();
        $query = $db->select()->from(
            array('l' => 'director_deployment_log'),
            array('id' => 'MAX(l.id)')
        );

        return static::load($db->fetchOne($query), $connection);
    }

    /**
     * @return static[]
     */
    public static function getUncollected(Db $connection)
    {
        $db = $connection->getDbAdapter();
        $query = $db->select()
            ->from('director_deployment_log')
            ->where('stage_name IS NOT NULL')
            ->where('stage_collected IS NULL')
            ->where('startup_succeeded IS NULL')
            ->order('stage_name');

        return static::loadAll($connection, $query, 'stage_name');
    }

    public static function hasUncollected(Db $connection)
    {
        $db = $connection->getDbAdapter();
        $query = $db->select()
            ->from('director_deployment_log', ['cnt' => 'COUNT(*)'])
            ->where('stage_name IS NOT NULL')
            ->where('stage_collected IS NULL')
            ->where('startup_succeeded IS NULL');

        return $db->fetchOne($query) > 0;
    }
}
