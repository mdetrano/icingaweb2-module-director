<?php

namespace Icinga\Module\Director\Clicommands;

use Icinga\Application\Logger;
use Icinga\Data\ConfigObject;
use Icinga\Module\Director\Cli\Command;
use Icinga\Module\Director\Ddo\DdoDb;
use Icinga\Module\Director\Ddo\HostObject;

class DdoCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    public function init()
    {
        // Force stderr log writer. Too bad that we can't use our Logger as instance
        Logger::create(new ConfigObject(array(
            'log'  => 'stderr',
            'level' => 'debug'
        )));
    }

    /**
     * Get the connection to the DDO database
     *
     * @return \Icinga\Module\Director\Ddo\DdoDb
     */
    public function getDb()
    {
        return DdoDb::fromResourceName('ddo');
    }

    public function captureAction()
    {
        $sleepSeconds = 60;

        $api = $this->api();
        /** @var \Icinga\Module\Director\Core\CoreApi $api */

        $db = $this->getDb();

        $ddoHosts = HostObject::loadAll($db, null, 'checksum');
        $ddoHostsCount = count($ddoHosts);

        while (true) {
            $union = array();
            $unionCount = 0;

            $apiHosts = $api->getObjects(null, 'hosts');

            foreach ($apiHosts as $name => $apiHost) {
                $ddoHost = HostObject::fromApiObject($name, $apiHost, $db);

                if (isset($ddoHosts[$ddoHost->checksum])) {
                    $ddoHost->merge($ddoHosts[$ddoHost->checksum]);

                    $union[$ddoHost->checksum] = null;
                    ++$unionCount;
                }

                if ($ddoHost->hasBeenModified()) {
                    if (! $ddoHost->hasBeenLoadedFromDb()) {
                        $ddoHosts[$ddoHost->checksum] = $ddoHost;
                        ++$ddoHostsCount;
                        ++$unionCount;
                    }
                    Logger::debug('Updating host object %s', $name);
                    $ddoHost->store();
                }
            }

            if ($ddoHostsCount !== $unionCount) {
                $diff = array_diff_key($ddoHosts, $union);
                foreach ($diff as $checksum => $ddoHost) {
                    Logger::debug('Deleting host object %s', $ddoHost->name);
                    /** @var HostObject $ddoHost */
                    $ddoHost->delete();
                    unset($ddoHosts[$checksum]);
                }
                $ddoHostsCount = count($ddoHosts);
            }

            Logger::debug('Sleeping %u seconds', $sleepSeconds);

            sleep($sleepSeconds);
        }
    }
}
