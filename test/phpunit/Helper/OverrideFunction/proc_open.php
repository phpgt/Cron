<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function proc_open() {
	return Override::call("proc_open", func_get_args());
}