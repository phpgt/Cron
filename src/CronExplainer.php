<?php
namespace GT\Cron;

use InvalidArgumentException;

/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) */
class CronExplainer {
	/** @var array<string,string> */
	private const NICKNAME_MAP = [
		"@yearly" => "0 0 1 1 *",
		"@annually" => "0 0 1 1 *",
		"@monthly" => "0 0 1 * *",
		"@weekly" => "0 0 * * 0",
		"@daily" => "0 0 * * *",
		"@hourly" => "0 * * * *",
	];

	/** @var array<int|string,string> */
	private const WEEKDAY_NAME_MAP = [
		"0" => "Sunday",
		"7" => "Sunday",
		"SUN" => "Sunday",
		"1" => "Monday",
		"MON" => "Monday",
		"2" => "Tuesday",
		"TUE" => "Tuesday",
		"3" => "Wednesday",
		"WED" => "Wednesday",
		"4" => "Thursday",
		"THU" => "Thursday",
		"5" => "Friday",
		"FRI" => "Friday",
		"6" => "Saturday",
		"SAT" => "Saturday",
	];

	/** @var array<int,string> */
	private const ORDINAL_MAP = [
		1 => "first",
		2 => "second",
		3 => "third",
		4 => "fourth",
		5 => "fifth",
	];

	/** @var array<int|string,string> */
	private const MONTH_NAME_MAP = [
		"1" => "January",
		"JAN" => "January",
		"2" => "February",
		"FEB" => "February",
		"3" => "March",
		"MAR" => "March",
		"4" => "April",
		"APR" => "April",
		"5" => "May",
		"MAY" => "May",
		"6" => "June",
		"JUN" => "June",
		"7" => "July",
		"JUL" => "July",
		"8" => "August",
		"AUG" => "August",
		"9" => "September",
		"SEP" => "September",
		"10" => "October",
		"OCT" => "October",
		"11" => "November",
		"NOV" => "November",
		"12" => "December",
		"DEC" => "December",
	];

