<?php

namespace Icinga\Module\Director\Ddo;

use Icinga\Module\Director\Data\Db\DbObject;
use Icinga\Exception\ProgrammingError;

abstract class DdoObject extends DbObject
{
    protected $booleans = array();

    protected $timestamps = array();

    public function set($key, $value)
    {
        if ($this->propertyIsBoolean($key)) {
            return parent::set($key, $this->normalizeBoolean($value));
        }

        if ($this->propertyIsTimestamp($key)) {
            return parent::set($key, $this->normalizeTimestamp($value));
        }

        return parent::set($key, $value);
    }

    public function propertyIsBoolean($property)
    {
        return in_array($property, $this->booleans);
    }

    public function propertyIsTimestamp($property)
    {
        return in_array($property, $this->timestamps);
    }

    protected function normalizeTimestamp($value)
    {
        return (int) round($value * 1000000);
    }

    protected function normalizeBoolean($value)
    {
        if ($value === 'y' || $value === '1' || $value === true || $value === 1) {
            return 'y';
        } elseif ($value === 'n' || $value === '0' || $value === false || $value === 0) {
            return 'n';
        } elseif ($value === '' || $value === null) {
            return null;
        } else {
            throw new ProgrammingError(
                'Got invalid boolean: %s',
                var_export($value, 1)
            );
        }
    }
}
