<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function sleep() {
	Override::call("sleep", func_get_args());
}