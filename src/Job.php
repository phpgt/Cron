<?php
namespace Gt\Cron;

use Cron\CronExpression;
use DateTime;

class Job {
	protected CronExpression $expression;
	protected string $command;
	protected bool $hasRun;

	public function __construct(CronExpression $expression, string $command) {
		$this->expression = $expression;
		$this->command = $command;
		$this->hasRun = false;
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

	public function run():void {
		$this->hasRun = true;

		if($this->isFunction()) {
			$this->executeFunction();
		}
		else {
// Assume the command is a shell command.
			$this->executeScript();
		}
	}

	public function hasRun():bool {
		return $this->hasRun;
	}

	public function resetRunFlag():void {
		$this->hasRun = false;
	}

	public function isFunction():bool {
		$command = $this->command;
		$bracketPos = strpos(
			$command,
			"("
		);
		if($bracketPos !== false) {
			$command = substr($command, 0, $bracketPos);
			$command = trim($command);
		}

		return strstr($command, "::")
			|| is_callable($command);
	}

	protected function executeFunction():void {
		$command = $this->command;
		$args = [];
		$bracketPos = strpos($command, "(");
		if($bracketPos !== false) {
			$argsString = substr(
				$command,
				$bracketPos
			);
			$argsString = trim($argsString, " ();");
			$args = str_getcsv($argsString);

			$command = substr(
				$command,
				0,
				$bracketPos
			);
			$command = trim($command);
		}

		$callable = explode("::", $command);

		if(!is_callable($callable)) {
			throw new FunctionExecutionException($command);
		}
		call_user_func_array($callable, $args);
	}

	protected function executeScript():void {
		$descriptor = [
			0 => ["pipe", "r"],
			1 => ["pipe", "w"],
			2 => ["pipe", "w"],
		];
		$pipes = [];

		$proc = proc_open(
			$this->command,
			$descriptor,
			$pipes
		);

		do {
			if($proc) {
				$status = proc_get_status($proc);
			}
			else {
				$status = [
					"running" => false,
					"exitcode" => -1,
				];
			}
		}while($status["running"]);

		if($status["exitcode"] > 0) {
			throw new ScriptExecutionException(
				$this->command
			);
		}

		if($proc) {
			proc_close($proc);
		}
	}
}
