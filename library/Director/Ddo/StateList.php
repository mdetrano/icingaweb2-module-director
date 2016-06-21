<?php

namespace Icinga\Module\Director\Ddo;

class StateList
{
    protected $connection;

    protected $db;

    protected $objects = array();

    public function __construct(DdoDb $connection)
    {
        $this->connection = $connection;
        $this->db = $connection->getDbAdapter();
    }

    public function processCheckResult($result)
    {
        list($host, $service) = $this->getHostServiceFromResult($result);

        $key = self::createKey($host, $service);

        if ($this->hasKey($key)) {
            $object = $this->getObject($key);
        } else {
            $object = $this->createObject($host, $service, $key);
        }

        $this->objects[$key] = $object;

        $object->processCheckResult($result);

        return $object;
    }

    protected function createObject($host, $service, $key)
    {
        if ($service === null) {
            return HostState::create(array(
                'checksum' => $key,
                'host'     => $host,
            ), $this->connection);
        } else {
            return ServiceState::create(array(
                'checksum' => $key,
                'host'     => $host,
                'service'  => $service,
            ), $this->connection);
        }
    }

    protected function getObject($key)
    {
        return $this->objects[$key];
    }

    protected function hasKey($key)
    {
        return array_key_exists($key, $this->objects);
    }

    protected function getHostServiceFromResult($result)
    {
        $list = array($result->host);

        if (property_exists($result, 'service')) {
            $list[] = $result->service;
        } else {
            $list[] = null;
        }

        return $list;
    }

    protected function createKey($host, $service = null)
    {
        $key = $host;
        if ($service !== null) {
            $key .= '!' . $service;
        }

        return sha1($key, true);
    }
}
