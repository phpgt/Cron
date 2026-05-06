<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function proc_close() {
	Override::call("proc_close", func_get_args());
}