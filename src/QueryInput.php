<?php
namespace GT\Cron;

use Gt\Input\Input;

class QueryInput {
	/**
	 * @param array<string, mixed> $query
	 */
	public function __construct(
		private readonly array $query,
	) {
	}

	public function getInput():Input {
		return new Input(
			array_map(
				static fn(mixed $value):string => is_array($value)
					? json_encode($value) ?: ""
					: (string)$value,
				$this->query
			)
		);
	}
}
