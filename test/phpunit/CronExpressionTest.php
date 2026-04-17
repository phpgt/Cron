<?php
namespace Gt\Cron\Test;

use DateTime;
use Gt\Cron\CronExpression;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CronExpressionTest extends TestCase {
	public function testIsDueEveryMinute():void {
		$expression = new CronExpression("* * * * *");
		self::assertTrue($expression->isDue(new DateTime("2026-03-11 12:34:56")));
	}

	public function testGetNextRunDateSkipsCurrentMinute():void {
		$expression = new CronExpression("* * * * *");
		$nextRunDate = $expression->getNextRunDate(new DateTime("2026-03-11 12:34:56"));
		self::assertSame("2026-03-11 12:35:00", $nextRunDate->format("Y-m-d H:i:s"));
	}

	public function testStepRangeAndListSyntax():void {
		$expression = new CronExpression("*/15 9-17 * * 1,3,5");

		self::assertTrue($expression->isDue(new DateTime("2026-03-13 09:30:20")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-13 09:31:00")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-14 09:30:00")));
	}

	public function testSecondStepSyntaxIsDueWithinMatchingMinute():void {
		$expression = new CronExpression("*/10s * * * *");

		self::assertTrue($expression->isDue(new DateTime("2026-03-11 12:34:20")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-11 12:34:25")));
	}

	public function testSecondStepSyntaxRespectsOtherFields():void {
		$expression = new CronExpression("*/15s 9-17 * * 1,3,5");

		self::assertTrue($expression->isDue(new DateTime("2026-03-13 09:30:15")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-13 09:30:10")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-14 09:30:15")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-13 08:30:15")));
	}

	public function testMonthAndWeekdayNames():void {
		$expression = new CronExpression("0 22 * JAN MON-FRI");

		self::assertTrue($expression->isDue(new DateTime("2027-01-04 22:00:10")));
		self::assertFalse($expression->isDue(new DateTime("2027-02-04 22:00:00")));
		self::assertFalse($expression->isDue(new DateTime("2027-01-03 22:00:00")));
	}

	public function testDayOfMonthAndDayOfWeekUseCronOrSemantics():void {
		$expression = new CronExpression("0 12 13 * FRI");

		self::assertTrue($expression->isDue(new DateTime("2026-03-13 12:00:00")));
		self::assertTrue($expression->isDue(new DateTime("2026-11-06 12:00:00")));
		self::assertFalse($expression->isDue(new DateTime("2026-03-12 12:00:00")));
	}

	public function testNicknameExpansion():void {
		$expression = new CronExpression("@daily");
		$nextRunDate = $expression->getNextRunDate(new DateTime("2026-03-11 12:34:00"));
		self::assertSame("2026-03-12 00:00:00", $nextRunDate->format("Y-m-d H:i:s"));
	}

	public function testGetNextRunDateUsesSecondPrecisionForSecondStepSyntax():void {
		$expression = new CronExpression("*/10s * * * *");
		$nextRunDate = $expression->getNextRunDate(new DateTime("2026-03-11 12:34:25"));
		self::assertSame("2026-03-11 12:34:30", $nextRunDate->format("Y-m-d H:i:s"));
	}

	public function testGetNextRunDateCanRollIntoNextMinuteForSecondStepSyntax():void {
		$expression = new CronExpression("*/10s * * * *");
		$nextRunDate = $expression->getNextRunDate(new DateTime("2026-03-11 12:34:58"));
		self::assertSame("2026-03-11 12:35:00", $nextRunDate->format("Y-m-d H:i:s"));
	}

	public function testInvalidFieldThrows():void {
		self::expectException(InvalidArgumentException::class);
		new CronExpression("* * * ABC *");
	}

	public function testInvalidSecondStepZeroThrows():void {
		self::expectException(InvalidArgumentException::class);
		new CronExpression("*/0s * * * *");
	}

	public function testInvalidSecondStepNonNumericThrows():void {
		self::expectException(InvalidArgumentException::class);
		new CronExpression("*/xs * * * *");
	}
}
