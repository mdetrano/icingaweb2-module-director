<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatafield;

class DatafieldsController extends ActionController
{
    var $isApified = true;

    public function indexAction() {
        if (! $this->getRequest()->isApiRequest()) {
            $this->redirectNow('director/data/fields');
            return;
        }
        $table = $this->loadTable('Datafield')->setConnection($this->db());
        $dummy = DirectorDatafield::create(array());
        $objects = array();
        foreach ($dummy::loadAll($this->db) as $object) {
            $objects[] = $this->restProps($object);;
        }
        return $this->sendJson((object) array('objects' => $objects));

    }

    protected function restProps($obj) {
        $props=$obj->properties;
        $props['object_name']=$props['varname'];
        foreach(array_keys($props) as $key) {
            if (is_null($props[$key]) || in_array($key, array('id','varname'))) {
                unset($props[$key]);
            }
        }
        return($props);
    }

}
