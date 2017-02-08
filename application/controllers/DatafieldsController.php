<?php

namespace Icinga\Module\Director\Controllers;

use Icinga\Module\Director\Web\Controller\ActionController;
use Icinga\Module\Director\Objects\DirectorDatafield;
use Icinga\Module\Director\Objects\DirectorDatalist;

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
        $props=$obj->getProperties();
        $props['object_name']=$props['varname'];
        foreach(array_keys($props) as $key) {
            if (is_null($props[$key]) || in_array($key, array('id','varname'))) {
                unset($props[$key]);
            }
        }
        if ($obj->getSetting('datalist_id')) {
            $datalist = DirectorDatalist::load($obj->getSetting('datalist_id'),$this->db);
            $props['datalist_name']=$datalist->list_name;
        }
        $props = array_merge($props, $this->loadObjectsForDatafield($obj->id));
     
        return($props);
    }


    protected function loadObjectsForDatafield($id) {
        $r=array();
        foreach(array('command','service','host','user','notification') as $related) {

	        $query = $this->db->select()->from(
	            array('o' => 'icinga_'.$related),
	            array(
	                'object_name'   => 'o.object_name',
	                'is_required'   => 'f.is_required',
	            )
	        )->join(
	            array('f' => 'icinga_'.$related.'_field'),
	            'o.id = f.'.$related.'_id',
	            array()
	        )->where('f.datafield_id', $id);
	
	        $result= $this->db->fetchAll($query);
	
	        foreach ($result as $obj) {
	            $r[$related.'s'][]=array('name' => $obj->object_name, 'is_required' => $obj->is_required);
	
	        }
        }	        

        return($r);
    }


}
