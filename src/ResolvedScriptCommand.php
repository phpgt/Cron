<?php
namespace Gt\Cron;

class ResolvedScriptCommand {
	public function resolve(string $command):string {
		$matches = [];
		if(!preg_match("/^(?P<script>\\S+)(?P<args>\\s.*)?$/", $command, $matches)) {
			return $command;
		}

		$script = $this->normaliseScriptName($matches["script"]);
		if(!$this->isValidScriptName($script)) {
			return $command;
		}

		$scriptPath = implode(DIRECTORY_SEPARATOR, [
			getcwd(),
			"cron",
			"$script.php",
		]);
		if(!is_file($scriptPath)) {
			return $command;
		}

		return PHP_BINARY
			. " "
			. escapeshellarg($scriptPath)
			. ($matches["args"] ?? "");
	}

	protected function normaliseScriptName(string $script):string {
		if(substr(strtolower($script), -4) === ".php") {
			return substr($script, 0, -4);
		}

		return $script;
	}

	protected function isValidScriptName(string $script):bool {
		if(str_contains($script, "/")
		|| str_contains($script, "\\")) {
			return false;
		}

		return strlen($script) > 0
			&& preg_match("/^[a-zA-Z0-9._-]+$/", $script);
	}
}
