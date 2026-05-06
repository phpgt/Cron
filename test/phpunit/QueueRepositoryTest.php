<?php
namespace GT\Cron\Test;

use DateTime;
use GT\Cron\QueueRepository;
use PHPUnit\Framework\TestCase;

class QueueRepositoryTest extends TestCase {
	public function testCreateAtTime() {
		$expectedNow = new DateTime("+17 minutes");
		$repository = new QueueRepository();
		$queue = $repository->createAtTime($expectedNow);
		self::assertEquals(
			$expectedNow,
			$queue->now()
		);
	}
}