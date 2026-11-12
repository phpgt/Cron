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
			if($arguments->contains("generate-service")) {
				$options = [];
				foreach(["user", "group", "working-directory", "php-binary", "cron-binary", "restart-seconds"] as $name) {
					if($arguments->contains($name)) {
						$options[$name] = (string)$arguments->get($name)->get();
					}
				}
				$this->stream->writeLine("Generated " . $service->generate($options));
			}
			if($arguments->contains("install-service")) {
				$name = $service->install();
				$this->stream->writeLine("Enabled $name. Start with: systemctl start $name");
			}
			return 0;
		}
		catch(CronException $exception) {
			$this->stream->writeLine($exception->getMessage(), StreamName::ERROR);
			return 2;
		}
	}

	/** @return Parameter[] */
	public function getParameterList():array {
		return [
			new Parameter(false, "generate-service", null, "Generate cron.service in the project root."),
			new Parameter(false, "install-service", null, "Link cron.service into systemd and enable it at boot."),
			new Parameter(true, "user", null, "Service user (defaults to the vendor directory owner UID)."),
			new Parameter(true, "group", null, "Service group (defaults to the vendor directory group GID)."),
			new Parameter(true, "working-directory", null, "Project working directory (defaults to the current directory)."),
			new Parameter(true, "php-binary", null, "PHP executable (defaults to the current PHP binary)."),
			new Parameter(true, "cron-binary", null, "Cron script (defaults to vendor/bin/cron in the working directory)."),
			new Parameter(true, "restart-seconds", null, "Delay before restarting the scheduler (default 10 seconds)."),
		];
	}
}
