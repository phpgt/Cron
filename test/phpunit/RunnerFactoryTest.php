<?php
namespace GT\Cron\Test;

use GT\Cron\CrontabNotFoundException;
use GT\Cron\Runner;
use GT\Cron\RunnerFactory;
use PHPUnit\Framework\TestCase;

class RunnerFactoryTest extends TestCase {
	public function testCreateForProjectNoCrontabFile() {
		$dir = implode(DIRECTORY_SEPARATOR, [
			sys_get_temp_dir(),
			"phpgt",
			"cron",
			"example-project-" . uniqid()
		]);
		mkdir($dir, 0775, true);
		self::expectException(CrontabNotFoundException::class);
		(new RunnerFactory())->createForProject($dir);
	}

	public function testCreateForProject() {
		$dir = implode(DIRECTORY_SEPARATOR, [
			sys_get_temp_dir(),
			"phpgt",
			"cron",
			"example-project-" . uniqid()
		]);
		mkdir($dir, 0775, true);
		touch(implode(DIRECTORY_SEPARATOR, [
			$dir,
			"crontab",
		]));
		$runner = (new RunnerFactory())->createForProject(
			$dir
		);

		self::assertInstanceOf(Runner::class, $runner);
	}
}
