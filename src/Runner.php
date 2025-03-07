<?php
namespace Gt\Cron;

use Cron\CronExpression;
use DateTime;
use InvalidArgumentException;

class Runner {
	/** @var bool */
	public $continue;
	/** @var Queue */
	protected $queue;
	/** @var callable */
	protected $runCallback;
	/** @var int */
	protected $numJobs;

	public function __construct(
		JobRepository $jobRepository,
		QueueRepository $queueRepository,
		string $contents,
		?DateTime $now = null,
	) {
		$this->queue = call_user_func(
			[$queueRepository, "createAtTime"],
			$now ?? new DateTime()
		);

		$this->numJobs = 0;

		foreach(explode("\n", $contents) as $line) {
			$line = trim($line);
			if(strlen($line) === 0) {
				continue;
			}

			if($line[0] === "#") {
				continue;
			}

			preg_match(
				"/(?P<crontab>\S+\s\S+\s\S+\s\S+\s\S+)\s(?P<command>.+)/",
				$line,
				$matches
			);

			$crontab = $matches["crontab"] ?? null;
			$command = $matches["command"] ?? null;

			$crontab = trim($crontab);
			$command = trim($command);

			try {
				$job = call_user_func(
					[$jobRepository, "create"],
					CronExpression::factory($crontab),
					$command
				);
				$this->queue->add($job);
			}
			catch(InvalidArgumentException $exception) {
				throw new ParseException("Invalid syntax: $line");
			}

			$this->numJobs++;
		}
	}

	public function getNumJobs():int {
		return $this->numJobs;
	}

	public function setRunCallback(callable $callback):void {
		$this->runCallback = $callback;
	}

	public function run(bool $continue = false):int {
		$this->continue = $continue;

		do {
			$jobsRan = 0;
			$this->queue->reset();

			$jobsRan += $this->queue->runDueJobs();

			if(is_callable($this->runCallback)) {
				$this->queue->now(new DateTime());

				call_user_func(
					$this->runCallback,
					$jobsRan,
					$this->queue->timeOfNextJob(),
					$continue
				);
			}

			if($this->continue) {
				sleep($this->queue->secondsUntilNextJob());
			}
		}
		while($this->continue);

		return $jobsRan;
	}

	public function runAll():int {
		return $this->queue->runAllJobs();
	}
}
