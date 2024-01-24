<?php 

namespace DTApi\Repository\notification;

use DTApi\Models\Job;

interface NotificationInterface{

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id);

    public function sendSMSNotificationToTranslator($job);

    public function getPotentialTranslators(Job $job);

    public function sendNotificationByAdminCancelJob($job_id);

    public function show($id);

    public function sendNotificationChangePending($user,$job,$language,$due,$duration);

    public function alerts();

    public function sendSessionStartRemindNotification($user,$job,$language, $due,$duration);

    public function sendChangedTranslatorNotification($job,$current_translator,$new_translator);

    public function sendChangedDateNotification($job, $old_time);

    public function sendChangedLangNotification($job, $old_lang);

    public function sendExpiredNotification($job, $user);
}