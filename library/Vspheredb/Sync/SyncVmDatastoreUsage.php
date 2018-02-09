<?php

namespace Icinga\Module\Vspheredb\Sync;

use Icinga\Application\Benchmark;
use Icinga\Module\Vspheredb\DbObject\VCenter;
use Icinga\Module\Vspheredb\DbObject\VirtualMachine;
use Icinga\Module\Vspheredb\PropertySet\PropertySet;

class SyncVmDatastoreUsage
{
    /** @var VCenter */
    protected $vCenter;

    public function __construct(VCenter $vCenter)
    {
        $this->vCenter = $vCenter;
    }

    public function run()
    {
        $vCenter = $this->vCenter;
        $db = $vCenter->getDb();
        $result = $vCenter->getApi()->propertyCollector()->collectObjectProperties(
            new PropertySet('VirtualMachine', ['storage.perDatastoreUsage']),
            VirtualMachine::getSelectSet()
        );
        Benchmark::measure('Got VirtualMachine perDatastoreUsage');

        $vCenterUuid = $vCenter->get('uuid');
        $table = 'vm_datastore_usage';
        $existing = $db->fetchCol($db->select()->from(
            $table,
            "(vm_uuid || datastore_uuid)"
        )->where('vcenter_uuid = ?', $vCenterUuid));
        $existing = array_combine($existing, $existing);
        $seen = [];

        $db->beginTransaction();
        $insert = 0;
        $update = 0;
        $delete = 0;

        foreach ($result as $map) {
            $moRef = $map->id;
            $vmUuid = $vCenter->makeBinaryGlobalUuid($moRef);
            foreach ($map->{'storage.perDatastoreUsage'}->{'VirtualMachineUsageOnDatastore'} as $usage) {
                $dsMoid = $usage->datastore->_;
                $dsUuid = $vCenter->makeBinaryGlobalUuid($dsMoid);
                $key = "$vmUuid$dsUuid";
                $usage = [
                    'committed'   => $usage->committed,
                    'uncommitted' => $usage->uncommitted,
                    'unshared'    => $usage->unshared,
                ];
                $seen[$key] = $key;
                if (array_key_exists($key, $existing)) {
                    $res = $db->update(
                        $table,
                        $usage,
                        $this->makeWhere($db, $vmUuid, $dsUuid)
                    );
                    if ($res) {
                        $update++;
                    }
                } else {
                    $usage['vcenter_uuid'] = $vCenterUuid;
                    $usage['vm_uuid'] = $vmUuid;
                    $usage['datastore_uuid'] = $dsUuid;
                    $db->insert($table, $usage);
                    $insert++;
                }
            }
        }

        foreach (array_diff($existing, $seen) as $key) {
            $vmUuid = substr($key, 0, 20);
            $dsUuid = substr($key, 20);
            $db->delete($table, $this->makeWhere($db, $vmUuid, $dsUuid));
            $delete++;
        }

        $db->commit();
        Benchmark::measure("$insert created, $update changed, $delete deleted");
    }

    protected function makeWhere(\Zend_Db_Adapter_Abstract $db, $vmUuid, $dsUuid)
    {
        return $db->quoteInto('vm_uuid = ?', $vmUuid)
            . $db->quoteInto(' AND datastore_uuid = ?', $dsUuid);
    }
}
