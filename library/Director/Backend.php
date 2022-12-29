<?php

namespace Icinga\Module\Director;

use Icinga\Application\Icinga;
use Icinga\Authentication\Auth;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Director\Backend\MonitorBackend;
use Icinga\Module\Director\Backend\MonitorBackendIcingadb;
use Icinga\Module\Director\Backend\MonitorBackendMonitoring;

class Backend implements MonitorBackend
{
    const MONITORING = 'monitoring';
    const ICINGADB = 'icingadb';

    protected $backend = null;

    /**
     * @param string|null $backend_name backend to use, 'icingadb' or 'monitoring'
     *                                  <code>null</code> will use either, preferring icingadb
     */
    public function __construct($backend_name = null)
    {
        $app = Icinga::app();
        $modules = $app->getModuleManager();

        $tried_loading = false;
        if (is_null($backend_name) || ($backend_name == self::ICINGADB)) {
            if (!$modules->hasLoaded(self::ICINGADB) && $app->isCli()) {
                $modules->loadEnabledModules();
                $tried_loading = true;
            }

            if ($modules->hasLoaded(self::ICINGADB)) {
                $this->backend = new MonitorBackendIcingadb();
            }
        }

        if (is_null($this->backend)
            && (is_null($backend_name) || ($backend_name == self::MONITORING))) {
            if (!$tried_loading && !$modules->hasLoaded(self::MONITORING) && $app->isCli()) {
                $modules->loadEnabledModules();
            }

            if ($modules->hasLoaded(self::MONITORING)) {
                $this->backend = new MonitorBackendMonitoring();
            }
        }
    }

    public function isAvailable()
    {
        return (($this->backend !== null) && ($this->backend->isAvailable()));
    }

    public function hasHost($hostname)
    {
        return (($this->backend !== null) && $this->backend->hasHost($hostname));
    }

    public function hasHostWithExtraFilter($hostname, Filter $filter)
    {
        return (($this->backend !== null) && $this->backend->hasHostWithExtraFilter($hostname, $filter));
    }

    public function hasService($hostname, $service)
    {
        return (($this->backend !== null) && $this->backend->hasService($hostname, $service));
    }

    public function hasServiceWithExtraFilter($hostname, $service, Filter $filter)
    {
        return (($this->backend !== null)
            && $this->backend->hasServiceWithExtraFilter($hostname, $service, $filter));
    }

    public function authCanEditHost(Auth $auth, $hostname)
    {
        if ($auth->hasPermission('director/monitoring/hosts')) {
            $restriction = null;
            foreach ($auth->getRestrictions('director/monitoring/rw-object-filter') as $restriction) {
                if ($this->hasHostWithExtraFilter($hostname, Filter::fromQueryString($restriction))) {
                    return true;
                }
            }
            if ($restriction === null) {
                return $this->hasHost($hostname);
            }
        }

        return false;
    }

    public function authCanEditService(Auth $auth, $hostname, $service)
    {
        if ($hostname === null || $service === null) {
            // TODO: UUID support!
            return false;
        }
        if ($auth->hasPermission('director/monitoring/services')) {
            $restriction = null;
            foreach ($auth->getRestrictions('director/monitoring/rw-object-filter') as $restriction) {
                if ($this->hasServiceWithExtraFilter($hostname, $service, Filter::fromQueryString($restriction))) {
                    return true;
                }
            }
            if ($restriction === null) {
                return $this->hasService($hostname, $service);
            }
        }

        return false;
    }

    public function getHostLink($title, $hostname, array $attributes = null)
    {
        if ($this->backend !== null) {
            return $this->backend->getHostLink($title, $hostname, $attributes);
        }
        return null;
    }

    public function getHostState($hostname)
    {
        if ($this->backend === null) {
            return (object) [
                'hostname'     => $hostname,
                'state'        => '99',
                'problem'      => '0',
                'acknowledged' => '0',
                'in_downtime'  => '0',
                'output'       => null,
            ];
        } else {
            return $this->backend->getHostState($hostname);
        }
    }
}
