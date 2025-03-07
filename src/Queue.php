<?php
namespace Gt\Cron;

use DateTime;

class Queue {
	/** @var Job[] */
	protected $jobList;
	/** @var DateTime|null The time to use as "now". If null, always use current system time */
	protected $now;

	public function __construct(?DateTime &$now = null) {
		$this->jobList = [];
		$this->now = $now;
	}

	public function add(Job $job):void {
		$this->jobList []= $job;
	}

	public function secondsUntilNextJob():?int {
		$nextJob = $this->getNextJob();
		$now = $this->now();
		$then = $nextJob->getNextRunDate($now);
		return $then->getTimestamp() - $now->getTimestamp();
	}

	public function timeOfNextJob():?DateTime {
		$nextJob = $this->getNextJob();

		if(!$nextJob) {
			return null;
		}

		$now = $this->now();
		return $nextJob->getNextRunDate($now);
	}

	public function runDueJobs():int {
		$jobsRan = 0;

		foreach($this->jobList as $job) {
			if(!$job->hasRun()
			&& $job->isDue($this->now())) {
				$job->run();
				$jobsRan++;
			}
		}

		return $jobsRan;
	}

	public function runAllJobs():int {
		$jobsRan = 0;

		foreach($this->jobList as $job) {
			$job->run();
			$jobsRan++;
		}

		return $jobsRan;
	}

	public function reset():void {
		foreach($this->jobList as $job) {
			$job->resetRunFlag();
		}
	}

	public function getNextJob():?Job {
		/** @var Job|null $nextJob */
		$nextJob = null;

		foreach($this->jobList as $job) {
			$jobRunDate = $job->getNextRunDate();

			if(is_null($nextJob)
			|| $jobRunDate < $nextJob->getNextRunDate()) {
				$nextJob = $job;
			}
		}

		return $nextJob;
	}

	public function now(?DateTime $newNow = null):DateTime {
		if(!is_null($newNow)) {
			$this->now = $newNow;
		}

		if(is_null($this->now)) {
			return new DateTime;
		}
		else {
			return clone $this->now;
		}
	}
}
