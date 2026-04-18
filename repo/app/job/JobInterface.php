<?php
namespace app\job;

interface JobInterface
{
    /** @return array metrics returned to the runner (rows affected, etc.) */
    public function run(): array;
}
