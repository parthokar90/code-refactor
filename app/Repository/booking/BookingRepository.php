<?php

namespace DTApi\Repository\booking;

use DTApi\Repository\booking\service\BookingService;

use DTApi\Repository\notification\NotificationInterface;

use DTApi\Mailers\MailerInterface;

use Illuminate\Support\Facades\DB;

use Monolog\Handler\StreamHandler;

use Monolog\Handler\FirePHPHandler;

use Illuminate\Support\Facades\Auth;

use DTApi\Traits\CommonTrait;

use Carbon\Carbon;

use Monolog\Logger;

use DTApi\Models\Job;

use DTApi\Models\User;

use DTApi\Models\Language;

use DTApi\Helpers\TeHelper;

use Illuminate\Http\Request;

use DTApi\Models\Translator;

use Config;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository implements BookingInterface
{
    protected $model;

    protected $mailer;

    protected $logger;

    protected $notificationRepository;

    protected $bookingService;

    use CommonTrait;

    /**
     * @param Job $model
     */
    function __construct(
        Job $model,
        MailerInterface $mailer,
        NotificationInterface $notificationRepository,
        BookingService $bookingService,
    ) {
        $this->mailer = $mailer;

        $this->logger = new Logger("admin_logger");

        $this->logger->pushHandler(
            new StreamHandler(
                storage_path("logs/admin/laravel-" . date("Y-m-d") . ".log"),

                Logger::DEBUG
            )
        );

        $this->logger->pushHandler(new FirePHPHandler());

        $this->notificationRepository = $notificationRepository;
    }

    public function getAll(Request $request, $limit = null)
    {
        return $this->bookingService->getAllJobs($request, $limit);
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == Config::get("constants.customer_role_id")) {
            $cuser = $user;

            if (!isset($data["from_language_id"])) {
                $response["status"] = "fail";
                $response["message"] = "Du måste fylla in alla fält";
                $response["field_name"] = "from_language_id";
                return $response;
            }
            if ($data["immediate"] == "no") {
                if (isset($data["due_date"]) && $data["due_date"] == "") {
                    $response["status"] = "fail";
                    $response["message"] = "Du måste fylla in alla fält";
                    $response["field_name"] = "due_date";
                    return $response;
                }
                if (isset($data["due_time"]) && $data["due_time"] == "") {
                    $response["status"] = "fail";
                    $response["message"] = "Du måste fylla in alla fält";
                    $response["field_name"] = "due_time";
                    return $response;
                }
                if (
                    !isset($data["customer_phone_type"]) &&
                    !isset($data["customer_physical_type"])
                ) {
                    $response["status"] = "fail";
                    $response["message"] = "Du måste göra ett val här";
                    $response["field_name"] = "customer_phone_type";
                    return $response;
                }
                if (isset($data["duration"]) && $data["duration"] == "") {
                    $response["status"] = "fail";
                    $response["message"] = "Du måste fylla in alla fält";
                    $response["field_name"] = "duration";
                    return $response;
                }
            } else {
                if (isset($data["duration"]) && $data["duration"] == "") {
                    $response["status"] = "fail";
                    $response["message"] = "Du måste fylla in alla fält";
                    $response["field_name"] = "duration";
                    return $response;
                }
            }
            if (isset($data["customer_phone_type"])) {
                $data["customer_phone_type"] = "yes";
            } else {
                $data["customer_phone_type"] = "no";
            }

            if (isset($data["customer_physical_type"])) {
                $data["customer_physical_type"] = "yes";
                $response["customer_physical_type"] = "yes";
            } else {
                $data["customer_physical_type"] = "no";
                $response["customer_physical_type"] = "no";
            }

            if ($data["immediate"] == "yes") {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data["due"] = $due_carbon->format("Y-m-d H:i:s");
                $data["immediate"] = "yes";
                $data["customer_phone_type"] = "yes";
                $response["type"] = "immediate";
            } else {
                $due = $data["due_date"] . " " . $data["due_time"];
                $response["type"] = "regular";
                $due_carbon = Carbon::createFromFormat("m/d/Y H:i", $due);
                $data["due"] = $due_carbon->format("Y-m-d H:i:s");
                if ($due_carbon->isPast()) {
                    $response["status"] = "fail";
                    $response["message"] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array("male", $data["job_for"])) {
                $data["gender"] = "male";
            } elseif (in_array("female", $data["job_for"])) {
                $data["gender"] = "female";
            }
            if (in_array("normal", $data["job_for"])) {
                $data["certified"] = "normal";
            } elseif (in_array("certified", $data["job_for"])) {
                $data["certified"] = "yes";
            } elseif (in_array("certified_in_law", $data["job_for"])) {
                $data["certified"] = "law";
            } elseif (in_array("certified_in_helth", $data["job_for"])) {
                $data["certified"] = "health";
            }
            if (
                in_array("normal", $data["job_for"]) &&
                in_array("certified", $data["job_for"])
            ) {
                $data["certified"] = "both";
            } elseif (
                in_array("normal", $data["job_for"]) &&
                in_array("certified_in_law", $data["job_for"])
            ) {
                $data["certified"] = "n_law";
            } elseif (
                in_array("normal", $data["job_for"]) &&
                in_array("certified_in_helth", $data["job_for"])
            ) {
                $data["certified"] = "n_health";
            }
            if ($consumer_type == "rwsconsumer") {
                $data["job_type"] = "rws";
            } elseif ($consumer_type == "ngo") {
                $data["job_type"] = "unpaid";
            } elseif ($consumer_type == "paid") {
                $data["job_type"] = "paid";
            }
            $data["b_created_at"] = date("Y-m-d H:i:s");
            if (isset($due)) {
                $data["will_expire_at"] = TeHelper::willExpireAt(
                    $due,
                    $data["b_created_at"]
                );
            }
            $data["by_admin"] = isset($data["by_admin"])
                ? $data["by_admin"]
                : "no";

            $job = $cuser->jobs()->create($data);

            $response["status"] = "success";
            $response["id"] = $job->id;
            $data["job_for"] = [];
            if ($job->gender != null) {
                if ($job->gender == "male") {
                    $data["job_for"][] = "Man";
                } elseif ($job->gender == "female") {
                    $data["job_for"][] = "Kvinna";
                }
            }
            if ($job->certified != null) {
                if ($job->certified == "both") {
                    $data["job_for"][] = "normal";
                    $data["job_for"][] = "certified";
                } elseif ($job->certified == "yes") {
                    $data["job_for"][] = "certified";
                } else {
                    $data["job_for"][] = $job->certified;
                }
            }

            $data["customer_town"] = $cuser->userMeta->city;
            $data["customer_type"] = $cuser->userMeta->customer_type;
        } else {
            $response["status"] = "fail";
            $response["message"] = "Translator can not create booking";
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
     * @param $id
     * @param $data
     * @return mixed
     */
    public function update($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel
            ->where("cancel_at", null)
            ->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel
                ->where("completed_at", "!=", null)
                ->first();
        }

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator(
            $current_translator,
            $data,
            $job
        );
        if ($changeTranslator["translatorChanged"]) {
            $log_data[] = $changeTranslator["log_data"];
        }

        $changeDue = $this->changeDue($job->due, $data["due"]);
        if ($changeDue["dateChanged"]) {
            $old_time = $job->due;
            $job->due = $data["due"];
            $log_data[] = $changeDue["log_data"];
        }

        if ($job->from_language_id != $data["from_language_id"]) {
            $log_data[] = [
                "old_lang" => TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                ),
                "new_lang" => TeHelper::fetchLanguageFromJobId(
                    $data["from_language_id"]
                ),
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data["from_language_id"];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus(
            $job,
            $data,
            $changeTranslator["translatorChanged"]
        );
        if ($changeStatus["statusChanged"]) {
            $log_data[] = $changeStatus["log_data"];
        }

        $job->admin_comments = $data["admin_comments"];

        $this->logger->addInfo(
            "USER #" .
                $cuser->id .
                "(" .
                $cuser->name .
                ")" .
                ' has been updated booking <a class="openjob" href="/admin/jobs/' .
                $id .
                '">#' .
                $id .
                "</a> with data:  ",
            $log_data
        );

        $job->reference = $data["reference"];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ["Updated"];
        } else {
            $job->save();
            if ($changeDue["dateChanged"]) {
                $this->notificationRepository($job, $old_time);
            }
            if ($changeTranslator["translatorChanged"]) {
                $this->notificationRepository->sendChangedTranslatorNotification(
                    $job,
                    $current_translator,
                    $changeTranslator["new_translator"]
                );
            }
            if ($langChanged) {
                $this->notificationRepository->sendChangedLangNotification(
                    $job,
                    $old_lang
                );
            }
        }
    }

    public function customerNotCall($post_data)
    {
        $completeddate = date("Y-m-d H:i:s");
        $jobid = $post_data["job_id"];
        $job_detail = Job::with("translatorJobRel")->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ":" . $diff->i . ":" . $diff->s;
        $job = $job_detail;
        $job->end_at = date("Y-m-d H:i:s");
        $job->status = "not_carried_out_customer";

        $tr = $job
            ->translatorJobRel()
            ->where("completed_at", null)
            ->where("cancel_at", null)
            ->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response["status"] = "success";
        return $response;
    }

    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $distance = $data["distance"] ?? "";

        $time = $data["time"] ?? "";

        $jobid = $data["jobid"] ?? "";

        $session = $data["session_time"] ?? "";

        $flagged = $data["flagged"] === "true" ? "yes" : "no";

        $manually_handled = $data["manually_handled"] === "true" ? "yes" : "no";

        $by_admin = $data["by_admin"] === "true" ? "yes" : "no";

        $adminComment = $data["admincomment"] ?? "";

        if ($flagged === "yes" && $adminComment === "") {
            return response()->json(
                ["error" => "Please, add comment"],
                Config::get("statusCode.BAD_REQUEST")
            );
        }

        if ($time || $distance) {
            $affectedRows = Distance::where("job_id", "=", $jobid)->update([
                "distance" => $distance,
                "time" => $time,
            ]);
        }

        if (
            $adminComment ||
            $session ||
            $flagged ||
            $manually_handled ||
            $by_admin
        ) {
            $affectedRows1 = Job::where("id", "=", $jobid)->update([
                "admin_comments" => $adminComment,
                "flagged" => $flagged,
                "session_time" => $session,
                "manually_handled" => $manually_handled,
                "by_admin" => $by_admin,
            ]);
        }

        return ["message" => "Record updated!"];
    }

    public function reopen($request)
    {
        $jobid = $request["jobid"];
        $userid = $request["userid"];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = [];
        $data["created_at"] = date("Y-m-d H:i:s");
        $data["will_expire_at"] = TeHelper::willExpireAt(
            $job["due"],
            $data["created_at"]
        );
        $data["updated_at"] = date("Y-m-d H:i:s");
        $data["user_id"] = $userid;
        $data["job_id"] = $jobid;
        $data["cancel_at"] = Carbon::now();

        $datareopen = [];
        $datareopen["status"] = "pending";
        $datareopen["created_at"] = Carbon::now();
        $datareopen["will_expire_at"] = TeHelper::willExpireAt(
            $job["due"],
            $datareopen["created_at"]
        );

        if ($job["status"] != "timedout") {
            $affectedRows = Job::where("id", "=", $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job["status"] = "pending";
            $job["created_at"] = Carbon::now();
            $job["updated_at"] = Carbon::now();
            $job["will_expire_at"] = TeHelper::willExpireAt(
                $job["due"],
                date("Y-m-d H:i:s")
            );
            $job["updated_at"] = date("Y-m-d H:i:s");
            $job["cust_16_hour_email"] = 0;
            $job["cust_48_hour_email"] = 0;
            $job["admin_comments"] =
                "This booking is a reopening of booking #" . $jobid;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows["id"];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where("job_id", $jobid)
            ->where("cancel_at", null)
            ->update(["cancel_at" => $data["cancel_at"]]);
        $Translator = Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    public function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data["status"]) {
            switch ($job->status) {
                case "timedout":
                    $statusChanged = $this->changeTimedoutStatus(
                        $job,
                        $data,
                        $changedTranslator
                    );
                    break;
                case "completed":
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case "started":
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case "pending":
                    $statusChanged = $this->changePendingStatus(
                        $job,
                        $data,
                        $changedTranslator
                    );
                    break;
                case "withdrawafter24":
                    $statusChanged = $this->changeWithdrawafter24Status(
                        $job,
                        $data
                    );
                    break;
                case "assigned":
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    "old_status" => $old_status,
                    "new_status" => $data["status"],
                ];
                $statusChanged = true;
                return [
                    "statusChanged" => $statusChanged,
                    "log_data" => $log_data,
                ];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    public function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        //if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $old_status = $job->status;
        $job->status = $data["status"];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            "user" => $user,
            "job" => $job,
        ];
        if ($data["status"] == "pending") {
            $job->created_at = date("Y-m-d H:i:s");
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject =
                "Vi har nu återöppnat er bokning av " .
                TeHelper::fetchLanguageFromJobId($job->from_language_id) .
                "tolk för bokning #" .
                $job->id;
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.job-change-status-to-customer",
                $dataEmail
            );

            $this->sendNotificationTranslator($job, $job_data, "*"); // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject =
                "Bekräftelse - tolk har accepterat er bokning (bokning # " .
                $job->id .
                ")";
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.job-accepted",
                $dataEmail
            );
            return true;
        }

        //}
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    public function changeCompletedStatus($job, $data)
    {
        //if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data["status"];
        if ($data["status"] == "timedout") {
            if ($data["admin_comments"] == "") {
                return false;
            }
            $job->admin_comments = $data["admin_comments"];
        }
        $job->save();
        return true;
        //}
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    public function changeStartedStatus($job, $data)
    {
        //        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data["status"];
        if ($data["admin_comments"] == "") {
            return false;
        }
        $job->admin_comments = $data["admin_comments"];
        if ($data["status"] == "completed") {
            $user = $job->user()->first();
            if ($data["sesion_time"] == "") {
                return false;
            }
            $interval = $data["sesion_time"];
            $diff = explode(":", $interval);
            $job->end_at = date("Y-m-d H:i:s");
            $job->session_time = $interval;
            $session_time = $diff[0] . " tim " . $diff[1] . " min";
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                "user" => $user,
                "job" => $job,
                "session_time" => $session_time,
                "for_text" => "faktura",
            ];

            $subject =
                "Information om avslutad tolkning för bokningsnummer #" .
                $job->id;
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.session-ended",
                $dataEmail
            );

            $user = $job->translatorJobRel
                ->where("completed_at", null)
                ->where("cancel_at", null)
                ->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject =
                "Information om avslutad tolkning för bokningsnummer # " .
                $job->id;
            $dataEmail = [
                "user" => $user,
                "job" => $job,
                "session_time" => $session_time,
                "for_text" => "lön",
            ];
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.session-ended",
                $dataEmail
            );
        }
        $job->save();
        return true;
        //        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    public function changePendingStatus($job, $data, $changedTranslator)
    {
        //        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data["status"];
        if ($data["admin_comments"] == "" && $data["status"] == "timedout") {
            return false;
        }
        $job->admin_comments = $data["admin_comments"];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            "user" => $user,
            "job" => $job,
        ];

        if ($data["status"] == "assigned" && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject =
                "Bekräftelse - tolk har accepterat er bokning (bokning # " .
                $job->id .
                ")";
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.job-accepted",
                $dataEmail
            );

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send(
                $translator->email,
                $translator->name,
                $subject,
                "emails.job-changed-translator-new-translator",
                $dataEmail
            );

            $language = TeHelper::fetchLanguageFromJobId(
                $job->from_language_id
            );

            $this->sendSessionStartRemindNotification(
                $user,
                $job,
                $language,
                $job->due,
                $job->duration
            );
            $this->sendSessionStartRemindNotification(
                $translator,
                $job,
                $language,
                $job->due,
                $job->duration
            );
            return true;
        } else {
            $subject = "Avbokning av bokningsnr: #" . $job->id;
            $this->mailer->send(
                $email,
                $name,
                $subject,
                "emails.status-changed-from-pending-or-assigned-customer",
                $dataEmail
            );
            $job->save();
            return true;
        }

        //        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    public function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data["status"], ["timedout"])) {
            $job->status = $data["status"];
            if ($data["admin_comments"] == "") {
                return false;
            }
            $job->admin_comments = $data["admin_comments"];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    public function changeAssignedStatus($job, $data)
    {
        if (
            in_array($data["status"], [
                "withdrawbefore24",
                "withdrawafter24",
                "timedout",
            ])
        ) {
            $job->status = $data["status"];
            if (
                $data["admin_comments"] == "" &&
                $data["status"] == "timedout"
            ) {
                return false;
            }
            $job->admin_comments = $data["admin_comments"];
            if (
                in_array($data["status"], [
                    "withdrawbefore24",
                    "withdrawafter24",
                ])
            ) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    "user" => $user,
                    "job" => $job,
                ];

                $subject =
                    "Information om avslutad tolkning för bokningsnummer #" .
                    $job->id;
                $this->mailer->send(
                    $email,
                    $name,
                    $subject,
                    "emails.status-changed-from-pending-or-assigned-customer",
                    $dataEmail
                );

                $user = $job->translatorJobRel
                    ->where("completed_at", null)
                    ->where("cancel_at", null)
                    ->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject =
                    "Information om avslutad tolkning för bokningsnummer # " .
                    $job->id;
                $dataEmail = [
                    "user" => $user,
                    "job" => $job,
                ];
                $this->mailer->send(
                    $email,
                    $name,
                    $subject,
                    "emails.job-cancel-translator",
                    $dataEmail
                );
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    public function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (
            !is_null($current_translator) ||
            (isset($data["translator"]) && $data["translator"] != 0) ||
            $data["translator_email"] != ""
        ) {
            $log_data = [];
            if (
                !is_null($current_translator) &&
                ((isset($data["translator"]) &&
                    $current_translator->user_id != $data["translator"]) ||
                    $data["translator_email"] != "") &&
                (isset($data["translator"]) && $data["translator"] != 0)
            ) {
                if ($data["translator_email"] != "") {
                    $data["translator"] = User::where(
                        "email",
                        $data["translator_email"]
                    )->first()->id;
                }
                $new_translator = $current_translator->toArray();
                $new_translator["user_id"] = $data["translator"];
                unset($new_translator["id"]);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    "old_translator" => $current_translator->user->email,
                    "new_translator" => $new_translator->user->email,
                ];
                $translatorChanged = true;
            } elseif (
                is_null($current_translator) &&
                isset($data["translator"]) &&
                ($data["translator"] != 0 || $data["translator_email"] != "")
            ) {
                if ($data["translator_email"] != "") {
                    $data["translator"] = User::where(
                        "email",
                        $data["translator_email"]
                    )->first()->id;
                }
                $new_translator = Translator::create([
                    "user_id" => $data["translator"],
                    "job_id" => $job->id,
                ]);
                $log_data[] = [
                    "old_translator" => null,
                    "new_translator" => $new_translator->user->email,
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged) {
                return [
                    "translatorChanged" => $translatorChanged,
                    "new_translator" => $new_translator,
                    "log_data" => $log_data,
                ];
            }
        }

        return ["translatorChanged" => $translatorChanged];
    }

    //below method not used any of the resource
    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ["success", "Changes saved"];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ["success", "Changes saved"];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ["success", "Changes saved"];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where("ignore", 0)
            ->with("user")
            ->paginate(15);

        return ["throttles" => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where("active", "1")
            ->orderBy("language")
            ->get();
        $requestdata = Request::all();
        $all_customers = DB::table("users")
            ->where("user_type", "1")
            ->lists("email");
        $all_translators = DB::table("users")
            ->where("user_type", "2")
            ->lists("email");

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, "consumer_type");

        if ($cuser && ($cuser->is("superadmin") || $cuser->is("admin"))) {
            $allJobs = DB::table("jobs")
                ->join(
                    "languages",
                    "jobs.from_language_id",
                    "=",
                    "languages.id"
                )
                ->where("jobs.ignore_expired", 0);
            if (isset($requestdata["lang"]) && $requestdata["lang"] != "") {
                $allJobs
                    ->whereIn("jobs.from_language_id", $requestdata["lang"])
                    ->where("jobs.status", "pending")
                    ->where("jobs.ignore_expired", 0)
                    ->where("jobs.due", ">=", Carbon::now());
            }
            if (isset($requestdata["status"]) && $requestdata["status"] != "") {
                $allJobs
                    ->whereIn("jobs.status", $requestdata["status"])
                    ->where("jobs.status", "pending")
                    ->where("jobs.ignore_expired", 0)
                    ->where("jobs.due", ">=", Carbon::now());
            }
            if (
                isset($requestdata["customer_email"]) &&
                $requestdata["customer_email"] != ""
            ) {
                $user = DB::table("users")
                    ->where("email", $requestdata["customer_email"])
                    ->first();
                if ($user) {
                    $allJobs
                        ->where("jobs.user_id", "=", $user->id)
                        ->where("jobs.status", "pending")
                        ->where("jobs.ignore_expired", 0)
                        ->where("jobs.due", ">=", Carbon::now());
                }
            }
            if (
                isset($requestdata["translator_email"]) &&
                $requestdata["translator_email"] != ""
            ) {
                $user = DB::table("users")
                    ->where("email", $requestdata["translator_email"])
                    ->first();
                if ($user) {
                    $allJobIDs = DB::table("translator_job_rel")
                        ->where("user_id", $user->id)
                        ->lists("job_id");
                    $allJobs
                        ->whereIn("jobs.id", $allJobIDs)
                        ->where("jobs.status", "pending")
                        ->where("jobs.ignore_expired", 0)
                        ->where("jobs.due", ">=", Carbon::now());
                }
            }
            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "created"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs
                        ->where("jobs.created_at", ">=", $requestdata["from"])
                        ->where("jobs.status", "pending")
                        ->where("jobs.ignore_expired", 0)
                        ->where("jobs.due", ">=", Carbon::now());
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs
                        ->where("jobs.created_at", "<=", $to)
                        ->where("jobs.status", "pending")
                        ->where("jobs.ignore_expired", 0)
                        ->where("jobs.due", ">=", Carbon::now());
                }
                $allJobs->orderBy("jobs.created_at", "desc");
            }
            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "due"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs
                        ->where("jobs.due", ">=", $requestdata["from"])
                        ->where("jobs.status", "pending")
                        ->where("jobs.ignore_expired", 0)
                        ->where("jobs.due", ">=", Carbon::now());
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs
                        ->where("jobs.due", "<=", $to)
                        ->where("jobs.status", "pending")
                        ->where("jobs.ignore_expired", 0)
                        ->where("jobs.due", ">=", Carbon::now());
                }
                $allJobs->orderBy("jobs.due", "desc");
            }

            if (
                isset($requestdata["job_type"]) &&
                $requestdata["job_type"] != ""
            ) {
                $allJobs
                    ->whereIn("jobs.job_type", $requestdata["job_type"])
                    ->where("jobs.status", "pending")
                    ->where("jobs.ignore_expired", 0)
                    ->where("jobs.due", ">=", Carbon::now());
            }
            $allJobs
                ->select("jobs.*", "languages.language")
                ->where("jobs.status", "pending")
                ->where("ignore_expired", 0)
                ->where("jobs.due", ">=", Carbon::now());

            $allJobs->orderBy("jobs.created_at", "desc");
            $allJobs = $allJobs->paginate(15);
        }
        return [
            "allJobs" => $allJobs,
            "languages" => $languages,
            "all_customers" => $all_customers,
            "all_translators" => $all_translators,
            "requestdata" => $requestdata,
        ];
    }
}
