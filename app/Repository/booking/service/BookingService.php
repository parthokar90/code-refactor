<?php 

namespace DTApi\Repository\booking\service;

use DTApi\Repository\booking\BookingInterface;

use Illuminate\Support\Facades\DB;

use DTApi\Models\Job;

use Config;

class BookingService implements BookingInterface
{
    public function getAllJobs(Request $request, $limit = null)
    {
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;

        $allJobs = $this->initializeQuery($cuser, $request);

        $this->applyFilters($allJobs, $request);

        return $this->retrieveJobs($allJobs, $limit);
    }

    protected function initializeQuery($cuser, $request)
    {
        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == Config::get("constants.superadmin_role_id")) {
            if (
                isset($requestdata["feedback"]) &&
                $requestdata["feedback"] != "false"
            ) {
                $allJobs->where("ignore_feedback", "0");
                $allJobs->whereHas("feedback", function ($q) {
                    $q->where("rating", "<=", "3");
                });
                if (
                    isset($requestdata["count"]) &&
                    $requestdata["count"] != "false"
                ) {
                    return ["count" => $allJobs->count()];
                }
            }

            if (isset($requestdata["id"]) && $requestdata["id"] != "") {
                if (is_array($requestdata["id"])) {
                    $allJobs->whereIn("id", $requestdata["id"]);
                } else {
                    $allJobs->where("id", $requestdata["id"]);
                }
                $requestdata = array_only($requestdata, ["id"]);
            }

            if (isset($requestdata["lang"]) && $requestdata["lang"] != "") {
                $allJobs->whereIn("from_language_id", $requestdata["lang"]);
            }

            if (isset($requestdata["status"]) && $requestdata["status"] != "") {
                $allJobs->whereIn("status", $requestdata["status"]);
            }
            if (
                isset($requestdata["expired_at"]) &&
                $requestdata["expired_at"] != ""
            ) {
                $allJobs->where("expired_at", ">=", $requestdata["expired_at"]);
            }

            if (
                isset($requestdata["will_expire_at"]) &&
                $requestdata["will_expire_at"] != ""
            ) {
                $allJobs->where(
                    "will_expire_at",
                    ">=",
                    $requestdata["will_expire_at"]
                );
            }
            if (
                isset($requestdata["customer_email"]) &&
                count($requestdata["customer_email"]) &&
                $requestdata["customer_email"] != ""
            ) {
                $users = DB::table("users")
                    ->whereIn("email", $requestdata["customer_email"])
                    ->get();
                if ($users) {
                    $allJobs->whereIn(
                        "user_id",
                        collect($users)
                            ->pluck("id")
                            ->all()
                    );
                }
            }
            if (
                isset($requestdata["translator_email"]) &&
                count($requestdata["translator_email"])
            ) {
                $users = DB::table("users")
                    ->whereIn("email", $requestdata["translator_email"])
                    ->get();
                if ($users) {
                    $allJobIDs = DB::table("translator_job_rel")
                        ->whereNull("cancel_at")
                        ->whereIn(
                            "user_id",
                            collect($users)
                                ->pluck("id")
                                ->all()
                        )
                        ->lists("job_id");
                    $allJobs->whereIn("id", $allJobIDs);
                }
            }
            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "created"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where("created_at", ">=", $requestdata["from"]);
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("created_at", "<=", $to);
                }
                $allJobs->orderBy("created_at", "desc");
            }
            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "due"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where("due", ">=", $requestdata["from"]);
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("due", "<=", $to);
                }
                $allJobs->orderBy("due", "desc");
            }

            if (
                isset($requestdata["job_type"]) &&
                $requestdata["job_type"] != ""
            ) {
                $allJobs->whereIn("job_type", $requestdata["job_type"]);
            }

            if (isset($requestdata["physical"])) {
                $allJobs->where(
                    "customer_physical_type",
                    $requestdata["physical"]
                );
                $allJobs->where("ignore_physical", 0);
            }

            if (isset($requestdata["phone"])) {
                $allJobs->where("customer_phone_type", $requestdata["phone"]);
                if (isset($requestdata["physical"])) {
                    $allJobs->where("ignore_physical_phone", 0);
                }
            }

            if (isset($requestdata["flagged"])) {
                $allJobs->where("flagged", $requestdata["flagged"]);
                $allJobs->where("ignore_flagged", 0);
            }

            if (
                isset($requestdata["distance"]) &&
                $requestdata["distance"] == "empty"
            ) {
                $allJobs->whereDoesntHave("distance");
            }

            if (
                isset($requestdata["salary"]) &&
                $requestdata["salary"] == "yes"
            ) {
                $allJobs->whereDoesntHave("user.salaries");
            }

            if (
                isset($requestdata["count"]) &&
                $requestdata["count"] == "true"
            ) {
                $allJobs = $allJobs->count();

                return ["count" => $allJobs];
            }

            if (
                isset($requestdata["consumer_type"]) &&
                $requestdata["consumer_type"] != ""
            ) {
                $allJobs->whereHas("user.userMeta", function ($q) use (
                    $requestdata
                ) {
                    $q->where("consumer_type", $requestdata["consumer_type"]);
                });
            }

            if (isset($requestdata["booking_type"])) {
                if ($requestdata["booking_type"] == "physical") {
                    $allJobs->where("customer_physical_type", "yes");
                }
                if ($requestdata["booking_type"] == "phone") {
                    $allJobs->where("customer_phone_type", "yes");
                }
            }

            $allJobs->orderBy("created_at", "desc");
            $allJobs->with(
                "user",
                "language",
                "feedback.user",
                "translatorJobRel.user",
                "distance"
            );
            if ($limit == "all") {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }
         else {
           
            $allJobs = Job::query();

            if (isset($requestdata["id"]) && $requestdata["id"] != "") {
                $allJobs->where("id", $requestdata["id"]);
                $requestdata = array_only($requestdata, ["id"]);
            }

            if ($consumer_type == "RWS") {
                $allJobs->where("job_type", "=", "rws");
            } else {
                $allJobs->where("job_type", "=", "unpaid");
            }
            if (
                isset($requestdata["feedback"]) &&
                $requestdata["feedback"] != "false"
            ) {
                $allJobs->where("ignore_feedback", "0");
                $allJobs->whereHas("feedback", function ($q) {
                    $q->where("rating", "<=", "3");
                });
                if (
                    isset($requestdata["count"]) &&
                    $requestdata["count"] != "false"
                ) {
                    return ["count" => $allJobs->count()];
                }
            }

            if (isset($requestdata["lang"]) && $requestdata["lang"] != "") {
                $allJobs->whereIn("from_language_id", $requestdata["lang"]);
            }
            if (isset($requestdata["status"]) && $requestdata["status"] != "") {
                $allJobs->whereIn("status", $requestdata["status"]);
            }
            if (
                isset($requestdata["job_type"]) &&
                $requestdata["job_type"] != ""
            ) {
                $allJobs->whereIn("job_type", $requestdata["job_type"]);
            }
            if (
                isset($requestdata["customer_email"]) &&
                $requestdata["customer_email"] != ""
            ) {
                $user = DB::table("users")
                    ->where("email", $requestdata["customer_email"])
                    ->first();
                if ($user) {
                    $allJobs->where("user_id", "=", $user->id);
                }
            }
            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "created"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where("created_at", ">=", $requestdata["from"]);
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("created_at", "<=", $to);
                }
                $allJobs->orderBy("created_at", "desc");
            }
            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "due"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where("due", ">=", $requestdata["from"]);
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("due", "<=", $to);
                }
                $allJobs->orderBy("due", "desc");
            }

            $allJobs->orderBy("created_at", "desc");
            $allJobs->with(
                "user",
                "language",
                "feedback.user",
                "translatorJobRel.user",
                "distance"
            );
            if ($limit == "all") {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }


        }

        return $allJobs;
    }

    protected function applyFilters($allJobs, $request)
    {
        $requestdata = $request->all();

        return $allJobs;
    }

    protected function retrieveJobs($allJobs, $limit)
    {
        $allJobs->orderBy("created_at", "desc");
        $allJobs->with("user", "language", "feedback.user", "translatorJobRel.user", "distance");

        return ($limit == "all") ? $allJobs->get() : $allJobs->paginate(15);
    }
}
