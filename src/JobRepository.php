<?php
namespace Gt\Cron;

class JobRepository {
	public function __construct(
		protected ScriptOutputMode $scriptOutputMode = ScriptOutputMode::DISCARD,
		protected string $projectDirectory = "",
	) {
	}

	public function create(Expression $expression, string $command):Job {
		return new Job(
			$expression,
			$command,
			$this->scriptOutputMode,
			$this->projectDirectory,
		);
	}
}
