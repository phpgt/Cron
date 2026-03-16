<?php
namespace Gt\Cron;

class ScriptCommandResolver {
	public function resolve(string $command):string {
		$scriptParts = $this->parseScriptCommand($command);
		if(is_null($scriptParts)) {
			return $command;
		}

		$script = $this->normaliseScriptName($scriptParts["script"]);
		if(!$this->isValidScriptName($script)) {
			return $command;
		}

		$scriptPath = $this->getLocalCronScriptPath($script);
		if(!is_file($scriptPath)) {
			return $command;
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
