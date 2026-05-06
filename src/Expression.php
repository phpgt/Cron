<?php
namespace GT\Cron;

use DateTime;

interface Expression {
	public function isDue(DateTime $now):bool;
	public function getNextRunDate(?DateTime $now = null):DateTime;
}
