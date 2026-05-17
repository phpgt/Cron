<?php
namespace GT\Cron\Test;

use GT\Cron\CronExplainer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CronExplainerTest extends TestCase {
	/** @dataProvider simpleScheduleData */
	public function testSimpleSchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function simpleScheduleData():array {
		return [
			"every minute" => ["* * * * *", "Every minute"],
			"every ten minutes" => ["*/10 * * * *", "Every 10 minutes"],
			"every hour" => ["0 * * * *", "Every hour"],
			"fixed minute each hour" => [
				"15 * * * *",
				"At 15 minutes past every hour",
			],
			"midnight" => ["0 0 * * *", "At 12:00 AM"],
			"noon" => ["0 12 * * *", "At 12:00 PM"],
			"morning leading zeroes" => ["05 01 * * *", "At 01:05 AM"],
			"afternoon" => ["5 13 * * *", "At 01:05 PM"],
			"late evening" => ["59 23 * * *", "At 11:59 PM"],
			"complex minute fallback" => [
				"5,35 9 * * *",
				"At minute 5,35 of hour 9",
			],
			"complex hour fallback" => [
				"0 9-17 * * *",
				"At minute 0 of hour 9-17",
			],
		];
	}

	/** @dataProvider secondScheduleData */
	public function testSecondSchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function secondScheduleData():array {
		return [
			"every ten seconds" => ["*/10s * * * *", "Every 10 seconds"],
			"fixed second" => [
				"5s * * * *",
				"At 5 seconds past every minute",
			],
			"complex second fallback" => [
				"5,10s * * * *",
				"At second 5,10 of every minute",
			],
		];
	}

	/** @dataProvider nicknameScheduleData */
	public function testNicknameSchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function nicknameScheduleData():array {
		return [
			"hourly" => ["@hourly", "Every hour"],
			"daily" => ["@daily", "At 12:00 AM"],
			"weekly" => ["@weekly", "At 12:00 AM, only on Sunday"],
			"monthly" => [
				"@monthly",
				"At 12:00 AM, on day 1 of the month",
			],
			"yearly" => ["@yearly", "At 12:00 AM, on 1st January"],
			"annually" => ["@annually", "At 12:00 AM, on 1st January"],
			"uppercase nickname" => ["@DAILY", "At 12:00 AM"],
		];
	}

	/** @dataProvider weekdayScheduleData */
	public function testWeekdaySchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function weekdayScheduleData():array {
		return [
			"weekday name" => [
				"0 0 * * FRI",
				"At 12:00 AM, only on Friday",
			],
			"weekday number" => [
				"0 0 * * 5",
				"At 12:00 AM, only on Friday",
			],
			"sunday zero" => [
				"0 0 * * 0",
				"At 12:00 AM, only on Sunday",
			],
			"sunday seven" => [
				"0 0 * * 7",
				"At 12:00 AM, only on Sunday",
			],
			"weekday range" => [
				"0 22 * * MON-FRI",
				"At 10:00 PM, only on Monday through Friday",
			],
			"weekday list" => [
				"0 9 * * MON,WED,FRI",
				"At 09:00 AM, only on Monday, Wednesday and Friday",
			],
		];
	}

	/** @dataProvider dayOfMonthScheduleData */
	public function testDayOfMonthSchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function dayOfMonthScheduleData():array {
		return [
			"first day monthly" => [
				"0 0 1 * *",
				"At 12:00 AM, on day 1 of the month",
			],
			"thirteenth day or friday" => [
				"0 12 13 * FRI",
				"At 12:00 PM, on day 13 of the month or on Friday",
			],
			"complex day fallback" => [
				"0 0 1,15 * *",
				"At 12:00 AM, on day 1,15 of the month",
			],
		];
	}

	/** @dataProvider monthScheduleData */
	public function testMonthSchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function monthScheduleData():array {
		return [
			"month name" => [
				"0 0 * JAN *",
				"At 12:00 AM, only in January",
			],
			"month number" => [
				"0 0 * 1 *",
				"At 12:00 AM, only in January",
			],
			"month list" => [
				"0 0 * JAN,MAR *",
				"At 12:00 AM, only in January and March",
			],
			"month range" => [
				"0 0 * JAN-MAR *",
				"At 12:00 AM, only in January through March",
			],
			"first of january" => [
				"0 0 1 JAN *",
				"At 12:00 AM, on 1st January",
			],
			"second of february" => [
				"0 0 2 FEB *",
				"At 12:00 AM, on 2nd February",
			],
			"third of march" => [
				"0 0 3 MAR *",
				"At 12:00 AM, on 3rd March",
			],
			"eleventh of april" => [
				"0 0 11 APR *",
				"At 12:00 AM, on 11th April",
			],
			"twenty second of may" => [
				"0 0 22 MAY *",
				"At 12:00 AM, on 22nd May",
			],
		];
	}

	/** @dataProvider nthWeekdayScheduleData */
	public function testNthWeekdaySchedules(
		string $expression,
		string $expected
	):void {
		$explainer = new CronExplainer();

		self::assertSame($expected, $explainer->explain($expression));
	}

	public static function nthWeekdayScheduleData():array {
		return [
			"first sunday" => [
				"05 01 * * SUN#1",
				"At 01:05 AM, on the first Sunday of the month",
			],
			"second sunday in may" => [
				"05 01 * MAY SUN#2",
				"At 01:05 AM, on the second Sunday of the month in May",
			],
			"third numeric weekday" => [
				"0 0 * * 3#3",
				"At 12:00 AM, on the third Wednesday of the month",
			],
			"fifth friday" => [
				"0 0 * * FRI#5",
				"At 12:00 AM, on the fifth Friday of the month",
			],
			"sunday seven" => [
				"05 01 * * 7#1",
				"At 01:05 AM, on the first Sunday of the month",
			],
			"mixed nth and standard weekday syntax" => [
				"0 0 * * SUN#1,FRI",
				"At 12:00 AM, on the first Sunday of the month and Friday",
			],
		];
	}

	/** @dataProvider invalidExpressionData */
	public function testInvalidExpressionsThrow(string $expression):void {
		$explainer = new CronExplainer();

		self::expectException(InvalidArgumentException::class);

		$explainer->explain($expression);
	}

	public static function invalidExpressionData():array {
		return [
			"empty" => [""],
			"unknown nickname" => ["@sometimes"],
			"too few fields" => ["0 0 * *"],
			"too many fields" => ["0 0 * * * *"],
			"invalid minute" => ["ABC 0 * * *"],
			"minute out of range" => ["60 0 * * *"],
			"hour out of range" => ["0 24 * * *"],
			"day of month out of range" => ["0 0 32 * *"],
			"month out of range" => ["0 0 * 13 *"],
			"weekday out of range" => ["0 0 * * 8"],
			"nth weekday zero" => ["0 0 * * SUN#0"],
			"nth weekday too high" => ["0 0 * * SUN#6"],
			"nth weekday range" => ["0 0 * * MON-FRI#1"],
			"invalid seconds" => ["*/0s * * * *"],
		];
	}
}
