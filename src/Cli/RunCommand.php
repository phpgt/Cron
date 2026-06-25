<?php
namespace GT\Cron\Cli;

use DateTime;
use DateTimeZone;
use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Parameter\Parameter;
use Gt\Cli\Stream;
use GT\Cron\CronException;
use GT\Cron\CrontabNotFoundException;
use GT\Cron\FunctionExecutionException;
use GT\Cron\JobNotFoundException;
use GT\Cron\Runner;
use GT\Cron\RunnerFactory;
use GT\Cron\ScriptExecutionException;

class RunCommand extends Command {
	/** @SuppressWarnings(PHPMD.ExitExpression) */
	public function run(?ArgumentValueList $arguments = null):int {
		$this->applySystemTimezone();

		$filename = $arguments->get("file", "crontab");
		$filePath = implode(DIRECTORY_SEPARATOR, [
			getcwd(),
			$filename,
		]);

		try {
			$runner = (new RunnerFactory())->createForProject(
				getcwd(),
				$filename
			);
		}
		catch(CrontabNotFoundException) {
			$this->stream->writeLine("Skipping cron as there is no crontab file.");
			return 1;
		}
		catch(CronException $exception) {
			$this->stream->writeLine(
				$exception->getMessage(),
				Stream::ERROR
			);
			return 2;
		}

		if($arguments->contains("validate")) {
			$this->writeLine("Syntax OK at $filePath");
			exit(0);
		}

		$runner->setRunCallback([$this, "cronRunStep"]);

		$nowStatusCode = $this->runNowJobs($runner, $arguments);
		if(!is_null($nowStatusCode)) {
			return $nowStatusCode;
		}

		try {
			$runner->run($arguments->contains("watch"));
		}
		catch(ScriptExecutionException $exception) {
			$this->stream->writeLine(
				"Error executing command: "
				. $exception->getMessage(),
				Stream::ERROR
			);
		}
		catch(FunctionExecutionException $exception) {
			$this->stream->writeLine(
				"Error executing function: "
				. $exception->getMessage(),
				Stream::ERROR
			);
		}

		return 0;
	}

	private function runNowJobs(
		Runner $runner,
		ArgumentValueList $arguments
	):?int {
		if(!$arguments->contains("now")) {
			return null;
		}

		$jobName = $arguments->get("now")->get();
		try {
			if($jobName) {
				$numRunJobs = $this->runNamedJobNow($runner, $jobName);
				$this->stream->writeLine(
					"Ran $numRunJobs "
					. ($numRunJobs === 1 ? "job" : "jobs")
					. " now."
				);

				return $arguments->contains("watch") ? null : 0;
			}

			$numRunJobs = $runner->runAll();
			$this->stream->writeLine("Ran $numRunJobs jobs now.");
		}
		catch(JobNotFoundException $exception) {
			$this->stream->writeLine(
				$exception->getMessage(),
				Stream::ERROR
			);
			return 2;
		}

		return null;
	}

	private function runNamedJobNow(
		Runner $runner,
		string $jobName,
	):int {
		$jobName = trim($jobName);
		$numRunJobs = $runner->runMatching(
			fn(string $command):bool => $this->displayCommandName($command)
				=== $jobName
		);

		if(!$numRunJobs) {
			throw new JobNotFoundException(
				"No cron job found matching \"$jobName\" in crontab."
			);
		}

		return $numRunJobs;
	}

	/**
	 * @param array<string> $runCommandList
	 * @SuppressWarnings(PHPMD.ExitExpression)
	 */
	public function cronRunStep(
		int $jobsRan,
		?DateTime $wait,
		bool $continue,
		array $runCommandList = [],
		?string $nextCommand = null
	):void {
		$now = new DateTime();
		$this->stream->writeLine("Current time: " . $this->formatLocalTime($now));

		if(is_null($wait)) {
			$this->writeLine("No tasks in crontab.");
			exit(0);
		}

		$jobPlural = $jobsRan === 1 ? "job" : "jobs";
		$displayedRunCommands = array_map(
			function(string $command):string {
				return $this->displayCommandName($command);
			},
			$runCommandList
		);

		$message = "Just ran $jobsRan $jobPlural";
		if($displayedRunCommands) {
			$message .= " (" . implode(", ", $displayedRunCommands) . ")";
		}

		$this->stream->writeLine($message);

		$message = "Next job at: " . $this->formatLocalTime($wait);
		if($nextCommand) {
			$message .= " [" . $this->displayCommandName($nextCommand) . "]";
		}

		if($now->diff($wait)->format("%a") > 0) {
			$message .= " on " . $wait->format("dS M");
		}
		if($now->diff($wait)->format("%y") > 0) {
			$message .= " " . $wait->format("Y");
		}
		$this->stream->writeLine($message);

		if($continue) {
			$this->stream->writeLine("Waiting...");
		}
	}

