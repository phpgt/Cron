<?php
namespace Gt\Cron\Test;

use DateTime;
use Gt\Cron\CronExpression;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CronExpressionTest extends TestCase {
	public function testNicknameIsExpanded():void {
		$expression = new CronExpression("@hourly");
		$due = new DateTime("2026-03-16 10:00:00");
		$notDue = new DateTime("2026-03-16 10:15:00");

		self::assertTrue($expression->isDue($due));
		self::assertFalse($expression->isDue($notDue));
	}

	public function testWeekdaySevenNormalisesToSunday():void {
		$expression = new CronExpression("0 0 * * 7");
		$sunday = new DateTime("2026-03-15 00:00:00");
		$monday = new DateTime("2026-03-16 00:00:00");

		self::assertTrue($expression->isDue($sunday));
		self::assertFalse($expression->isDue($monday));
	}

	public function testNextRunDateSupportsStepValues():void {
		$expression = new CronExpression("*/15 * * * *");
		$now = new DateTime("2026-03-16 10:07:42");

		self::assertSame(
			"2026-03-16 10:15:00",
			$expression->getNextRunDate($now)->format("Y-m-d H:i:s")
		);
	}

	public function testInvalidStepThrowsException():void {
		self::expectException(InvalidArgumentException::class);
		new CronExpression("*/0 * * * *");
	}
}
