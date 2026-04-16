<?php
namespace Gt\Cron;

class FunctionCommand {
	public function isCallable(string $command):bool {
		$callable = $this->callableName($command);
		return str_contains($callable, "::") || is_callable($callable);
	}

	public function execute(string $command):void {
		$callableName = $this->callableName($command);
		$callable = explode("::", $callableName);
		if(!is_callable($callable)) {
			throw new FunctionExecutionException($callableName);
		}

		call_user_func_array($callable, $this->arguments($command));
	}

	protected function callableName(string $command):string {
		$bracketPos = strpos($command, "(");
		if($bracketPos === false) {
			return trim($command);
		}

		return trim(substr($command, 0, $bracketPos));
	}

	/** @return array<int, string> */
	protected function arguments(string $command):array {
		$bracketPos = strpos($command, "(");
		if($bracketPos === false) {
			return [];
		}

		$argsString = substr($command, $bracketPos);
		$argsString = trim($argsString, " ();");
		return str_getcsv($argsString, ",", "\"", "\\");
	}
}
