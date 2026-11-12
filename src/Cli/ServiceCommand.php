<?php
namespace GT\Cron\Cli;

use GT\Cli\Argument\ArgumentValueList;
use GT\Cli\Parameter\Parameter;
use GT\Cli\Stream;
use GT\Cli\StreamName;
use GT\Cron\CronException;
use GT\Cron\Service;

class ServiceCommand {
	public function __construct(private readonly Stream $stream = new Stream()) {
	}

	public function run(ArgumentValueList $arguments):int {
		try {
			$service = new Service(getcwd());
			if($this->shouldGenerate($arguments)) {
				$options = [];
				foreach(["user", "group", "working-directory", "php-binary", "cron-binary", "restart-seconds"] as $name) {
					if($arguments->contains($name)) {
						$options[$name] = (string)$arguments->get($name)->get();
					}
				}
				$this->stream->writeLine("Generated " . $service->generate($options));
			}
			if($arguments->contains("install-service")) {
				$noStart = $arguments->contains("no-start");
				$name = $service->install(!$noStart);
				$message = $noStart
					? "Enabled $name. Start with: systemctl start $name"
					: "Enabled and started $name.";
				$this->stream->writeLine($message);
			}
			return 0;
		}
		catch(CronException $exception) {
			$this->stream->writeLine($exception->getMessage(), StreamName::ERROR);
			return 2;
		}
	}

	private function shouldGenerate(ArgumentValueList $arguments):bool {
		if($arguments->contains("generate-service")) {
			return true;
		}

		$path = getcwd() . "/cron.service";
		return $arguments->contains("install-service") && !file_exists($path) && !is_link($path);
	}

	/** @return Parameter[] */
	public function getParameterList():array {
		return [
			new Parameter(false, "generate-service", null, "Generate cron.service in the project root."),
			new Parameter(
				false,
				"install-service",
				null,
				"Generate cron.service if missing, link it into systemd, enable it at boot and start it.",
			),
			new Parameter(false, "no-start", null, "Enable the installed service at boot without starting it now."),
			new Parameter(true, "user", null, "Service user (defaults to the vendor directory owner UID)."),
			new Parameter(true, "group", null, "Service group (defaults to the vendor directory group GID)."),
			new Parameter(true, "working-directory", null, "Project working directory (defaults to the current directory)."),
			new Parameter(true, "php-binary", null, "PHP executable (defaults to the current PHP binary)."),
			new Parameter(true, "cron-binary", null, "Cron script (defaults to vendor/bin/cron in the working directory)."),
			new Parameter(true, "restart-seconds", null, "Delay before restarting the scheduler (default 10 seconds)."),
		];
	}
}
