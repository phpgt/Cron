<?php
namespace GT\Cron;

readonly class CronScript {
	/**
	 * @param array<string, mixed> $query
	 */
	public function __construct(
		private string $path,
		private array $query = [],
	) {
	}

	public function getPath():string {
		return $this->path;
	}

	/** @return array<string, mixed> */
	public function getQuery():array {
		return $this->query;
	}
}
