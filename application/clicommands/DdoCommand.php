<?php

namespace Icinga\Module\Director\Clicommands;

use Exception;
use Icinga\Application\Logger;
use Icinga\Data\ConfigObject;
use Icinga\Module\Director\Cli\Command;
use Icinga\Module\Director\Ddo\DdoDb;
use Icinga\Module\Director\Ddo\HostGroup;
use Icinga\Module\Director\Ddo\HostGroupMember;
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
        $sleepSeconds = 10;

        $api = $this->api();
        /** @var \Icinga\Module\Director\Core\CoreApi $api */

        $db = $this->getDb();

        $ddoHosts = HostObject::loadAll($db, null, 'checksum');
        $ddoHostGroups = HostGroup::loadAll($db, null, 'checksum');

        while (true) {
            $activeHosts = array();
            $activeHostGroups = array();

            $apiHostGroups = $api->getObjects(null, 'hostgroups');

            foreach ($apiHostGroups as $name => $apiHostGroup) {
                $ddoHostGroup = HostGroup::fromApiObject($name, $apiHostGroup, $db);

                $activeHostGroups[$ddoHostGroup->checksum] = null;

                if (isset($ddoHostGroups[$ddoHostGroup->checksum])) {
                    $ddoHostGroup->merge($ddoHostGroups[$ddoHostGroup->checksum]);
                }

                if ($ddoHostGroup->hasBeenModified()) {
                    Logger::debug('Updating host group %s', $name);
                    if (! $ddoHostGroup->hasBeenLoadedFromDb()) {
                        $ddoHostGroups[$ddoHostGroup->checksum] = $ddoHostGroup;
                    }
                    $ddoHostGroup->store();
                }
            }

            $apiHosts = $api->getObjects(null, 'hosts');

            foreach ($apiHosts as $name => $apiHost) {
                $ddoHost = HostObject::fromApiObject($name, $apiHost, $db);

                $activeHosts[$ddoHost->checksum] = null;


                if (isset($ddoHosts[$ddoHost->checksum])) {
                    $ddoHost->merge($ddoHosts[$ddoHost->checksum]);
                }

                if ($ddoHost->hasBeenModified()) {
                    Logger::debug('Updating host object %s', $name);
                    if (! $ddoHost->hasBeenLoadedFromDb()) {
                        $ddoHosts[$ddoHost->checksum] = $ddoHost;
                    }
                    $ddoHost->store();
                }

                foreach ($apiHost->attrs->groups as $hostGroup) {
                    $member = HostGroupMember::create(
                        array(
                            'host_group_checksum'   => hex2bin(sha1($hostGroup)),
                            'host_checksum'         => $ddoHost->checksum
                        ),
                        $db
                    );
                    try {
                        // Brute force atm
                        $member->store();
                        Logger::debug('Updating host group memberships for host %s', $name);
                    } catch (Exception $e) {
                        // TODO(el): Member cleanup
                        continue;
                    }
                }
            }

            $hostGroupDiff = array_diff_key($ddoHostGroups, $activeHostGroups);
            foreach ($hostGroupDiff as $checksum => $ddoHostGroup) {
                Logger::debug('Deleting host group %s', $ddoHostGroup->name);
                /** @var HostGroup $ddoHostGroup */
                $ddoHostGroup->delete();
                unset($ddoHostGroup[$checksum]);
            }

            $hostDiff = array_diff_key($ddoHosts, $activeHosts);
            foreach ($hostDiff as $checksum => $ddoHost) {
                Logger::debug('Deleting host object %s', $ddoHost->name);
                /** @var HostObject $ddoHost */
                $ddoHost->delete();
                unset($ddoHosts[$checksum]);
            }

            Logger::debug('Sleeping %u seconds', $sleepSeconds);

            sleep($sleepSeconds);
        }
    }
}
