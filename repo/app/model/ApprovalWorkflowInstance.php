<?php
namespace app\model;

use think\Model;

class ApprovalWorkflowInstance extends Model
{
    protected $table = 'approval_workflow_instances';
    protected $autoWriteTimestamp = false;
}
