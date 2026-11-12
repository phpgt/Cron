<?php
namespace GT\Cron;

class Service {
	public function __construct(
		private readonly string $projectDirectory,
		private readonly string $unitDirectory = "/etc/systemd/system",
	) {
	}

	/** @param array<string, string> $options */
	public function generate(array $options = []):string {
		$workingDirectory = $this->absolutePath($options["working-directory"] ?? $this->projectDirectory);
		$vendor = "$workingDirectory/vendor";
		$user = $options["user"] ?? $this->owner($vendor);
		$group = $options["group"] ?? $this->group($vendor);
		$php = $this->absolutePath($options["php-binary"] ?? PHP_BINARY);
		$cron = $this->absolutePath($options["cron-binary"] ?? "$vendor/bin/cron");
		$restart = $options["restart-seconds"] ?? "10";

		$this->validateOptions($user, $group, $php, $cron, $restart);

		(new RunnerFactory())->createForProject($workingDirectory);
		$workingDirectory = $this->escapePath($workingDirectory);
		$php = $this->quote($php);
		$cron = $this->quote($cron, true);
		$contents = <<<UNIT
		[Unit]
		Description=PHP.GT cron scheduler
		Wants=network-online.target
		After=network-online.target
		StartLimitIntervalSec=0

		[Service]
		Type=simple
		User=$user
		Group=$group
		WorkingDirectory=$workingDirectory
		ExecStart=$php $cron --watch
		Restart=always
		RestartSec=$restart
		StandardOutput=journal
		StandardError=journal

		[Install]
		WantedBy=multi-user.target

		UNIT;
		$path = "$this->projectDirectory/cron.service";
		if(file_put_contents($path, $contents) === false) {
			throw new CronException("Unable to write $path.");
		}
		return $path;
	}

	private function validateOptions(string $user, string $group, string $php, string $cron, string $restart):void {
		if(!preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/D', $user)
		|| !preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/D', $group)) {
			throw new CronException("Invalid service user or group.");
		}
		if(!preg_match('/^\d+(\.\d+)?$/D', $restart) || (float)$restart <= 0) {
			throw new CronException("Restart seconds must be greater than zero.");
		}
		if(!is_executable($php) || !is_readable($cron)) {
			throw new CronException("The PHP binary must be executable and the cron binary readable.");
		}
	}

	public function install(bool $start = true):string {
		$source = realpath("$this->projectDirectory/cron.service");
		if($source === false || !is_file($source)) {
			throw new CronException("Generate cron.service before installing it.");
		}
		$name = basename($this->projectDirectory) . "-cron.service";
		if(!preg_match('/^[a-zA-Z0-9_.-]+-cron\.service$/D', $name)) {
			throw new CronException("Project directory name is not a valid service name.");
		}
		$target = "$this->unitDirectory/$name";
		$this->link($source, $target);

		$this->systemctl(["daemon-reload"]);
		$arguments = ["enable"];
		if($start) {
			$arguments []= "--now";
		}
		$arguments []= $name;
		$this->systemctl($arguments);
		return $name;
	}

	private function link(string $source, string $target):void {
		if(file_exists($target) || is_link($target)) {
			if(!is_link($target) || realpath($target) !== $source) {
				throw new CronException("Refusing to replace existing service: $target");
			}
		}
		elseif(!symlink($source, $target)) {
			throw new CronException("Unable to link $target. Installation requires system privileges.");
		}
	}

	/** @param list<string> $arguments */
	protected function systemctl(array $arguments):void {
		$output = tmpfile();
		if($output === false) {
			throw new CronException("Unable to capture systemctl output.");
		}
		try {
			$process = proc_open(["systemctl", ...$arguments], [0 => ["pipe", "r"], 1 => $output, 2 => $output], $pipes);
			if(!is_resource($process)) {
				throw new CronException("Unable to execute systemctl.");
			}
			fclose($pipes[0]);
			$status = proc_close($process);
			rewind($output);
			if($status !== 0) {
				throw new CronException(
					"systemctl " . implode(" ", $arguments) . " failed: " . stream_get_contents($output)
				);
			}
		}
		finally {
			fclose($output);
		}
	}

	private function absolutePath(string $path):string {
		if(!str_starts_with($path, "/")) {
			$path = "$this->projectDirectory/$path";
		}
		$resolved = realpath($path);
		if($resolved === false) {
			throw new CronException("Path does not exist: $path");
		}
		return $resolved;
	}

	private function owner(string $path):string {
		$id = is_dir($path) ? fileowner($path) : false;
		if($id === false) {
			throw new CronException("Cannot infer service user: $path does not exist or is inaccessible.");
		}
		return (string)$id;
	}

	private function group(string $path):string {
		$id = is_dir($path) ? filegroup($path) : false;
		if($id === false) {
			throw new CronException("Cannot infer service group: $path does not exist or is inaccessible.");
		}
		return (string)$id;
	}

	private function escapePath(string $value):string {
		if(preg_match('/[\x00-\x1f\x7f]/', $value)) {
			throw new CronException("Service paths cannot contain control characters.");
		}
		return str_replace(["\\", "%"], ["\\\\", "%%"], $value);
	}

	private function quote(string $value, bool $expandEnvironment = false):string {
		$value = $this->escapePath($value);
		if($expandEnvironment) {
			$value = str_replace('$', '$$', $value);
		}
		return '"' . str_replace('"', '\\"', $value) . '"';
	}
}
