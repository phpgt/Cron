<?php
namespace Gt\Cron;

use DateTime;

class Job {
	protected ScriptCommandResolver $scriptCommandResolver;
	protected GoFunctionExecutor $goFunctionExecutor;
	protected Expression $expression;
	protected string $command;
	protected bool $hasRun;
	protected ScriptOutputMode $scriptOutputMode;
	protected string $stdout;
	protected string $stderr;

	public function __construct(
		Expression $expression,
		string $command,
		ScriptOutputMode $scriptOutputMode = ScriptOutputMode::DISCARD,
		?string $projectDirectory = null,
	) {
		$projectDirectory = $projectDirectory ?? getcwd();
		$this->scriptCommandResolver = new ScriptCommandResolver($projectDirectory);
		$this->goFunctionExecutor = new GoFunctionExecutor($projectDirectory);
		$this->expression = $expression;
		$this->command = $command;
		$this->hasRun = false;
		$this->scriptOutputMode = $scriptOutputMode;
		$this->stdout = "";
		$this->stderr = "";
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

		if($this->isCronScript()) {
			$this->executeCronScript();
		}
		elseif($this->isFunction()) {
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

	public function isCronScript():bool {
		return !is_null($this->scriptCommandResolver->resolveCronScript($this->command));
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
			$args = str_getcsv($argsString, ",", "\"", "\\");

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
		$command = $this->scriptCommandResolver->resolve($this->command);
		$descriptor = $this->createScriptDescriptor();
		$pipes = [];

		$proc = proc_open(
			$command,
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

		if($proc) {
			$this->captureProcessOutput($pipes);
		}

		if($status["exitcode"] > 0) {
			throw new ScriptExecutionException(
				$this->command
			);
		}

		if($proc) {
			$this->closePipes($pipes);
			proc_close($proc);
		}
	}

	protected function executeCronScript():void {
		$cronScript = $this->scriptCommandResolver->resolveCronScript($this->command);
		if(is_null($cronScript)) {
			throw new FunctionExecutionException($this->command);
		}

		$this->goFunctionExecutor->execute($cronScript);
	}

	/** @return array<int, mixed> */
	protected function createScriptDescriptor():array {
		$stdin = ["pipe", "r"];

		return match($this->scriptOutputMode) {
			ScriptOutputMode::INHERIT => [
				0 => $stdin,
				1 => ["file", "php://stdout", "w"],
				2 => ["file", "php://stderr", "w"],
			],
			ScriptOutputMode::CAPTURE => [
				0 => $stdin,
				1 => ["pipe", "w"],
				2 => ["pipe", "w"],
			],
			default => [
				0 => $stdin,
				1 => ["file", $this->nullDevice(), "w"],
				2 => ["file", $this->nullDevice(), "w"],
			],
		};
	}

	/** @param array<int,mixed> $pipes */
	protected function captureProcessOutput(array $pipes):void {
		if($this->scriptOutputMode !== ScriptOutputMode::CAPTURE) {
			return;
		}

		if(isset($pipes[1]) && is_resource($pipes[1])) {
			$this->stdout = stream_get_contents($pipes[1]) ?: "";
		}

		if(isset($pipes[2]) && is_resource($pipes[2])) {
			$this->stderr = stream_get_contents($pipes[2]) ?: "";
		}
	}

	/** @param array<int,mixed> $pipes */
	protected function closePipes(array $pipes):void {
		foreach($pipes as $pipe) {
			if(is_resource($pipe)) {
				fclose($pipe);
			}
		}
	}

	protected function nullDevice():string {
		if(PHP_OS_FAMILY === "Windows") {
			return "NUL";
		}

		return "/dev/null";
	}
}
