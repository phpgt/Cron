<?php
namespace Gt\Cron;

use DateTime;
use InvalidArgumentException;
use RuntimeException;

class CronExpression implements Expression {
	/** @var array<string,string> */
	private const NICKNAME_MAP = [
		"@yearly" => "0 0 1 1 *",
		"@annually" => "0 0 1 1 *",
		"@monthly" => "0 0 1 * *",
		"@weekly" => "0 0 * * 0",
		"@daily" => "0 0 * * *",
		"@hourly" => "0 * * * *",
	];

	/** @var array<string,int> */
	private const MONTH_MAP = [
		"JAN" => 1,
		"FEB" => 2,
		"MAR" => 3,
		"APR" => 4,
		"MAY" => 5,
		"JUN" => 6,
		"JUL" => 7,
		"AUG" => 8,
		"SEP" => 9,
		"OCT" => 10,
		"NOV" => 11,
		"DEC" => 12,
	];

	/** @var array<string,int> */
	private const WEEKDAY_MAP = [
		"SUN" => 0,
		"MON" => 1,
		"TUE" => 2,
		"WED" => 3,
		"THU" => 4,
		"FRI" => 5,
		"SAT" => 6,
	];

	private const MAX_LOOKAHEAD_MINUTES = 525600 * 5;

	/** @var array<int,bool> */
	private array $minuteSet;
	/** @var array<int,bool> */
	private array $hourSet;
	/** @var array<int,bool> */
	private array $dayOfMonthSet;
	/** @var array<int,bool> */
	private array $monthSet;
	/** @var array<int,bool> */
	private array $dayOfWeekSet;

	private bool $dayOfMonthWildcard;
	private bool $dayOfWeekWildcard;
	private CronFieldParser $fieldParser;

	public function __construct(string $expression) {
		$this->fieldParser = new CronFieldParser();
		$expression = $this->expandNickname($expression);
		$parts = preg_split('/\s+/', trim($expression));

		if(!$parts || count($parts) !== 5) {
			throw new InvalidArgumentException("$expression is not a valid CRON expression");
		}

		$this->minuteSet = $this->parseField($parts[0], 0, 59);
		$this->hourSet = $this->parseField($parts[1], 0, 23);
		[$this->dayOfMonthSet, $this->dayOfMonthWildcard] = $this->parseFieldWithWildcard($parts[2], 1, 31);
		$this->monthSet = $this->parseField($parts[3], 1, 12, self::MONTH_MAP);
		[$this->dayOfWeekSet, $this->dayOfWeekWildcard] = $this->parseFieldWithWildcard(
			$parts[4],
			0,
			7,
			self::WEEKDAY_MAP,
			true
		);
	}

	public function isDue(DateTime $now):bool {
		$candidate = clone $now;
		$candidate->setTime(
			(int)$candidate->format("H"),
			(int)$candidate->format("i"),
			0
		);

		return $this->matches($candidate);
	}

	public function getNextRunDate(?DateTime $now = null):DateTime {
		$candidate = clone ($now ?? new DateTime());
		$candidate->setTime(
			(int)$candidate->format("H"),
			(int)$candidate->format("i"),
			0
		);
		$candidate->modify("+1 minute");

		for($i = 0; $i < self::MAX_LOOKAHEAD_MINUTES; $i++) {
			if($this->matches($candidate)) {
				return clone $candidate;
			}

			$candidate->modify("+1 minute");
		}

		throw new RuntimeException("Unable to calculate next run date");
	}

	private function expandNickname(string $expression):string {
		$expression = trim($expression);
		return self::NICKNAME_MAP[$expression] ?? $expression;
	}

