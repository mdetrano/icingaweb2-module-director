<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatafield;
use Icinga\Module\Director\Objects\DirectorDatalist;
use Icinga\Module\Director\Web\Table\DatafieldTable;
use Icinga\Module\Director\Objects\DirectorDatafieldCategory;

use Ramsey\Uuid\Uuid;

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
            if ($key == 'uuid') {
		    $props[$key] = Uuid::fromBytes($props[$key])->toString();
	    }
	    if ($key == 'category_id') {
	        if (isset($props[$key])) {
                	$category = DirectorDatafieldCategory::loadWithAutoIncId($props[$key],$this->db());
			$props['category_name']=$category->category_name;
			unset($props[$key]);
		}
            }
        }
        if ($obj->getSetting('datalist_id')) {
            $datalist = DirectorDatalist::loadWithAutoIncId($obj->getSetting('datalist_id'),$this->db());
            $props['datalist_name']=$datalist->list_name;
        }
        if ($obj->getSetting('data_type')) {
            $props['target_data_type']=$obj->getSetting('data_type');
        }
        foreach (array('icinga_object_type','query','resource') as $standard_prop) {
            if ($obj->getSetting($standard_prop)) {
                $props[$standard_prop]=$obj->getSetting($standard_prop);
            }
        }
        
        return($props);
    }

}
