<?php
namespace GT\Cron\Test;

use DateInterval;
use DateTime;
use GT\Cron\CronExpression;
use GT\Cron\Job;
use GT\Cron\JobRepository;
use GT\Cron\ParseException;
use GT\Cron\Queue;
use GT\Cron\QueueRepository;
use GT\Cron\Runner;
use GT\Cron\Test\Helper\Override;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase {
	/**
	 * @dataProvider watchCallbacks
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRun_watchExecutesJobWhenWakingAtScheduledTime(bool $withCallback):void {
		$clock = new DateTime("2026-11-12 10:59:30 UTC");
		$queue = new class($clock) extends Queue {
			public function __construct(private readonly DateTime $clock) {
				$initialTime = clone $clock;
				parent::__construct($initialTime);
			}

			public function now(?DateTime $newNow = null):DateTime {
				// Substitute the controlled clock only when the runner asks to refresh it.
				// Reads still return the stored time, preserving the real queue behaviour.
				return parent::now($newNow === null ? null : clone $this->clock);
			}
		};
		$executions = [];
		$job = $this->getMockBuilder(Job::class)
			->setConstructorArgs([new CronExpression("0 * * * *"), "hourly"])
			->onlyMethods(["run"])->getMock();
		$job->method("run")->willReturnCallback(function() use (&$executions, $clock):void {
			$executions []= $clock->format("H:i:s");
		});
		$jobs = $this->createStub(JobRepository::class);
		$jobs->method("create")->willReturn($job);
		$queues = $this->createStub(QueueRepository::class);
		$queues->method("createAtTime")->willReturn($queue);
		$sut = new Runner($jobs, $queues, "0 * * * * hourly", clone $clock);
		if($withCallback) {
			$sut->setRunCallback(function():void {});
		}

		$sleeps = 0;
		Override::setCallback("sleep", function(int $seconds) use (&$sleeps, $clock, $sut, &$executions):void {
			$sleeps++;
			if($sleeps === 1) {
				self::assertSame([], $executions, "The hourly job must not run before 11:00.");
				self::assertSame(30, $seconds);
				$clock->modify("+30 seconds");
				return;
			}

			// Stop after the first wakeup, regardless of whether the job was executed.
			$sut->continue = false;
		});

		$sut->run(true);

		self::assertSame(["11:00:00"], $executions, "The due job must run on waking, with or without a display callback.");
	}

	public static function watchCallbacks():array {
		return ["with display callback" => [true], "without display callback" => [false]];
	}

	public function testParseException() {
		$cronContents = <<<CRON
* * * ABC * ThisShouldNotWork::example
CRON;

		self::expectException(ParseException::class);
		new Runner(
			$this->mockJobRepository(),
			$this->mockQueueRepository(),
			$cronContents
		);
	}

	public function testParseExceptionIdentifiesLine() {
		$cronContents = <<<CRON
0 22 * * 1-5 CronExample::TenOclockWeekday
* * * * * CronExample::everyMinute
15 00 * CronBadExample::notEnoughParts
30 12 1 * * CronExample::halfTwelveOnFirstDayOfMonth
CRON;

		self::expectException(ParseException::class);
		self::expectExceptionMessage("Invalid syntax: 15 00 * CronBadExample::notEnoughParts");
		new Runner(
			$this->mockJobRepository(),
			$this->mockQueueRepository(),
			$cronContents
		);
	}

	public function testRun() {
		$now = new DateTime("2020-01-01 12:10:00");
		$cronContents = <<<CRON
10 * * * * ExampleClass::runAtTenMinutesPast
15 * * * * ExampleClass::runAtFifteenMinutesPast
CRON;

		$expectedWait = [
			0,
			5 * 60
		];

		$runner = new Runner(
			$this->mockJobRepository(
				...$expectedWait
			),
			$this->mockQueueRepository(
				...$expectedWait
			),
			$cronContents,
			$now
		);
		self::assertEquals(
			1,
			$runner->run()
		);
	}

	public function testMultipleJobsRun() {
		$now = new DateTime("2020-01-01 12:25:00");
		$cronContents = <<<CRON
10 * * * * ExampleClass::runAtTenMinutesPast
25 * * * * ExampleClass::runAtTwentyFiveMinutesPast
*/5 * * * * ExampleClass::runEveryFiveMinutes
*/10 * * * * ExampleClass::runEveryTenMinutes
CRON;

		$expectedWait = [
			45 * 60,
			0,
			0,
			5 * 60
		];

		$runner = new Runner(
			$this->mockJobRepository(
				...$expectedWait
			),
			$this->mockQueueRepository(
				...$expectedWait
			),
			$cronContents,
			$now
		);
		self::assertEquals(
			2,
			$runner->run()
		);
	}

	public function testSleep() {
// Set now to midday with a cron job at 10 past.
		$now = new DateTime("2020-01-01 12:00:00");
		$cronContents = <<<CRON
10 * * * * ExampleClass::runAtTenMinutesPast
CRON;
		$expectedWait = [
			10 * 60,
		];

		$runner = new Runner(
			$this->mockJobRepository(
				...$expectedWait
			),
			$this->mockQueueRepository(
				...$expectedWait
			),
			$cronContents,
			$now
		);

		$secondsUntilNextJob = 0;

		Override::setCallback(
			"sleep",
		function($seconds) use(&$secondsUntilNextJob, $runner) {
			$secondsUntilNextJob = $seconds;
			$runner->continue = false;
		});

		$runner->run(true);
		self::assertEquals(600, $secondsUntilNextJob);
	}

	public function testSleepGetsEarliest() {
// Set now to midday with a cron job at 10 past.
		$now = new DateTime("2020-01-01 12:00:00");
		$cronContents = <<<CRON
10 * * * * ExampleClass::runAtTenMinutesPast
*/5 * * * * ExampleClass::runEveryFiveMinutes
CRON;

		$expectedWait = [
			10 * 60,
			5 * 60,
		];

		$runner = new Runner(
			$this->mockJobRepository(...$expectedWait),
			$this->mockQueueRepository(...$expectedWait),
			$cronContents,
			$now
		);

		$secondsUntilNextJob = 0;

		Override::setCallback(
			"sleep",
			function($seconds) use(&$secondsUntilNextJob, $runner) {
				$secondsUntilNextJob = $seconds;
				$runner->continue = false;
			});

		$runner->run(true);
		self::assertEquals(300, $secondsUntilNextJob);
	}

	public function testRunNumber() {
		$cronContents = <<<CRON
* * * * * ExampleClass::one
* * * * * ExampleClass::two
* * * * * ExampleClass::three
CRON;
		$expectedWait = [
			0,
			0,
			0,
		];

		$runner = new Runner(
			$this->mockJobRepository(...$expectedWait),
				$this->mockQueueRepository(...$expectedWait),
				$cronContents
		);

		self::assertEquals(
			3,
			$runner->run()
		);
	}

	public function testRunNumberWithBlankLines() {
		$cronContents = <<<CRON
* * * * * ExampleClass::one

* * * * * ExampleClass::two
* * * * * ExampleClass::three


CRON;
		$expectedWait = [
			0,
			0,
			0,
		];

		$runner = new Runner(
			$this->mockJobRepository(...$expectedWait),
			$this->mockQueueRepository(...$expectedWait),
			$cronContents
		);

		self::assertEquals(
			3,
			$runner->run()
		);
	}

	public function testRunCallbackIsExecuted() {
		$cronContents = <<<CRON
* * * * * ExampleClass::example
CRON;
		$expectedWait = [
			0,
		];

		$runner = new Runner(
			$this->mockJobRepository(...$expectedWait),
			$this->mockQueueRepository(...$expectedWait),
			$cronContents
		);

		$count = 0;

		$runner->setRunCallback(function() use(&$count) {
			$count++;
		});

		self::assertEquals(0, $count);
		$runner->run();
		self::assertEquals(1, $count);
		$runner->run();
		self::assertEquals(2, $count);
	}

	public function testRunCallbackIncludesCommands():void {
		$cronContents = <<<CRON
* * * * * ExampleClass::example
CRON;

		$runner = new Runner(
			$this->mockJobRepository(0),
			$this->mockQueueRepository(0),
			$cronContents
		);

		$jobsRan = 0;
		$runCommandList = [];
		$nextCommand = null;
		$runner->setRunCallback(
			function(
				int $jobsRanArg,
				?DateTime $wait,
				bool $continue,
				array $runCommandListArg,
				?string $nextCommandArg
			) use(&$jobsRan, &$runCommandList, &$nextCommand) {
				$jobsRan = $jobsRanArg;
				$runCommandList = $runCommandListArg;
				$nextCommand = $nextCommandArg;
			}
		);

		$runner->run();

		self::assertEquals(1, $jobsRan);
		self::assertEquals(["ExampleClass::example"], $runCommandList);
		self::assertEquals("ExampleClass::example", $nextCommand);
	}

	public function testComments() {
		$cronContents = <<<CRON
* * * * * ExampleClass::example1
#* * * * * ExampleClass::example2
* * * * * ExampleClass::example3
#* * * * * ExampleClass::example4
# * * * * * ExampleClass::example5
CRON;

		$runner = new Runner(
			$this->mockJobRepository(0),
			$this->mockQueueRepository(0),
			$cronContents
		);
		self::assertEquals(2, $runner->getNumJobs());
	}

	public function testNicknameExpressions():void {
		$cronContents = <<<CRON
@daily ExampleClass::daily
CRON;

		$runner = new Runner(
			$this->mockJobRepository(0),
			$this->mockQueueRepository(0),
			$cronContents
		);

		self::assertEquals(1, $runner->getNumJobs());
	}

	public function testOnlyComments() {
		$cronContents = <<<CRON
# List your background tasks here in normal crontab syntax
# and they will be run with `gt run`
# More info: https://www.php.gt/cron
CRON;
		$runner = new Runner(
			$this->mockJobRepository(0),
			$this->mockQueueRepository(0),
			$cronContents
		);
		self::assertEquals(0, $runner->getNumJobs());
	}

	protected function mockJobRepository(int...$wait):JobRepository {
		$isDue = [];
		$runDate = [];
		foreach($wait as $w) {
			$isDue []= $w === 0;
			$d = new DateTime();
			$d->add(new DateInterval("PT{$w}S"));
			$runDate []= $d;
		}

		$job = self::createMock(Job::class);
		$job->method("isDue")
			->willReturnOnConsecutiveCalls(...$isDue);
		$job->method("getNextRunDate")
			->willReturnOnConsecutiveCalls(...$runDate);

		$repository = self::createMock(JobRepository::class);
		$repository->method("create")
			->willReturn($job);



		/** @var JobRepository $repository */
		return $repository;
	}

	protected function mockQueueRepository(int...$wait):QueueRepository {
		$numberDueJobs = 0;
		$secondsUntilNextJob = null;

		foreach($wait as $w) {
			if($w === 0) {
				$numberDueJobs++;
			}

			if(is_null($secondsUntilNextJob)
			|| $w < $secondsUntilNextJob) {
				$secondsUntilNextJob = $w;
			}
		}

		$queue = self::createMock(Queue::class);
		$queue->method("runDueJobs")
			->willReturn($numberDueJobs);
		$queue->method("runDueJobsAndGetCommands")
			->willReturn(
				array_fill(0, $numberDueJobs, "ExampleClass::example")
			);
		$queue->method("secondsUntilNextJob")
			->willReturn($secondsUntilNextJob);
		$queue->method("commandOfNextJob")
			->willReturn("ExampleClass::example");

		$repository = self::createMock(QueueRepository::class);
		$repository->method("createAtTime")
			->willReturn($queue);

		/** @var QueueRepository $repository */
		return $repository;
	}
}
