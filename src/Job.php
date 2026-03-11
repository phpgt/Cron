<?php
namespace Gt\Cron;

use DateTime;

class Job {
	protected Expression $expression;
	protected string $command;
	protected bool $hasRun;
	protected ScriptOutputMode $scriptOutputMode;
	protected string $stdout;
	protected string $stderr;

	public function __construct(
		Expression $expression,
		string $command,
		ScriptOutputMode $scriptOutputMode = ScriptOutputMode::DISCARD
	) {
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
		$command = $this->resolveScriptCommand();
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

	protected function resolveScriptCommand():string {
		$scriptParts = $this->parseScriptCommand($this->command);
		if(is_null($scriptParts)) {
			return $this->command;
		}

		$script = $this->normaliseScriptName($scriptParts["script"]);
		if(!$this->isValidScriptName($script)) {
			return $this->command;
		}

		$scriptPath = $this->getLocalCronScriptPath($script);
		if(!is_file($scriptPath)) {
			return $this->command;
		}

		return PHP_BINARY
			. " "
			. escapeshellarg($scriptPath)
			. $scriptParts["args"];
	}

	/** @return null|array{script:string,args:string} */
	protected function parseScriptCommand(string $command):?array {
		$matches = [];
		if(!preg_match(
			"/^(?P<script>\\S+)(?P<args>\\s.*)?$/",
			$command,
			$matches
		)) {
			return null;
		}

		return [
			"script" => $matches["script"],
			"args" => $matches["args"] ?? "",
		];
	}

	protected function normaliseScriptName(string $script):string {
		if(substr(strtolower($script), -4) === ".php") {
			return substr($script, 0, -4);
		}

		return $script;
	}

	protected function isValidScriptName(string $script):bool {
		if(strpos($script, "/") !== false
		|| strpos($script, "\\") !== false) {
			return false;
		}

		return strlen($script) > 0
			&& preg_match("/^[a-zA-Z0-9._-]+$/", $script);
	}

	protected function getLocalCronScriptPath(string $script):string {
		return implode(DIRECTORY_SEPARATOR, [
			getcwd(),
			"cron",
			"$script.php",
		]);
	}
}
