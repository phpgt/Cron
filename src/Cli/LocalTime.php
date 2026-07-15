<?php
namespace GT\Cron\Cli;

use DateTime;
use DateTimeZone;

class LocalTime {
	public function applySystemTimezone():void {
		if($timezone = $this->detectSystemTimezone()) {
			date_default_timezone_set($timezone);
		}
	}

	public function format(DateTime $dateTime):string {
		$local = clone $dateTime;
		$local->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$message = $local->format("H:i:s");

		if($local->getOffset() !== 0) {
			$utc = clone $local;
			$utc->setTimezone(new DateTimeZone("UTC"));
			$message .= " (" . $utc->format("H:i:s") . " UTC)";
		}

		return $message;
	}

	protected function detectSystemTimezone():?string {
		return $this->detectTimezoneFromEnvironment()
			?? $this->detectTimezoneFromLocaltime()
			?? $this->detectTimezoneFromTimezoneFile();
	}

	protected function detectTimezoneFromEnvironment():?string {
		$environmentTimezone = getenv("TZ");
		if($environmentTimezone !== false
		&& $this->isValidTimezone($environmentTimezone)) {
			return $environmentTimezone;
		}

		return null;
	}

	protected function detectTimezoneFromLocaltime():?string {
		$localtimePath = "/etc/localtime";
		if(is_link($localtimePath)) {
			$link = readlink($localtimePath);
			if($link !== false
			&& preg_match("#/zoneinfo/(.+)$#", $link, $match)
			&& $this->isValidTimezone($match[1])) {
				return $match[1];
			}
		}

		return null;
	}

	protected function detectTimezoneFromTimezoneFile():?string {
		$timezonePath = "/etc/timezone";
		if(is_file($timezonePath)) {
			$timezone = file_get_contents($timezonePath);
			if($timezone === false) {
				return null;
			}

			$timezone = trim($timezone);
			if($this->isValidTimezone($timezone)) {
				return $timezone;
			}
		}

		return null;
	}

	protected function isValidTimezone(string $timezone):bool {
		return in_array(
			$timezone,
			DateTimeZone::listIdentifiers(),
			true
		);
	}
}
