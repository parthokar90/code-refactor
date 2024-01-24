<?php

namespace DTApi\Repository\job;

use DTApi\Repository\job\JobInterface;

use DTApi\Repository\notification\NotificationInterface;

use DTApi\Mailers\MailerInterface;

use DTApi\Events\JobWasCanceled;

use DTApi\Events\JobWasCreated;

use DTApi\Events\SessionEnded;

use DTApi\Traits\CommonTrait;

use DTApi\Helpers\TeHelper;

use Illuminate\Http\Request;

use DTApi\Models\UserLanguages;

use DTApi\Models\UserMeta;

use DTApi\Models\User;

use DTApi\Models\Job;

use Carbon\Carbon;

class JobRepository implements JobInterface
{
    protected $notificationRepository;

    /**
     * Notification Repository constructor.
     * @param NotificationRepository $jobRepository
     */
    public function __construct(NotificationInterface $notificationRepository)
    {
        $this->notificationRepository = $notificationRepository;
    }

    use CommonTrait;
    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job
            ->user()
            ->get()
            ->first();
        if (isset($data['address'])) {
            $job->address =
                $data['address'] != ''
                    ? $data['address']
                    : $user->userMeta->address;
            $job->instructions =
                $data['instructions'] != ''
                    ? $data['instructions']
                    : $user->userMeta->instructions;
            $job->town =
                $data['town'] != '' ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job' => $job,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            'emails.job-created',
            $send_data
        );

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        if (isset($page)) {
            $pagenum = $page;
        } else {
            $pagenum = '1';
        }
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $noramlJobs = [];
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser
                ->jobs()
                ->with(
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback',
                    'distance'
                )
                ->whereIn('status', [
                    'completed',
                    'withdrawbefore24',
                    'withdrawafter24',
                    'timedout',
                ])
                ->orderBy('due', 'desc')
                ->paginate(15);
            $usertype = 'customer';
            return [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0,
            ];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric(
                $cuser->id,
                'historic',
                $pagenum
            );
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';

            $jobs = $jobs_ids;
            $noramlJobs = $jobs_ids;