	private function matches(DateTime $candidate):bool {
		$minute = (int)$candidate->format("i");
		$hour = (int)$candidate->format("G");
		$dayOfMonth = (int)$candidate->format("j");
		$month = (int)$candidate->format("n");
		$dayOfWeek = (int)$candidate->format("w");

		if(!isset($this->minuteSet[$minute]) || !isset($this->hourSet[$hour]) || !isset($this->monthSet[$month])) {
			return false;
		}

		$dayOfMonthMatches = isset($this->dayOfMonthSet[$dayOfMonth]);
		$dayOfWeekMatches = isset($this->dayOfWeekSet[$dayOfWeek]);

		if($this->dayOfMonthWildcard && $this->dayOfWeekWildcard) {
			return true;
		}

		if($this->dayOfMonthWildcard) {
			return $dayOfWeekMatches;
		}

		if($this->dayOfWeekWildcard) {
			return $dayOfMonthMatches;
		}

		return $dayOfMonthMatches || $dayOfWeekMatches;
	}

	/**
	 * @param array<string,int> $nameMap
	 * @return array<int,bool>
	 */
	private function parseField(
		string $field,
		int $min,
		int $max,
		array $nameMap = [],
		bool $normaliseWeekday = false
	):array {
		[$set] = $this->parseFieldWithWildcard($field, $min, $max, $nameMap, $normaliseWeekday);
		return $set;
	}

	/**
	 * @param array<string,int> $nameMap
	 * @return array{0:array<int,bool>,1:bool}
	 */
	private function parseFieldWithWildcard(
		string $field,
		int $min,
		int $max,
		array $nameMap = [],
		bool $normaliseWeekday = false
	):array {
		$field = strtoupper(trim($field));
		$isWildcard = $field === "*" || $field === "?";
		$set = [];

		foreach(explode(",", $field) as $segment) {
			$segment = trim($segment);
			if($segment === "") {
				throw new InvalidArgumentException("Invalid CRON field value $field");
			}

			foreach($this->expandSegment($segment, $min, $max, $nameMap, $normaliseWeekday) as $value) {
				$set[$value] = true;
			}
		}

		return [$set, $isWildcard];
	}

	/**
	 * @param array<string,int> $nameMap
	 * @return array<int>
	 */
	private function expandSegment(
		string $segment,
		int $min,
		int $max,
		array $nameMap,
		bool $normaliseWeekday
	):array {
		$step = 1;
		if(str_contains($segment, "/")) {
			[$segment, $stepPart] = explode("/", $segment, 2);
			if($stepPart === "" || !ctype_digit($stepPart) || (int)$stepPart < 1) {
				throw new InvalidArgumentException("Invalid CRON field value $segment/$stepPart");
			}
			$step = (int)$stepPart;
		}

		if($segment === "*" || $segment === "?") {
			$start = $min;
			$end = $max;
		}
		elseif(str_contains($segment, "-")) {
			[$startPart, $endPart] = explode("-", $segment, 2);
			$start = $this->normaliseValue($startPart, $min, $max, $nameMap, $normaliseWeekday);
			$end = $this->normaliseValue($endPart, $min, $max, $nameMap, $normaliseWeekday);
			if($end < $start) {
				throw new InvalidArgumentException("Invalid CRON field value $segment");
			}
		}
		else {
			$start = $this->normaliseValue($segment, $min, $max, $nameMap, $normaliseWeekday);
			$end = $start;
		}

		$values = [];
		for($value = $start; $value <= $end; $value += $step) {
			array_push($values, $normaliseWeekday && $value === 7 ? 0 : $value);
		}

		return $values;
	}

	/**
	 * @param array<string,int> $nameMap
	 */
	private function normaliseValue(
		string $value,
		int $min,
		int $max,
		array $nameMap,
		bool $normaliseWeekday
	):int {
		$value = strtoupper(trim($value));

		if(isset($nameMap[$value])) {
			return $nameMap[$value];
		}

		if(!preg_match('/^\d+$/', $value)) {
			throw new InvalidArgumentException("Invalid CRON field value $value");
		}

		$intValue = (int)$value;
		if($normaliseWeekday && $intValue === 7) {
			return 7;
		}

		if($intValue < $min || $intValue > $max) {
			throw new InvalidArgumentException("Invalid CRON field value $value");
		}

		return $intValue;
	}
}
