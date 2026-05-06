<?php
namespace GT\Cron;

enum ScriptOutputMode:string {
	case DISCARD = "discard";
	case INHERIT = "inherit";
	case CAPTURE = "capture";
}
