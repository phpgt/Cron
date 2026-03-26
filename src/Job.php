<?php
namespace Gt\Cron;

use DateTime;

class Job {
	protected Expression $expression;
	protected string $command;
	protected bool $hasRun;
	protected string $stdout;
	protected string $stderr;
	protected FunctionCommand $functionCommand;
	protected ScriptRunner $scriptRunner;

	public function __construct(
		Expression $expression,
		string $command,
		ScriptOutputMode $scriptOutputMode = ScriptOutputMode::DISCARD,
		?FunctionCommand $functionCommand = null,
		?ScriptRunner $scriptRunner = null,
	) {
		$this->expression = $expression;
		$this->command = $command;
		$this->hasRun = false;
		$this->stdout = "";
		$this->stderr = "";
		$this->functionCommand = $functionCommand ?? new FunctionCommand();
		$this->scriptRunner = $scriptRunner ?? new ScriptRunner($scriptOutputMode);
	}

	public function isDue(?DateTime $now = null):bool {
		if(is_null($now)) {
			$now = new DateTime();
		}

		return $this->expression->isDue($now);
	}

	public function getNextRunDate(?DateTime $now = null):DateTime {
		if(is_null($now)) {
			$now = new DateTime();
		}
		return $this->expression->getNextRunDate($now);
	}

	public function getCommand():string {
		return $this->command;
	}

	public function getStdout():string {
		return $this->stdout;
	}

	public function getStderr():string {
		return $this->stderr;
	}

	public function run():void {
		$this->hasRun = true;
		$this->stdout = "";
		$this->stderr = "";

		if($this->functionCommand->isCallable($this->command)) {
			$this->functionCommand->execute($this->command);
			return;
		}

		$scriptResult = $this->scriptRunner->run($this->command);
		$this->stdout = $scriptResult->stdout;
		$this->stderr = $scriptResult->stderr;
	}

	public function hasRun():bool {
		return $this->hasRun;
	}

	public function resetRunFlag():void {
		$this->hasRun = false;
	}

	public function isFunction():bool {
		return $this->functionCommand->isCallable($this->command);
	}
}
