<?php
use GT\Cron\CrontabParser;
use GT\Cron\CronExplainer;

chdir(dirname(__DIR__));
require "vendor/autoload.php";

$crontab = <<<'CRON'
# Explain a few different schedule styles.
@DAILY printf 'Daily backup.\n'
*/10s * * * * printf 'Heartbeat tick.\n'
0 9 * * MON,WED,FRI printf 'Team summary.\n'
05 01 * MAY SUN#2 printf 'May maintenance window.\n'
CRON;

$parser = new CrontabParser();
$explainer = new CronExplainer();

echo "Crontab:" . PHP_EOL;
echo $crontab . PHP_EOL . PHP_EOL;
echo "Explanations:" . PHP_EOL;

foreach(explode("\n", $crontab) as $line) {
	$line = trim($line);
	if($line === "" || $line[0] === "#") {
		continue;
	}

	[$expression, $command] = $parser->parseLine($line);
	echo $expression
		. " " . $command
		. PHP_EOL . "  -> "
		. $explainer->explain($expression)
		. PHP_EOL;
}
