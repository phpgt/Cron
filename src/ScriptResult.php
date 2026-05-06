<?php
namespace GT\Cron;

class ScriptResult {
	public function __construct(
		public readonly string $stdout = "",
		public readonly string $stderr = "",
	) {
	}
}
