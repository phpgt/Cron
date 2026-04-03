<?php
namespace Gt\Cron;

class Input extends \Gt\Input\Input {
	/**
	 * @param array<string, mixed> $query
	 */
	public static function fromQuery(array $query):self {
		return new self(
			array_map(
				static fn(mixed $value):string => is_array($value)
					? json_encode($value) ?: ""
					: (string)$value,
				$query
			)
		);
	}
}
