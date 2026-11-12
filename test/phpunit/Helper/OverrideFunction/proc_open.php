<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function proc_open(array|string $command, array $descriptors, ?array &$pipes) {
	return Override::call("proc_open", func_get_args());
}
