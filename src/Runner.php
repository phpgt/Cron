<?php
namespace GT\Cron;

use DateTime;

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
		?ExpressionFactory $expressionFactory = null,
		?CrontabParser $crontabParser = null,
	) {
		$this->queue = call_user_func(
			[$queueRepository, "createAtTime"],
			$now ?? new DateTime()
		);
		$crontabParser ??= new CrontabParser($expressionFactory);
		$this->numJobs = $crontabParser->parseIntoQueue(
			$contents,
			$this->queue,
			$jobRepository
		);
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
			$this->queue->reset();
			$runCommandList = $this->queue->runDueJobsAndGetCommands();
			$jobsRan = count($runCommandList);

			if(is_callable($this->runCallback)) {
				$this->queue->now(new DateTime());

				call_user_func(
					$this->runCallback,
					$jobsRan,
					$this->queue->timeOfNextJob(),
					$continue,
					$runCommandList,
					$this->queue->commandOfNextJob()
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

	/** @param callable(string):bool $matches */
	public function runMatching(callable $matches):int {
		return $this->queue->runAllJobs($matches);
	}
}
