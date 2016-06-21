<?php

namespace Icinga\Module\Director\StateList;

class StateList
{
    protected $connection;

    protected $db;

    protected $objects;

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

        $object->processCheckResult($result);
        if ($object->hasBeenModified()) {
            $object->store();
        }
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
        if (property_exists($result, 'service')) {
            $key .= '!' . $result->service;
        }

        return sha1($key, true);
    }
}
