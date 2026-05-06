<?php
namespace GT\Cron;

class ScriptCommandResolver {
	public function __construct(
		private readonly string $projectDirectory = ""
	) {
	}

	public function resolve(string $command):string {
		$scriptParts = $this->parseScriptCommand($command);
		if(is_null($scriptParts)) {
			return $command;
		}

		$script = $this->normaliseCronScriptName($scriptParts["script"]);
		if(!$this->isValidCronScriptName($script)) {
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

	public function resolveCronScript(string $command):?CronScript {
		$scriptParts = $this->parseScriptCommand($command);
		if(is_null($scriptParts) || !empty(trim($scriptParts["args"]))) {
			return null;
		}

		[$script, $queryString] = $this->splitScriptAndQuery($scriptParts["script"]);
		$script = $this->normaliseCronScriptName($script);
		if(!$this->isValidCronScriptName($script)) {
			return null;
		}

		$scriptPath = $this->getLocalCronScriptPath($script);
		if(!is_file($scriptPath) || !$this->containsGoFunction($scriptPath)) {
			return null;
		}

		$query = [];
		parse_str($queryString, $query);
		return new CronScript($scriptPath, $query);
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

	protected function normaliseCronScriptName(string $script):string {
		$script = ltrim($script, "/");
		if(str_starts_with(strtolower($script), "cron/")) {
			$script = substr($script, 5);
		}

		if(substr(strtolower($script), -4) === ".php") {
			return substr($script, 0, -4);
		}

		return trim($script, "/");
	}

	protected function isValidCronScriptName(string $script):bool {
		if(strpos($script, "\\") !== false
		|| str_contains($script, "..")) {
			return false;
		}

		return strlen($script) > 0
			&& preg_match("/^[a-zA-Z0-9._\\/-]+$/", $script);
	}

	protected function getLocalCronScriptPath(string $script):string {
		return implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory ?: getcwd(),
			"cron",
			"$script.php",
		]);
	}

	/** @return array{0:string,1:string} */
	protected function splitScriptAndQuery(string $script):array {
		$parts = explode("?", $script, 2);
		return [
			$parts[0],
			$parts[1] ?? "",
		];
	}

	protected function containsGoFunction(string $scriptPath):bool {
		$contents = file_get_contents($scriptPath);
		if($contents === false) {
			return false;
		}

		return (bool)preg_match('/function\s+go\s*\(/i', $contents);
	}
}
