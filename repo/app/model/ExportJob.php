<?php
namespace app\model;

use think\Model;

class ExportJob extends Model
{
    protected $table = 'export_jobs';
    protected $autoWriteTimestamp = false;
    protected $type = ['filters_json' => 'json'];
}
