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
        $this->objects = array_merge(
            HostState::loadAll($this->connection, null, 'checksum'),
            ServiceState::loadAll($this->connection, null, 'checksum')
        );
    }

    public function processCheckResult($result)
    {
        $type = $result->type;
        $types = array(
            'CheckResult'            => 'check_result',
            'StateChange'            => 'check_result',
            'Notification',
            'AcknowledgementSet'     => null,
            'AcknowledgementCleared' => null,
            'CommentAdded'           => 'comment',
            'CommentRemoved'         => 'comment',
            'DowntimeAdded'          => 'downtime',
            'DowntimeRemoved'        => 'downtime',
            'DowntimeTriggered'      => 'downtime',
        );

        if (! array_key_exists($type, $types)) {
            var_dump($type);
            var_dump($result);
            die('Type incomplete');
        }

        $eventProperty = $types[$type];
        $eventData = $eventProperty === null ? $result : $result->$eventProperty;

        list($host, $service) = $this->getHostServiceFromResult($result, $eventProperty);

        if ($host === null) {
            echo "Event has NO HOST\n";
            var_dump($type);
            var_dump($result);

            return false;
        }

        $key = self::createKey($host, $service);

        if ($this->hasKey($key)) {
            $object = $this->getObject($key);
        } else {
            $object = $this->createObject($host, $service, $key);
        }

        $this->objects[$key] = $object;

        $method = 'process' . $type;
        if (method_exists($object, $method)) {
            $object->$method($eventData, $result->timestamp);
        }

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

    protected function getHostServiceFromResult($result, $eventProperty)
    {
        if (property_exists($result, 'host')) {
            $list = array($result->host);
        } elseif (property_exists($result->$eventProperty, 'host_name')) {
            $list = array($result->$eventProperty->host_name);
        } else {
            return array(null, null);
        }

        if (property_exists($result, 'service')) {
            $list[] = $result->service;
        } elseif (property_exists($result, 'service_name')) {
            $list[] = $result->$eventProperty->service_name;
        } else {
            $list[] = null;
        }

        if (count($list) === 2 && $list[1] === '') {
            $list[1] = null;
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
