<?php

namespace Icinga\Module\Director\Cli;

use Icinga\Module\Director\Ddo\DdoDb;

class DdoCommand extends Command
{
    private $ddo;

    protected function ddo()
    {
        if ($this->ddo === null) {
            $resourceName = $this->Config()->get('ddo', 'resource');
            if ($resourceName) {
                $this->ddo = DdoDb::fromResourceName($resourceName);
            } else {
                $this->fail('DDO is not configured correctly');
            }
        }

        return $this->ddo;
    }
}
