<?php
class HordeActiveSyncAddMapDeleteFlag extends Horde_Db_Migration_Base
{
    public function up()
    {
        $this->addColumn(
            'horde_activesync_map',
            'sync_deleted',
            'boolean');
    }

    public function down()
    {
        $this->removeColumn('horde_activesync_map', 'sync_deleted');
    }

}
