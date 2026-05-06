<?php
namespace GT\Cron;

class ExpressionFactory {
	public function create(string $expression):Expression {
		return new CronExpression($expression);
	}
}
