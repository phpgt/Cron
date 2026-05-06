<?php
namespace GT\Cron;

use InvalidArgumentException;

class CrontabParser {
	public function __construct(
		protected ?ExpressionFactory $expressionFactory = null,
	) {
		$this->expressionFactory ??= new ExpressionFactory();
	}

	public function parseIntoQueue(
		string $contents,
		Queue $queue,
		JobRepository $jobRepository
	):int {
		$numJobs = 0;

		foreach(explode("\n", $contents) as $line) {
			$line = trim($line);
			if($line === "" || $line[0] === "#") {
				continue;
			}

			[$crontab, $command] = $this->parseLine($line);

			try {
				$queue->add(
					$jobRepository->create(
						$this->expressionFactory->create($crontab),
						$command
					)
				);
			}
			catch(InvalidArgumentException $exception) {
				throw new ParseException("Invalid syntax: $line");
			}

			$numJobs++;
		}

		return $numJobs;
	}

	/** @return array{0:string,1:string} */
	public function parseLine(string $line):array {
		preg_match(
			"/^(?P<crontab>@\S+|\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(?P<command>.+)$/",
			$line,
			$matches
		);

		$crontab = $matches["crontab"] ?? null;
		$command = $matches["command"] ?? null;

		if(is_null($crontab) || is_null($command)) {
			throw new ParseException("Invalid syntax: $line");
		}

		return [
			trim($crontab),
			trim($command),
		];
	}
}
