<?php

/** 独立プロセスによる競合テストの待機SQLを観測する。 */

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Symfony\Component\Process\Process;

trait ObservesConcurrentSql
{
    /** 固定sleepで順序を仮定せず、4秒以内に実際の待機SQLを確認する。 */
    private function waitForSqlInProcessList(Connection $connection, Process $process, string $sql): void
    {
        $deadline = microtime(true) + 4;
        do {
            foreach ($connection->select('SHOW PROCESSLIST') as $thread) {
                if (str_contains(strtolower($thread->Info ?? ''), $sql)) {
                    $this->assertTrue(true);

                    return;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline && $process->isRunning());
        $this->fail('独立プロセスの待機SQLを観測できなかった: '.$process->getOutput().$process->getErrorOutput());
    }
}
