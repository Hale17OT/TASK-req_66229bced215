<?php
namespace app\model;

use think\Model;

class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';
    protected $autoWriteTimestamp = false;
}
