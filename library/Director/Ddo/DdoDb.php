<?php

namespace Icinga\Module\Director\Ddo;

use Icinga\Data\Db\DbConnection;

class DdoDb extends DbConnection
{
    public function isPgsql()
    {
        // TODO(tg/el): Not PostgreSQL support yet
        return false;
//        return $this->getDbType() === 'pgsql';
    }
}
