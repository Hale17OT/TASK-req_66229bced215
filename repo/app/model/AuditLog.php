<?php
namespace app\model;

use think\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    protected $autoWriteTimestamp = false;
    protected $type = [
        'before_json'   => 'json',
        'after_json'    => 'json',
        'metadata_json' => 'json',
    ];
}
