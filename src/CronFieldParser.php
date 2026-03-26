<?php
namespace Gt\Cron;

use InvalidArgumentException;

class CronFieldParser {
	/**
	 * @param array<string,int> $nameMap
	 * @return array<int,bool>
	 */
	public function parseField(
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
	public function parseFieldWithWildcard(
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
		[$segment, $step] = $this->parseSegmentStep($segment);
		[$start, $end] = $this->resolveSegmentRange(
			$segment,
			$min,
			$max,
			$nameMap,
			$normaliseWeekday
		);

		$values = [];
		for($value = $start; $value <= $end; $value += $step) {
			array_push($values, $normaliseWeekday && $value === 7 ? 0 : $value);
		}

		return $values;
	}

	/** @return array{0:string,1:int} */
	private function parseSegmentStep(string $segment):array {
		if(!str_contains($segment, "/")) {
			return [$segment, 1];
		}

		[$rangeSegment, $stepPart] = explode("/", $segment, 2);
		if($stepPart === "" || !ctype_digit($stepPart) || (int)$stepPart < 1) {
			throw new InvalidArgumentException("Invalid CRON field value $rangeSegment/$stepPart");
		}

		return [$rangeSegment, (int)$stepPart];
	}

	/**
	 * @param array<string,int> $nameMap
	 * @return array{0:int,1:int}
	 */
	private function resolveSegmentRange(
		string $segment,
		int $min,
		int $max,
		array $nameMap,
		bool $normaliseWeekday
	):array {
		if($segment === "*" || $segment === "?") {
			return [$min, $max];
		}

		if(!str_contains($segment, "-")) {
			$value = $this->normaliseValue($segment, $min, $max, $nameMap, $normaliseWeekday);
			return [$value, $value];
		}

		[$startPart, $endPart] = explode("-", $segment, 2);
		$start = $this->normaliseValue($startPart, $min, $max, $nameMap, $normaliseWeekday);
		$end = $this->normaliseValue($endPart, $min, $max, $nameMap, $normaliseWeekday);
		if($end < $start) {
			throw new InvalidArgumentException("Invalid CRON field value $segment");
		}

		return [$start, $end];
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
