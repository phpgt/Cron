<?php
use Gt\Cron\CrontabParser;
use Gt\Cron\ExpressionFactory;
use Gt\Cron\JobRepository;
use Gt\Cron\Queue;
use Gt\Cron\ScriptOutputMode;

chdir(dirname(__DIR__));
require "vendor/autoload.php";

$now = new DateTime("2026-03-11 13:00:00");
$crontab = <<<'CRON'
# Nicknames are accepted as a single schedule token.
@hourly printf 'Hourly job fired.\n'
@daily printf 'Daily job is not due yet.\n'
CRON;

$queue = new Queue($now);
$crontabParser = new CrontabParser(new ExpressionFactory());
$jobRepository = new JobRepository(ScriptOutputMode::INHERIT);
$crontabParser->parseIntoQueue($crontab, $queue, $jobRepository);

echo "Current time: " . $now->format("Y-m-d H:i:s") . PHP_EOL;
echo "Crontab:" . PHP_EOL;
echo $crontab . PHP_EOL . PHP_EOL;

echo "Command output:" . PHP_EOL;
$runCommandList = $queue->runDueJobsAndGetCommands();
echo "Commands due right now: " . implode(", ", $runCommandList) . PHP_EOL;
echo "Next job: " . $queue->timeOfNextJob()?->format("Y-m-d H:i:s") . PHP_EOL;
echo "Next command: " . $queue->commandOfNextJob() . PHP_EOL;
