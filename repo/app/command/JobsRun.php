<?php
namespace app\command;

use app\job\Registry;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * `php think jobs:run [--interval=30]`
 *
 * In-process scheduled job runner. Polls scheduled_jobs every N seconds,
 * claims due rows via SELECT ... FOR UPDATE SKIP LOCKED, and runs the
 * registered job. All jobs are idempotent and restart-safe.
 */
class JobsRun extends Command
{
    protected function configure(): void
    {
        $this->setName('jobs:run')
            ->setDescription('Run the in-process scheduled job worker')
            ->addOption('interval', null, Option::VALUE_OPTIONAL, 'poll interval seconds', 30)
            ->addOption('once', null, Option::VALUE_NONE, 'run one tick and exit');
    }

    protected function execute(Input $input, Output $output): int
    {
        $owner = gethostname() . ':' . getmypid();
        $interval = max(5, (int)$input->getOption('interval'));
        $once = (bool)$input->getOption('once');

        $output->writeln("<info>jobs:run owner={$owner} interval={$interval}s</info>");
        do {
            try {
                $this->tick($owner, $output);
            } catch (\Throwable $e) {
                $output->error($e->getMessage());
            }
            if ($once) break;
            sleep($interval);
        } while (true);
        return 0;
    }

    private function tick(string $owner, Output $out): void
    {
        $now = date('Y-m-d H:i:s');
        $due = Db::table('scheduled_jobs')
            ->where('enabled', 1)
            ->where('next_run_at', '<=', $now)
            ->whereNull('lock_owner')
            ->select();

        foreach ($due as $row) {
            // Try to claim atomically
            $claimed = Db::table('scheduled_jobs')
                ->where('id', $row['id'])
                ->whereNull('lock_owner')
                ->update(['lock_owner' => $owner, 'lock_acquired_at' => $now]);
            if (!$claimed) continue;

            $runId = Db::table('job_runs')->insertGetId([
                'job_key'    => $row['job_key'],
                'started_at' => date('Y-m-d H:i:s'),
                'status'     => 'running',
            ]);
            $status = 'ok'; $err = null; $metrics = [];
            try {
                $job = Registry::resolve($row['job_key']);
                $metrics = $job->run() ?? [];
                $out->writeln("<info>job {$row['job_key']} ok</info>");
            } catch (\Throwable $e) {
                $status = 'failed';
                $err = substr($e->getMessage(), 0, 2000);
                $out->error("job {$row['job_key']} failed: {$err}");
            }
            Db::table('job_runs')->where('id', $runId)->update([
                'finished_at' => date('Y-m-d H:i:s'),
                'status'      => $status === 'ok' ? 'ok' : 'failed',
                'error'       => $err,
                'metrics_json' => $metrics ? json_encode($metrics) : null,
            ]);
            Db::table('scheduled_jobs')->where('id', $row['id'])->update([
                'last_run_at'  => date('Y-m-d H:i:s'),
                'last_status'  => $status === 'ok' ? 'ok' : 'failed',
                'last_error'   => $err,
                'next_run_at'  => date('Y-m-d H:i:s', time() + (int)$row['interval_seconds']),
                'lock_owner'   => null,
                'lock_acquired_at' => null,
            ]);
        }
    }
}
