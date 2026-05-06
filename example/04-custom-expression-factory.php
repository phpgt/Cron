<?php
use GT\Cron\CrontabParser;
use GT\Cron\CronExpression;
use GT\Cron\Expression;
use GT\Cron\ExpressionFactory;
use GT\Cron\JobRepository;
use GT\Cron\Queue;
use GT\Cron\ScriptOutputMode;

chdir(dirname(__DIR__));
require "vendor/autoload.php";

$customExpressionFactory = new class extends ExpressionFactory {
	public function create(string $expression):Expression {
		if($expression === "@start") {
			return new class implements Expression {
				public function isDue(DateTime $now):bool {
					return true;
				}

				public function getNextRunDate(?DateTime $now = null):DateTime {
					return clone ($now ?? new DateTime());
				}
			};
		}

		return new CronExpression($expression);
	}
};

$now = new DateTime("2026-03-11 12:25:00");
$crontab = <<<'CRON'
@start printf 'This runs once when the runner starts.\n'
30 * * * * printf 'This is a normal cron schedule.\n'
CRON;

echo "Current time: " . $now->format("Y-m-d H:i:s") . PHP_EOL;
echo "Crontab:" . PHP_EOL;
echo $crontab . PHP_EOL . PHP_EOL;
echo "This example injects a custom ExpressionFactory to handle @start." . PHP_EOL;
echo "Command output:" . PHP_EOL;

$queue = new Queue($now);
$jobRepository = new JobRepository(ScriptOutputMode::INHERIT);
(new CrontabParser($customExpressionFactory))
	->parseIntoQueue($crontab, $queue, $jobRepository);

$runCommandList = $queue->runDueJobsAndGetCommands();
echo "Jobs ran: " . count($runCommandList) . PHP_EOL;
