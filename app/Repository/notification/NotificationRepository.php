<?php

namespace DTApi\Repository\notification;

use DTApi\Repository\notification\NotificationInterface;

use Monolog\Handler\StreamHandler;

use Monolog\Handler\FirePHPHandler;

use Illuminate\Support\Facades\Log;

use DTApi\Repository\job\JobInterface;

use Illuminate\Http\Request;

use DTApi\Traits\CommonTrait;

use DTApi\Helpers\TeHelper;

use DTApi\Helpers\SendSMSHelper;

use DTApi\Models\Language;

use DTApi\Models\Job;

use DTApi\Models\User;

use Monolog\Logger;

use DTApi\Models\UsersBlacklist;

use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;

use DTApi\Mailers\MailerInterface;

use Carbon\Carbon;

use DTApi\Models\UserMeta;

use Config;

class NotificationRepository implements NotificationInterface
{
    protected $jobRepository;

    /**
     * Job Repository constructor.
     * @param JobRepository $jobRepository
     */
    public function __construct(JobInterface $jobRepository)
    {
        $this->jobRepository = $jobRepository;
    }

    use CommonTrait;
    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id) {
        $users = User::all();
        $translator_array = []; // suitable translators (no need to delay push)
        $delpay_translator_array = []; // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if (
                $oneUser->user_type == '2' &&
                $oneUser->status == '1' &&
                $oneUser->id != $exclude_user_id
            ) {
                // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) {
                    continue;
                }
                $not_get_emergency = TeHelper::getUsermeta(
                    $oneUser->id,
                    'not_get_emergency'
                );
                if (
                    $data['immediate'] == 'yes' &&
                    $not_get_emergency == 'yes'
                ) {
                    continue;
                }
                $jobs = $this->jobRepository->getPotentialJobIdsWithUserId(
                    $oneUser->id
                ); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) {
                        // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator(
                            $userId,
                            $oneJob->id
                        );
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob(
                                $userId,
                                $oneJob
                            );
                            if ($job_checker != 'userCanNotAcceptJob') {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId(
            $data['from_language_id']
        );
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents =
                'Ny bokning för ' .
                $data['language'] .
                'tolk ' .
                $data['duration'] .
                'min ' .
                $data['due'];
        } else {
            $msg_contents =
                'Ny akutbokning för ' .
                $data['language'] .
                'tolk ' .
                $data['duration'] .
                'min';
        }
        $msg_text = [
            'en' => $msg_contents,
        ];

        $logger = new Logger('push_logger');

        $logger->pushHandler(
            new StreamHandler(
                storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG
            )
        );
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [
            $translator_array,
            $delpay_translator_array,
            $msg_text,
            $data,
        ]);
        $this->sendPushNotificationToSpecificUsers(
            $translator_array,
            $job->id,
            $data,
            $msg_text,
            false
        ); // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers(
            $delpay_translator_array,
            $job->id,
            $data,
            $msg_text,
            true
        ); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', [
            'date' => $date,
            'time' => $time,
            'duration' => $duration,
            'jobId' => $jobId,
        ]);

        $physicalJobMessageTemplate = trans('sms.physical_job', [
            'date' => $date,
            'time' => $time,
            'town' => $city,
            'duration' => $duration,
            'jobId' => $jobId,
        ]);

        // analyse weather it's phone or physical; if both = default to phone
        if (
            $job->customer_physical_type == 'yes' &&
            $job->customer_phone_type == 'no'
        ) {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } elseif (
            $job->customer_physical_type == 'no' &&
            $job->customer_phone_type == 'yes'
        ) {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } elseif (
            $job->customer_physical_type == 'yes' &&
            $job->customer_phone_type == 'yes'
        ) {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(
                Config::get('constants.sms_number'),
                $translator->mobile,
                $message
            );
            Log::info(
                'Send SMS to ' .
                    $translator->email .
                    ' (' .
                    $translator->mobile .
                    '), status: ' .
                    print_r($status, true)
            );
        }

        return count($translators);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;

        if ($job_type == 'paid') {
            $translator_type = 'professional';
        } elseif ($job_type == 'rws') {
            $translator_type = 'rwstranslator';
        } elseif ($job_type == 'unpaid') {
            $translator_type = 'volunteer';
        }

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] =
                    'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif (
                $job->certified == 'health' ||
                $job->certified == 'n_health'
            ) {
                $translator_level[] =
                    'Certified with specialisation in health care';
            } elseif (
                $job->certified == 'normal' ||
                $job->certified == 'both'
            ) {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] =
                    'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();

        $translatorsId = collect($blacklist)
            ->pluck('translator_id')
            ->all();

        $users = User::getPotentialUsers(
            $translator_type,
            $joblanguage,
            $gender,
            $translator_level,
            $translatorsId
        );

        return $users;
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = []; // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(' ', $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = [];
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*'); // send Push all sutiable translators
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        return job::findOrFail($id);
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    public function sendNotificationChangePending($user,$job,$language,$due,$duration) {
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes') {
            $msg_text = [
                'en' =>
                    'Du har nu fått platstolkningen för ' .
                    $language .
                    ' kl ' .
                    $duration .
                    ' den ' .
                    $due .
                    '. Vänligen säkerställ att du är förberedd för den tiden. Tack!',
            ];
        } else {
            $msg_text = [
                'en' =>
                    'Du har nu fått telefontolkningen för ' .
                    $language .
                    ' kl ' .
                    $duration .
                    ' den ' .
                    $due .
                    '. Vänligen säkerställ att du är förberedd för den tiden. Tack!',
            ];
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] =
                    $sessionTime[0] * 60 +
                    $sessionTime[1] +
                    $sessionTime[2] / 60;

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')
            ->orderBy('language')
            ->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')
            ->where('user_type', '1')
            ->lists('email');
        $all_translators = DB::table('users')
            ->where('user_type', '2')
            ->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join(
                    'languages',
                    'jobs.from_language_id',
                    '=',
                    'languages.id'
                )
                ->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs
                    ->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs
                    ->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (
                isset($requestdata['customer_email']) &&
                $requestdata['customer_email'] != ''
            ) {
                $user = DB::table('users')
                    ->where('email', $requestdata['customer_email'])
                    ->first();
                if ($user) {
                    $allJobs
                        ->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (
                isset($requestdata['translator_email']) &&
                $requestdata['translator_email'] != ''
            ) {
                $user = DB::table('users')
                    ->where('email', $requestdata['translator_email'])
                    ->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')
                        ->where('user_id', $user->id)
                        ->lists('job_id');
                    $allJobs
                        ->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (
                isset($requestdata['filter_timetype']) &&
                $requestdata['filter_timetype'] == 'created'
            ) {
                if (isset($requestdata['from']) && $requestdata['from'] != '') {
                    $allJobs
                        ->where('jobs.created_at', '>=', $requestdata['from'])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != '') {
                    $to = $requestdata['to'] . ' 23:59:00';
                    $allJobs
                        ->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (
                isset($requestdata['filter_timetype']) &&
                $requestdata['filter_timetype'] == 'due'
            ) {
                if (isset($requestdata['from']) && $requestdata['from'] != '') {
                    $allJobs
                        ->where('jobs.due', '>=', $requestdata['from'])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != '') {
                    $to = $requestdata['to'] . ' 23:59:00';
                    $allJobs
                        ->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (
                isset($requestdata['job_type']) &&
                $requestdata['job_type'] != ''
            ) {
                $allJobs
                    ->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs
                ->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata,
        ];
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user,$job,$language, $due,$duration) {
        $this->logger->pushHandler(
            new StreamHandler(
                storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG
            )
        );
        $this->logger->pushHandler(new FirePHPHandler());
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes') {
            $msg_text = [
                'en' =>
                    'Detta är en påminnelse om att du har en ' .
                    $language .
                    'tolkning (på plats i ' .
                    $job->town .
                    ') kl ' .
                    $due_explode[1] .
                    ' på ' .
                    $due_explode[0] .
                    ' som vara i ' .
                    $duration .
                    ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!',
            ];
        } else {
            $msg_text = [
                'en' =>
                    'Detta är en påminnelse om att du har en ' .
                    $language .
                    'tolkning (telefon) kl ' .
                    $due_explode[1] .
                    ' på ' .
                    $due_explode[0] .
                    ' som vara i ' .
                    $duration .
                    ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!',
            ];
        }

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->isNeedToDelayPush($user->id)
            );
            $this->logger->addInfo('sendSessionStartRemindNotification ', [
                'job' => $job->id,
            ]);
        }
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job,$current_translator,$new_translator) {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' .
            $job->id .
            ')';
        $data = [
            'user' => $user,
            'job' => $job,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            'emails.job-changed-translator-customer',
            $data
        );
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send(
                $email,
                $name,
                $subject,
                'emails.job-changed-translator-old-translator',
                $data
            );
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send(
            $email,
            $name,
            $subject,
            'emails.job-changed-translator-new-translator',
            $data
        );
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            'Meddelande om ändring av tolkbokning för uppdrag # ' .
            $job->id .
            '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_time' => $old_time,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            'emails.job-changed-date',
            $data
        );

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user' => $translator,
            'job' => $job,
            'old_time' => $old_time,
        ];
        $this->mailer->send(
            $translator->email,
            $translator->name,
            $subject,
            'emails.job-changed-date',
            $data
        );
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            'Meddelande om ändring av tolkbokning för uppdrag # ' .
            $job->id .
            '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $old_lang,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            'emails.job-changed-lang',
            $data
        );
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send(
            $translator->email,
            $translator->name,
            $subject,
            'emails.job-changed-date',
            $data
        );
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            'en' =>
                'Tyvärr har ingen tolk accepterat er bokning: (' .
                $language .
                ', ' .
                $job->duration .
                'min, ' .
                $job->due .
                '). Vänligen pröva boka om tiden.',
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->isNeedToDelayPush($user->id)
            );
        }
    }
}
