<?php
namespace GT\Cron;

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
	private const SECONDS_PER_MINUTE = 60;

	/** @var array<int,bool>|null */
	private ?array $secondSet = null;
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
	/** @var array<int,array<int,bool>> */
	private array $nthDayOfWeekSet = [];

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

		$this->minuteSet = $this->parseMinuteField($parts[0]);
		$this->hourSet = $this->fieldParser->parseField($parts[1], 0, 23);
		[$this->dayOfMonthSet, $this->dayOfMonthWildcard] = $this->fieldParser->parseFieldWithWildcard($parts[2], 1, 31);
		$this->monthSet = $this->fieldParser->parseField($parts[3], 1, 12, self::MONTH_MAP);
		[
			$this->dayOfWeekSet,
			$this->dayOfWeekWildcard,
			$this->nthDayOfWeekSet
		] = $this->parseDayOfWeekField($parts[4]);
	}

	public function isDue(DateTime $now):bool {
		$candidate = clone $now;
		$candidate->setTime((int)$candidate->format("H"), (int)$candidate->format("i"), $this->hasSecondPrecision()
			? (int)$candidate->format("s")
			: 0);

		return $this->matches($candidate);
	}

	public function getNextRunDate(?DateTime $now = null):DateTime {
		$candidate = clone ($now ?? new DateTime());
		$candidate->setTime(
			(int)$candidate->format("H"),
			(int)$candidate->format("i"),
			$this->hasSecondPrecision() ? (int)$candidate->format("s") : 0
		);
		$candidate->modify($this->hasSecondPrecision() ? "+1 second" : "+1 minute");
		$maxLookaheadSteps = $this->getMaxLookaheadSteps();

		for($i = 0; $i < $maxLookaheadSteps; $i++) {
			if($this->matches($candidate)) {
				return clone $candidate;
			}

			$candidate->modify($this->hasSecondPrecision() ? "+1 second" : "+1 minute");
		}

		throw new RuntimeException("Unable to calculate next run date");
	}

	private function expandNickname(string $expression):string {
		$expression = trim($expression);
		return self::NICKNAME_MAP[strtolower($expression)] ?? $expression;
	}

	private function matches(DateTime $candidate):bool {
		$second = (int)$candidate->format("s");
		$minute = (int)$candidate->format("i");
		$hour = (int)$candidate->format("G");
		$dayOfMonth = (int)$candidate->format("j");
		$month = (int)$candidate->format("n");
		$dayOfWeek = (int)$candidate->format("w");

		if(!$this->matchesSecond($second)) {
			return false;
		}

		if(!isset($this->minuteSet[$minute]) || !isset($this->hourSet[$hour]) || !isset($this->monthSet[$month])) {
			return false;
		}

		return $this->matchesDay($dayOfMonth, $dayOfWeek);
	}

	/** @return array<int,bool> */
	private function parseMinuteField(string $field):array {
		if(!str_ends_with(strtolower(trim($field)), "s")) {
			return $this->fieldParser->parseField($field, 0, 59);
		}

		$this->secondSet = $this->fieldParser->parseField(substr(trim($field), 0, -1), 0, 59);
		return $this->fieldParser->parseField("*", 0, 59);
	}

	private function hasSecondPrecision():bool {
		return !is_null($this->secondSet);
	}

	private function matchesSecond(int $second):bool {
		if(!$this->hasSecondPrecision()) {
			return true;
		}

		return isset($this->secondSet[$second]);
	}

	private function matchesDay(int $dayOfMonth, int $dayOfWeek):bool {
		$dayOfMonthMatches = isset($this->dayOfMonthSet[$dayOfMonth]);
		$dayOfWeekMatches = isset($this->dayOfWeekSet[$dayOfWeek])
			|| $this->matchesNthDayOfWeek($dayOfMonth, $dayOfWeek);

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
	 * @return array{0:array<int,bool>,1:bool,2:array<int,array<int,bool>>}
	 */
	private function parseDayOfWeekField(string $field):array {
		$field = trim($field);
		$isWildcard = $field === "*" || $field === "?";
		$standardSegmentList = [];
		$nthDayOfWeekSet = [];

		foreach(explode(",", $field) as $segment) {
			$segment = trim($segment);
			if($segment === "") {
				throw new InvalidArgumentException("Invalid CRON field value $field");
			}

			if(!str_contains($segment, "#")) {
				$standardSegmentList []= $segment;
				continue;
			}

			[$dayOfWeek, $nth] = $this->parseNthDayOfWeekSegment($segment);
			$nthDayOfWeekSet[$dayOfWeek][$nth] = true;
		}

		$dayOfWeekSet = [];
		if($standardSegmentList) {
			[$dayOfWeekSet] = $this->fieldParser->parseFieldWithWildcard(
				implode(",", $standardSegmentList),
				0,
				7,
				self::WEEKDAY_MAP,
				true
			);
		}

		return [$dayOfWeekSet, $isWildcard, $nthDayOfWeekSet];
	}

	/** @return array{0:int,1:int} */
	private function parseNthDayOfWeekSegment(string $segment):array {
		[$dayOfWeekPart, $nthPart] = explode("#", strtoupper($segment), 2);
		if($dayOfWeekPart === ""
		|| $nthPart === ""
		|| str_contains($nthPart, "#")
		|| !ctype_digit($nthPart)) {
			throw new InvalidArgumentException("Invalid CRON field value $segment");
		}

		$nth = (int)$nthPart;
		if($nth < 1 || $nth > 5) {
			throw new InvalidArgumentException("Invalid CRON field value $segment");
		}

		$dayOfWeekSet = $this->fieldParser->parseField(
			$dayOfWeekPart,
			0,
			7,
			self::WEEKDAY_MAP,
			true
		);
		if(count($dayOfWeekSet) !== 1) {
			throw new InvalidArgumentException("Invalid CRON field value $segment");
		}

		return [array_key_first($dayOfWeekSet), $nth];
	}

	private function matchesNthDayOfWeek(
		int $dayOfMonth,
		int $dayOfWeek
	):bool {
		if(!isset($this->nthDayOfWeekSet[$dayOfWeek])) {
			return false;
		}

		$nth = intdiv($dayOfMonth - 1, 7) + 1;
		return isset($this->nthDayOfWeekSet[$dayOfWeek][$nth]);
	}

	private function getMaxLookaheadSteps():int {
		if($this->hasSecondPrecision()) {
			return self::MAX_LOOKAHEAD_MINUTES * self::SECONDS_PER_MINUTE;
		}

		return self::MAX_LOOKAHEAD_MINUTES;
	}
}