	protected function displayCommandName(string $command):string {
		$command = trim($command);
		if(strpos($command, "::") !== false) {
			$functionExpression = trim(
				preg_replace("/\\s*\\(.+$/", "", $command)
			);
			[$class, $method] = explode("::", $functionExpression, 2);
			$class = basename(str_replace("\\", "/", $class));
			return "$class::$method";
		}

		$script = preg_split("/\\s+/", $command, 2)[0];
		[$script] = explode("?", $script, 2);
		$script = preg_replace("/\\.php$/i", "", $script);

		return basename(str_replace("\\", "/", $script));
	}

	protected function applySystemTimezone():void {
		if($timezone = $this->detectSystemTimezone()) {
			date_default_timezone_set($timezone);
		}
	}

	protected function detectSystemTimezone():?string {
		return $this->detectTimezoneFromEnvironment()
			?? $this->detectTimezoneFromLocaltime()
			?? $this->detectTimezoneFromTimezoneFile();
	}

	protected function detectTimezoneFromEnvironment():?string {
		$environmentTimezone = getenv("TZ");
		if($environmentTimezone !== false
		&& $this->isValidTimezone($environmentTimezone)) {
			return $environmentTimezone;
		}

		return null;
	}

	protected function detectTimezoneFromLocaltime():?string {
		$localtimePath = "/etc/localtime";
		if(is_link($localtimePath)) {
			$link = readlink($localtimePath);
			if($link !== false
			&& preg_match("#/zoneinfo/(.+)$#", $link, $match)
			&& $this->isValidTimezone($match[1])) {
				return $match[1];
			}
		}

		return null;
	}

	protected function detectTimezoneFromTimezoneFile():?string {
		$timezonePath = "/etc/timezone";
		if(is_file($timezonePath)) {
			$timezone = file_get_contents($timezonePath);
			if($timezone === false) {
				return null;
			}

			$timezone = trim($timezone);
			if($this->isValidTimezone($timezone)) {
				return $timezone;
			}
		}

		return null;
	}

	protected function isValidTimezone(string $timezone):bool {
		return in_array(
			$timezone,
			DateTimeZone::listIdentifiers(),
			true
		);
	}

	protected function formatLocalTime(DateTime $dateTime):string {
		$local = clone $dateTime;
		$local->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$message = $local->format("H:i:s");

		if($local->getOffset() !== 0) {
			$utc = clone $local;
			$utc->setTimezone(new DateTimeZone("UTC"));
			$message .= " (" . $utc->format("H:i:s") . " UTC)";
		}

		return $message;
	}

	public function getName():string {
		return "run";
	}

	public function getDescription():string {
		return "Start a long-running process to execute each job when it is due";
	}

	/** @return  NamedParameter[] */
	public function getRequiredNamedParameterList():array {
		return [];
	}

	/** @return  NamedParameter[] */
	public function getOptionalNamedParameterList():array {
		return [
			new NamedParameter("file"),
		];
	}

	/** @return  Parameter[] */
	public function getRequiredParameterList():array {
		return [];
	}

	/** @return  Parameter[] */
	public function getOptionalParameterList():array {
		return [
			new Parameter(
				false,
				"watch",
				"w",
				"Pass this flag to continue running cron commands as they become due. Without this flag, cron will only run the commands that are due at the point of executing the command." // phpcs:ignore Generic.Files.LineLength.TooLong
			),
			new Parameter(
				false,
				"validate",
				null,
				"Check the syntax of the crontab file without running anything."
			),
			new Parameter(
				true,
				"now",
				"n",
				"Run all tasks once now, or pass a job name to run only that task. Useful when using --watch for when developing locally." // phpcs:ignore Generic.Files.LineLength.TooLong
			)
		];
	}
}
