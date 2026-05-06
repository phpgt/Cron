<?php
namespace GT\Cron;

class RunnerFactory {
	public function createForProject(
		string $projectDirectory,
		string $fileName = "crontab"
	):Runner {
		$crontabPath = implode(DIRECTORY_SEPARATOR, [
			$projectDirectory,
			$fileName,
		]);

		if(!is_file($crontabPath)) {
			throw new CrontabNotFoundException("crontab file not found at $crontabPath");
		}

		$jobFactory = new JobRepository(projectDirectory: $projectDirectory);
		return new Runner(
			$jobFactory,
			new QueueRepository(),
			file_get_contents($crontabPath)
		);
	}
}
