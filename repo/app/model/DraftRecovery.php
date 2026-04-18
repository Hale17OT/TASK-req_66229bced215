<?php
namespace app\model;

use think\Model;

class DraftRecovery extends Model
{
    protected $table = 'draft_recovery';
    protected $autoWriteTimestamp = false;
    protected $type = ['payload_json' => 'json'];
}
