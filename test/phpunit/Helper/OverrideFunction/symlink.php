<?php
namespace GT\Cron;

use GT\Cron\Test\Helper\Override;

function symlink(string $target, string $link):bool {
	return Override::call("symlink", [$target, $link]);
}
