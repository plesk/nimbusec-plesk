<?php

pm_Context::init('nimbusec-agent-integration');

$id = pm_Setting::get('agent-schedule-id');
if (!empty(id)) {
	$task = pm_Scheduler::getInstance()->getTaskById($id);

	pm_Scheduler::removeTask($task);
}

unlink(pm_Context::getVarDir() . '/agent*');

pm_Settings::clean();
