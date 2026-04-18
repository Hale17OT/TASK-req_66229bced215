<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class JobsStatus extends Command
{
    protected function configure(): void
    {
        $this->setName('jobs:status')->setDescription('Show scheduled jobs and last run status');
    }

    protected function execute(Input $input, Output $output): int
    {
        $rows = Db::table('scheduled_jobs')->order('job_key')->select();
        foreach ($rows as $r) {
            $output->writeln(sprintf('%-30s every %5ds  next=%s  last=%s/%s',
                $r['job_key'], (int)$r['interval_seconds'],
                $r['next_run_at'] ?? '-', $r['last_status'] ?? '-', $r['last_run_at'] ?? '-'));
        }
        return 0;
    }
}
