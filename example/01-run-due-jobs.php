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
10 * * * * printf 'This is not due at 12:25.\n'
25 * * * * printf 'This runs at 25 past the hour.\n'
*/5 * * * * printf 'This runs every five minutes.\n'
CRON;

echo "Current time: " . $now->format("Y-m-d H:i:s") . PHP_EOL;
echo "Crontab:" . PHP_EOL;
echo $crontab . PHP_EOL . PHP_EOL;

$queue = new Queue($now);
$crontabParser = new CrontabParser(new ExpressionFactory());
$jobRepository = new JobRepository(ScriptOutputMode::INHERIT);
$crontabParser->parseIntoQueue($crontab, $queue, $jobRepository);

echo "Command output:" . PHP_EOL;
$runCommandList = $queue->runDueJobsAndGetCommands();
echo "Jobs ran: " . count($runCommandList) . PHP_EOL;
