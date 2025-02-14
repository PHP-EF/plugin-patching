<?php
// **
// USED TO DEFINE CRON JOBS
// **

// Set Schedules From Configuration
$patchingConfig = $phpef->config->get('Plugins', 'Patching');
$patchingSchedule = $patchingConfig['Patching-Check-Schedule'] ?? '0 * * * *';  // Run every hour at minute 0
$statusUpdateSchedule = $patchingConfig['Status-Update-Schedule'] ?? '*/5 * * * *';  // Run every 5 minutes

// Scheduled execution of patching jobs
$scheduler->call(function() {
    $Patching = new Patching();
    $awxPluginConfig = $Patching->config->get('Plugins', 'awx');
    
    // Check if AWX configuration is available
    if (isset($awxPluginConfig) && !empty($awxPluginConfig['Ansible-URL'])) {
        try {
            $result = $Patching->executeCronJob();
	    //$Patching->logging->writeLog('Patching', "Cron job result: ", 'debug', $result);
            if ($result['success']) {
                $Patching->updateCronStatus('Patching', 'Server Patching', 'success', $result['message'] ?? '');
            } else {
                $Patching->updateCronStatus('Patching', 'Server Patching', 'error', $result['error'] ?? 'Unknown error');
            }
        } catch (Exception $e) {
            $Patching->updateCronStatus('Patching', 'Server Patching', 'error', $e->getMessage());
        }
    }
})->at($patchingSchedule);

// Scheduled update of patching job statuses
$scheduler->call(function() {
    $Patching = new Patching();
    $awxPluginConfig = $Patching->config->get('Plugins', 'awx');
    
    // Check if AWX configuration is available
    if (isset($awxPluginConfig) && !empty($awxPluginConfig['Ansible-URL'])) {
        try {
            $result = $Patching->updatePatchingJobStatuses();
            if ($result['success']) {
                $message = "Updated {$result['updated_count']} job(s)";
                $Patching->updateCronStatus('Patching', 'Status Updates', 'success', $message);
            } else {
                $Patching->updateCronStatus('Patching', 'Status Updates', 'error', $result['error'] ?? 'Unknown error');
            }
        } catch (Exception $e) {
            $Patching->updateCronStatus('Patching', 'Status Updates', 'error', $e->getMessage());
        }
    }
})->at($statusUpdateSchedule);
