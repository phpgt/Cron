<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function tmpfile() {
	return Override::call("tmpfile", func_get_args());
}
