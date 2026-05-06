<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function proc_get_status() {
	Override::call("proc_get_status", func_get_args());
	return [
		"running" => false,
		"exitcode" => 0,
	];
}