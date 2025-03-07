<?php
namespace Gt\Cron\Cli;

use DateTime;
use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Parameter\Parameter;
use Gt\Cli\Stream;
use Gt\Cron\CronException;
use Gt\Cron\FunctionExecutionException;
use Gt\Cron\RunnerFactory;
use Gt\Cron\ScriptExecutionException;

class RunCommand extends Command {
	/** @SuppressWarnings(PHPMD.ExitExpression) */
	public function run(?ArgumentValueList $arguments = null):void {
		$filename = $arguments->get("file", "crontab");
		$filePath = implode(DIRECTORY_SEPARATOR, [
			getcwd(),
			$filename,
		]);

		try {
			$runner = RunnerFactory::createForProject(
				getcwd(),
				$filename
			);
		}
		catch(CronException $exception) {
			$this->stream->writeLine(
				$exception->getMessage(),
				Stream::ERROR
			);
			return;
		}

		if($arguments->contains("validate")) {
			$this->writeLine("Syntax OK at $filePath");
			exit(0);
		}

		$runner->setRunCallback([$this, "cronRunStep"]);

		if($arguments->contains("now")) {
			$numRunJobs = $runner->runAll();
			$this->stream->writeLine("Ran $numRunJobs jobs now.");
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
	}

	/** @SuppressWarnings(PHPMD.ExitExpression) */
	public function cronRunStep(
		int $jobsRan,
		?DateTime $wait,
		bool $continue
	):void {
		$message = "";
		$now = new DateTime();

		if(is_null($wait)) {
			$this->writeLine("No tasks in crontab.");
			exit(0);
		}

		$jobPlural = "job";
		if($jobsRan !== 1) {
			$jobPlural .= "s";
		}

		if($jobsRan > 0) {
			$message = "Just ran $jobsRan $jobPlural, ";
		}


		$message .= "next job at: " . $wait->format("H:i:s");

		if($now->diff($wait)->format("%a") > 0) {
			$message .= " on " . $wait->format("dS M");
		}
		if($now->diff($wait)->format("%y") > 0) {
			$message .= " " . $wait->format("Y");
		}

		if($continue) {
			$message .= ". Waiting...";
		}
		else {
			$message .= ". Stopping now.";
		}

		$this->stream->writeLine(
			ucfirst($message)
		);
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
				"Run all tasks once now. Useful when using --watch for when developing locally."
			)
		];
	}
}
