<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatafield;
use Icinga\Module\Director\Objects\DirectorDatalist;
use Icinga\Module\Director\Web\Table\DatafieldTable;

class DatafieldsController extends ActionController
{
    var $isApified = true;

    public function indexAction() {
        if (! $this->getRequest()->isApiRequest()) {
            $this->redirectNow('director/data/fields');
            return;
        }
        $dummy = DirectorDatafield::create(array());
        $objects = array();

        foreach ($dummy::loadAll($this->db()) as $object) {
            $objects[] = $this->restProps($object);
        } 
        return $this->sendJson($this->getResponse(), (object) array('objects' => $objects));

    }

    protected function restProps($obj) {
        $props=$obj->getProperties();
        $props['object_name']=$props['varname'];
        $props['object_type']='template';
        foreach(array_keys($props) as $key) {
            if (is_null($props[$key]) || in_array($key, array('id','varname'))) {
                unset($props[$key]);
            }
        }
        if ($obj->getSetting('datalist_id')) {
            $datalist = DirectorDatalist::load($obj->getSetting('datalist_id'),$this->db());
            $props['datalist_name']=$datalist->list_name;
        }

        return($props);
    }

}
