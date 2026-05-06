<?php
namespace GT\Cron\Test;

use DateTime;
use GT\Cron\Job;
use GT\Cron\Queue;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase {
	public function testGetNextJobEmpty() {
		$queue = new Queue();
		self::assertNull($queue->getNextJob());
	}

	public function testNextJobNotEmpty() {
		/** @var Job $job */
		$job = self::createMock(Job::class);
		$queue = new Queue();
		$queue->add($job);
		self::assertNotNull($queue->getNextJob());
	}

	public function testTimeOfNextJob() {
		$expectedTimeOfNextJob = new DateTime("+5 minutes");

		$job = self::createMock(Job::class);
		$job->method("getNextRunDate")
			->willReturn($expectedTimeOfNextJob);

		/** @var Job $job */
		$queue = new Queue();
		$queue->add($job);

		self::assertEquals(
			$expectedTimeOfNextJob,
			$queue->timeOfNextJob()
		);
	}

	public function testSecondsUntilNextJob() {
		$expectedTimeOfNextJob = new DateTime("+5 minutes");

		$job = self::createMock(Job::class);
		$job->method("getNextRunDate")
			->willReturn($expectedTimeOfNextJob);

		/** @var Job $job */
		$queue = new Queue();
		$queue->add($job);

		self::assertEquals(
			60 * 5,
			$queue->secondsUntilNextJob()
		);
	}

	public function testRunDueJobs() {
		$job = self::createMock(Job::class);

		$job->method("hasRun")
			->willReturn(false);
		$job->method("isDue")
			->willReturn(true);

		$job->expects($this->exactly(3))
			->method("run");

		/** @var Job $job*/
		$queue = new Queue();
		$queue->add($job);
		$queue->add($job);
		$queue->add($job);

		self::assertEquals(
			3,
			$queue->runDueJobs()
		);
	}

	public function testReset() {
		$job = self::createMock(Job::class);
		$job->expects($this->exactly(4))
			->method("resetRunFlag");

		/** @var Job $job */
		$queue = new Queue();
		$queue->add($job);
		$queue->add($job);
		$queue->add($job);
		$queue->add($job);

		$queue->reset();
	}

	public function testNow() {
		$then = [];
		$then []= new DateTime("+4 minutes");
		$then []= new DateTime("+6 minutes");
		$now = new DateTime("+5 minutes");
		$thenToggle = false;

		$job = self::createMock(Job::class);
		$job->method("getNextRunDate")
		->willReturnCallback(function()
		use($then, &$thenToggle) {
			$value = $then[(int)$thenToggle];
			$thenToggle = !$thenToggle;
			return $value;
		});

		/** @var Job $job */
		$queue = new Queue($now);
		$queue->add($job);
		$queue->add($job);

		self::assertEquals(
			$then[1],
			$queue->timeOfNextJob()
		);
	}
}