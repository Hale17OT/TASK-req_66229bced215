<?php
namespace app\model;

use think\Model;

class DuplicateDocument extends Model
{
    protected $table = 'duplicate_document_registry';
    protected $autoWriteTimestamp = false;
}
