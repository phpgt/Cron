<?php
use GT\Cron\CrontabParser;
use GT\Cron\ExpressionFactory;
use GT\Cron\JobRepository;
use GT\Cron\Queue;
use GT\Cron\ScriptOutputMode;

chdir(dirname(__DIR__));
require "vendor/autoload.php";

$now = new DateTime("2026-03-11 12:25:00");
$crontab = <<<'CRON'
25 * * * * printf 'Generate report.\n'
30 * * * * printf 'Warm cache.\n'
0 13 * * * printf 'Send lunchtime digest.\n'
CRON;

$queue = new Queue($now);
$crontabParser = new CrontabParser(new ExpressionFactory());
$jobRepository = new JobRepository(ScriptOutputMode::INHERIT);
$crontabParser->parseIntoQueue($crontab, $queue, $jobRepository);

echo "Current time: " . $now->format("Y-m-d H:i:s") . PHP_EOL;
echo "Crontab:" . PHP_EOL;
echo $crontab . PHP_EOL . PHP_EOL;

echo "Command output:" . PHP_EOL;
$queue->runDueJobsAndGetCommands();

echo "Next job: " . $queue->timeOfNextJob()?->format("Y-m-d H:i:s") . PHP_EOL;
echo "Next command: " . $queue->commandOfNextJob() . PHP_EOL;
