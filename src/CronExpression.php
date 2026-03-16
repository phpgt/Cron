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

		$this->minuteSet = $this->fieldParser->parseField($parts[0], 0, 59);
		$this->hourSet = $this->fieldParser->parseField($parts[1], 0, 23);
		[$this->dayOfMonthSet, $this->dayOfMonthWildcard] = $this->fieldParser->parseFieldWithWildcard($parts[2], 1, 31);
		$this->monthSet = $this->fieldParser->parseField($parts[3], 1, 12, self::MONTH_MAP);
		[$this->dayOfWeekSet, $this->dayOfWeekWildcard] = $this->fieldParser->parseFieldWithWildcard(
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
}