            return [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs' => $noramlJobs,
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $pagenum,
            ];
        }
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if (
                $job->status == 'pending' &&
                Job::insertTranslatorJobRel($cuser->id, $job_id)
            ) {
                $job->status = 'assigned';
                $job->save();
                $user = $job
                    ->user()
                    ->get()
                    ->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject =
                        'Bekräftelse - tolk har accepterat er bokning (bokning # ' .
                        $job->id .
                        ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject =
                        'Bekräftelse - tolk har accepterat er bokning (bokning # ' .
                        $job->id .
                        ')';
                }
                $data = [
                    'user' => $user,
                    'job' => $job,
                ];
                $mailer->send(
                    $email,
                    $name,
                    $subject,
                    'emails.job-accepted',
                    $data
                );
            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = [];
            $response['list'] = json_encode(
                ['jobs' => $jobs, 'job' => $job],
                true
            );
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] =
                'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if (
                $job->status == 'pending' &&
                Job::insertTranslatorJobRel($cuser->id, $job_id)
            ) {
                $job->status = 'assigned';
                $job->save();
                $user = $job
                    ->user()
                    ->get()
                    ->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }
                $subject =
                    'Bekräftelse - tolk har accepterat er bokning (bokning # ' .
                    $job->id .
                    ')';
                $data = [
                    'user' => $user,
                    'job' => $job,
                ];
                $mailer->send(
                    $email,
                    $name,
                    $subject,
                    'emails.job-accepted',
                    $data
                );

                $data = [];
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                );
                $msg_text = [
                    'en' =>
                        'Din bokning för ' .
                        $language .
                        ' translators, ' .
                        $job->duration .
                        'min, ' .
                        $job->due .
                        ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.',
                ];
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers(
                        $users_array,
                        $job_id,
                        $data,
                        $msg_text,
                        $this->isNeedToDelayPush($user->id)
                    );
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] =
                    'Du har nu accepterat och fått bokningen för ' .
                    $language .
                    'tolk ' .
                    $job->duration .
                    'min ' .
                    $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                );
                $response['status'] = 'fail';
                $response['message'] =
                    'Denna ' .
                    $language .
                    'tolkning ' .
                    $job->duration .
                    'min ' .
                    $job->due .
                    ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] =
                'Du har redan en bokning den tiden ' .
                $job->due .
                '. Du har inte fått denna tolkning';
        }
        return $response;
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
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $noramlJobs = [];
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser
                ->jobs()
                ->with(
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback'
                )
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $noramlJobs[] = $jobitem;
                }
            }
            $noramlJobs = collect($noramlJobs)
                ->each(function ($item, $key) use ($user_id) {
                    $item['usercheck'] = Job::checkParticularJob(
                        $user_id,
                        $item
                    );
                })
                ->sortBy('due')
                ->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => $noramlJobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
        ];
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
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
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

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
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } elseif ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = [];
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                );
                $msg_text = [
                    'en' =>
                        'Kunden har avbokat bokningen för ' .
                        $language .
                        'tolk, ' .
                        $job->duration .
                        'min, ' .
                        $job->due .
                        '. Var god och kolla dina tidigare bokningar för detaljer.',
                ];
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->sendPushNotificationToSpecificUsers(
                        $users_array,
                        $job_id,
                        $data,
                        $msg_text,
                        $this->isNeedToDelayPush($translator->id)
                    ); // send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job
                    ->user()
                    ->get()
                    ->first();
                if ($customer) {
                    $data = [];
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId(
                        $job->from_language_id
                    );
                    $msg_text = [
                        'en' =>
                            'Er ' .
                            $language .
                            'tolk, ' .
                            $job->duration .
                            'min ' .
                            $job->due .
                            ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.',
                    ];
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = [$customer];
                        $this->sendPushNotificationToSpecificUsers(
                            $users_array,
                            $job_id,
                            $data,
                            $msg_text,
                            $this->isNeedToDelayPush($customer->id)
                        ); // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt(
                    $job->due,
                    date('Y-m-d H:i:s')
                );
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                // send Push all sutiable translators
                $this->notificationRepository->sendNotificationTranslator(
                    $job,
                    $data,
                    $translator->id
                );
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] =
                    'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /**
     * @param array $post_data
     */

    public function jobEnd($post_data = [])
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data['job_id'];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job
            ->user()
            ->get()
            ->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time =
            $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura',
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel
            ->where('completed_at', null)
            ->where('cancel_at', null)
            ->first();

        Event::fire(
            new SessionEnded(
                $job,
                $post_data['userid'] == $job->user_id
                    ? $tr->user_id
                    : $job->user_id
            )
        );

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject =
            'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön',
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional') {
            $job_type = 'paid';
        } /*show all jobs for professionals.*/ elseif (
            $translator_type == 'rwstranslator'
        ) {
            $job_type = 'rws';
        } /* for rwstranslator only show rws jobs. */ elseif (
            $translator_type == 'volunteer'
        ) {
            $job_type = 'unpaid';
        } /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)
            ->pluck('lang_id')
            ->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs(
            $user_id,
            $job_type,
            'pending',
            $userlanguage,
            $gender,
            $translator_level
        );
        foreach (
            $job_ids
            as $k =>
                $v // checking translator town
        ) {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (
                ($job->customer_phone_type == 'no' ||
                    $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' &&
                $checktown == false
            ) {
                unset($job_ids[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data['job_id'];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job
            ->user()
            ->get()
            ->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject =
            'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time =
            $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura',
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job
            ->translatorJobRel()
            ->where('completed_at', null)
            ->where('cancel_at', null)
            ->first();

        Event::fire(
            new SessionEnded(
                $job,
                $post_data['user_id'] == $job->user_id
                    ? $tr->user_id
                    : $job->user_id
            )
        );

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject =
            'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön',
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional') {
            $job_type = 'paid';
        } /*show all jobs for professionals.*/ elseif (
            $translator_type == 'rwstranslator'
        ) {
            $job_type = 'rws';
        } /* for rwstranslator only show rws jobs. */ elseif (
            $translator_type == 'volunteer'
        ) {
            $job_type = 'unpaid';
        } /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)
            ->pluck('lang_id')
            ->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs(
            $cuser->id,
            $job_type,
            'pending',
            $userlanguage,
            $gender,
            $translator_level
        );
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator(
                $cuser->id,
                $job->id
            );
            $job->check_particular_job = Job::checkParticularJob(
                $cuser->id,
                $job
            );
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob') {
                if ($job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($job_ids[$k]);
                }
            }

            if (
                ($job->customer_phone_type == 'no' ||
                    $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' &&
                $checktown == false
            ) {
                unset($job_ids[$k]);
            }
        }
        return $job_ids;
    }
}