	public function explain(string $expression):string {
		$expression = trim($expression);
		$expression = self::NICKNAME_MAP[strtolower($expression)]
			?? $expression;
		new CronExpression($expression);
		$parts = preg_split("/\s+/", $expression);

		if(!$parts || count($parts) !== 5) {
			throw new InvalidArgumentException("$expression is not a valid CRON expression");
		}

		[$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

		if($this->isEveryHour($minute, $hour, $dayOfMonth, $month, $dayOfWeek)) {
			return "Every hour";
		}

		$phraseList = [$this->explainTime($minute, $hour)];
		$dayPhrase = $this->explainDay($dayOfMonth, $dayOfWeek, $month);
		if($dayPhrase) {
			$phraseList []= $dayPhrase;
		}

		return implode(", ", $phraseList);
	}

	private function isEveryHour(
		string $minute,
		string $hour,
		string $dayOfMonth,
		string $month,
		string $dayOfWeek
	):bool {
		return $minute === "0"
			&& $hour === "*"
			&& $dayOfMonth === "*"
			&& $month === "*"
			&& $dayOfWeek === "*";
	}

	private function explainTime(string $minute, string $hour):string {
		if(str_ends_with(strtolower($minute), "s") && $hour === "*") {
			return $this->explainSecondTime($minute);
		}

		$wildcardHourTime = $this->explainWildcardHourTime($minute, $hour);
		if(!is_null($wildcardHourTime)) {
			return $wildcardHourTime;
		}

		return $this->explainFixedHourTime($minute, $hour);
	}

	private function explainSecondTime(string $minute):string {
		$second = substr($minute, 0, -1);
		if(str_starts_with($second, "*/")) {
			return "Every " . substr($second, 2) . " seconds";
		}

		if($this->isInteger($second)) {
			return "At " . (int)$second . " seconds past every minute";
		}

		return "At second $second of every minute";
	}

	private function explainWildcardHourTime(
		string $minute,
		string $hour
	):?string {
		if($hour !== "*") {
			return null;
		}

		if($minute === "*") {
			return "Every minute";
		}

		if(str_starts_with($minute, "*/")) {
			return "Every " . substr($minute, 2) . " minutes";
		}

		if($this->isInteger($minute)) {
			return "At " . (int)$minute . " minutes past every hour";
		}

		return null;
	}

	private function explainFixedHourTime(string $minute, string $hour):string {
		if($this->isInteger($minute) && $this->isInteger($hour)) {
			return "At " . $this->formatTime((int)$hour, (int)$minute);
		}

		return "At minute $minute of hour $hour";
	}

	private function explainDay(
		string $dayOfMonth,
		string $dayOfWeek,
		string $month
	):?string {
		$monthPhrase = $this->formatMonth($month);

		if($dayOfMonth === "*" && $dayOfWeek === "*") {
			return $monthPhrase ? "only in $monthPhrase" : null;
		}

		if($dayOfMonth === "*" && str_contains($dayOfWeek, "#")) {
			return $this->explainNthDayOfWeek($dayOfWeek, $monthPhrase);
		}

		if($dayOfMonth === "*") {
			return $this->explainDayOfWeek($dayOfWeek, $monthPhrase);
		}

		if($dayOfWeek === "*") {
			return $this->formatDayOfMonth($dayOfMonth, $monthPhrase);
		}

		return $this->formatDayOfMonth($dayOfMonth, $monthPhrase)
			. " or on " . $this->formatDayOfWeek($dayOfWeek);
	}

	private function explainNthDayOfWeek(
		string $dayOfWeek,
		?string $monthPhrase
	):string {
		$phrase = "on " . $this->formatNthDayOfWeekList($dayOfWeek);
		return $monthPhrase ? "$phrase in $monthPhrase" : $phrase;
	}

	private function explainDayOfWeek(
		string $dayOfWeek,
		?string $monthPhrase
	):string {
		$phrase = "only on " . $this->formatDayOfWeek($dayOfWeek);
		return $monthPhrase ? "$phrase in $monthPhrase" : $phrase;
	}

	private function formatTime(int $hour, int $minute):string {
		$suffix = $hour >= 12 ? "PM" : "AM";
		$hour12 = $hour % 12;
		if($hour12 === 0) {
			$hour12 = 12;
		}

		return sprintf("%02d:%02d %s", $hour12, $minute, $suffix);
	}

	private function formatNthDayOfWeek(string $dayOfWeek):string {
		[$day, $nth] = explode("#", strtoupper($dayOfWeek), 2);
		$ordinal = self::ORDINAL_MAP[(int)$nth] ?? "{$nth}th";
		return $ordinal . " " . $this->formatDayOfWeek($day);
	}

	private function formatNthDayOfWeekList(string $dayOfWeek):string {
		$phraseList = [];

		foreach(explode(",", $dayOfWeek) as $part) {
			if(str_contains($part, "#")) {
				$phraseList []= "the " . $this->formatNthDayOfWeek($part)
					. " of the month";
				continue;
			}

			$phraseList []= $this->formatDayOfWeek($part);
		}

		return $this->formatList($phraseList);
	}

	private function formatDayOfWeek(string $dayOfWeek):string {
		$dayOfWeekList = array_map(
			function(string $part):string {
				return $this->formatDayOfWeekPart($part);
			},
			explode(",", $dayOfWeek)
		);

		return $this->formatList($dayOfWeekList);
	}

	private function formatDayOfWeekPart(string $dayOfWeek):string {
		$dayOfWeek = strtoupper($dayOfWeek);
		if(str_contains($dayOfWeek, "-")) {
			[$start, $end] = explode("-", $dayOfWeek, 2);
			return $this->formatDayOfWeekPart($start)
				. " through " . $this->formatDayOfWeekPart($end);
		}

		return self::WEEKDAY_NAME_MAP[$dayOfWeek] ?? $dayOfWeek;
	}

	private function formatDayOfMonth(
		string $dayOfMonth,
		?string $monthPhrase
	):string {
		if($monthPhrase && $this->isInteger($dayOfMonth)) {
			return "on " . $this->formatMonthDay((int)$dayOfMonth)
				. " " . $monthPhrase;
		}

		$phrase = "on day $dayOfMonth of the month";
		return $monthPhrase ? "$phrase in $monthPhrase" : $phrase;
	}

	private function formatMonthDay(int $day):string {
		$suffix = "th";
		if($day % 100 < 11 || $day % 100 > 13) {
			$suffix = match($day % 10) {
				1 => "st",
				2 => "nd",
				3 => "rd",
				default => "th",
			};
		}

		return $day . $suffix;
	}

	private function formatMonth(string $month):?string {
		if($month === "*") {
			return null;
		}

		$monthList = array_map(
			function(string $part):string {
				return $this->formatMonthPart($part);
			},
			explode(",", $month)
		);

		return $this->formatList($monthList);
	}

	private function formatMonthPart(string $month):string {
		$month = strtoupper($month);
		if(str_contains($month, "-")) {
			[$start, $end] = explode("-", $month, 2);
			return $this->formatMonthPart($start)
				. " through " . $this->formatMonthPart($end);
		}

		return self::MONTH_NAME_MAP[$month] ?? $month;
	}

	/** @param array<string> $partList */
	private function formatList(array $partList):string {
		if(count($partList) <= 1) {
			return $partList[0] ?? "";
		}

		$last = array_pop($partList);
		return implode(", ", $partList) . " and " . $last;
	}

	private function isInteger(string $value):bool {
		return (bool)preg_match("/^\d+$/", $value);
	}
}
