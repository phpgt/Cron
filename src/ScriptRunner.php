<?php
namespace GT\Cron;

class ScriptRunner {
	public function __construct(
		protected ScriptOutputMode $scriptOutputMode = ScriptOutputMode::DISCARD,
		?ResolvedScriptCommand $resolvedScriptCommand = null,
	) {
		$this->resolvedScriptCommand = $resolvedScriptCommand ?? new ResolvedScriptCommand();
	}

	protected ResolvedScriptCommand $resolvedScriptCommand;

	public function run(string $command):ScriptResult {
		$resolvedCommand = $this->resolvedScriptCommand->resolve($command);
		$descriptor = $this->createDescriptor();
		$pipes = [];
		$proc = proc_open($resolvedCommand, $descriptor, $pipes);
		if($proc === false) {
			throw new ScriptExecutionException($command);
		}

		$status = $this->waitForExit($proc);
		$result = $this->captureOutput($pipes);

		if($status["exitcode"] > 0) {
			throw new ScriptExecutionException($command);
		}

		$this->closePipes($pipes);
		proc_close($proc);

		return $result;
	}

	/** @return array<int, mixed> */
	protected function createDescriptor():array {
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

	/**
	 * @param mixed $proc
	 * @return array{running:bool,exitcode:int}
	 */
	protected function waitForExit($proc):array {
		do {
			$status = proc_get_status($proc);
		} while($status["running"]);

		return [
			"running" => (bool)$status["running"],
			"exitcode" => (int)$status["exitcode"],
		];
	}

	/** @param array<int,mixed> $pipes */
	protected function captureOutput(array $pipes):ScriptResult {
		if($this->scriptOutputMode !== ScriptOutputMode::CAPTURE) {
			return new ScriptResult();
		}

		$stdout = "";
		if(isset($pipes[1]) && is_resource($pipes[1])) {
			$stdout = stream_get_contents($pipes[1]) ?: "";
		}

		$stderr = "";
		if(isset($pipes[2]) && is_resource($pipes[2])) {
			$stderr = stream_get_contents($pipes[2]) ?: "";
		}

		return new ScriptResult($stdout, $stderr);
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
