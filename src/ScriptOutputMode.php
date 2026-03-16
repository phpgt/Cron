<?php
namespace Gt\Cron;

enum ScriptOutputMode:string {
	case DISCARD = "discard";
	case INHERIT = "inherit";
	case CAPTURE = "capture";
}
