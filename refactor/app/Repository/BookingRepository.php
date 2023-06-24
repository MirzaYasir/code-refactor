<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Mail\Mailer;
use TeHelper;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, Mailer $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger("admin_logger");

        $logFilePath = storage_path(
            "logs/admin/laravel-" . date("Y-m-d") . ".log"
        );
        $this->logger->pushHandler(
            new StreamHandler($logFilePath, Logger::DEBUG)
        );
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = "";
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser && $cuser->is("customer")) {
            $jobs = $cuser
                ->jobs()
                ->with(
                    "user.userMeta",
                    "user.average",
                    "translatorJobRel.user.average",
                    "language",
                    "feedback"
                )
                ->whereIn("status", ["pending", "assigned", "started"])
                ->orderBy("due", "asc")
                ->get();
            $usertype = "customer";
        } elseif ($cuser && $cuser->is("translator")) {
            $jobs = Job::getTranslatorJobs($cuser->id, "new")
                ->pluck("jobs")
                ->all();
            $usertype = "translator";
        }

        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == "yes") {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }
            $normalJobs = collect($normalJobs)
                ->each(function ($item, $key) use ($user_id) {
                    $item["usercheck"] = Job::checkParticularJob(
                        $user_id,
                        $item
                    );
                })
                ->sortBy("due")
                ->all();
        }

        return [
            "emergencyJobs" => $emergencyJobs,
            "normalJobs" => $normalJobs,
            "cuser" => $cuser,
            "usertype" => $usertype,
        ];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get("page");
        $pagenum = isset($page) ? $page : "1";

        $cuser = User::find($user_id);
        $usertype = "";
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser && $cuser->is("customer")) {
            $jobs = $cuser
                ->jobs()
                ->with(
                    "user.userMeta",
                    "user.average",
                    "translatorJobRel.user.average",
                    "language",
                    "feedback",
                    "distance"
                )
                ->whereIn("status", [
                    "completed",
                    "withdrawbefore24",
                    "withdrawafter24",
                    "timedout",
                ])
                ->orderBy("due", "desc")
                ->paginate(15);
            $usertype = "customer";

            return [
                "emergencyJobs" => $emergencyJobs,
                "normalJobs" => [],
                "jobs" => $jobs,
                "cuser" => $cuser,
                "usertype" => $usertype,
                "numpages" => 0,
                "pagenum" => 0,
            ];
        } elseif ($cuser && $cuser->is("translator")) {
            $jobsIds = Job::getTranslatorJobsHistoric(
                $cuser->id,
                "historic",
                $pagenum
            );
            $totalJobs = $jobsIds->total();
            $numpages = ceil($totalJobs / 15);
            $usertype = "translator";

            $jobs = $jobsIds;
            $normalJobs = $jobsIds;

            return [
                "emergencyJobs" => $emergencyJobs,
                "normalJobs" => $normalJobs,
                "jobs" => $jobs,
                "cuser" => $cuser,
                "usertype" => $usertype,
                "numpages" => $numpages,
                "pagenum" => $pagenum,
            ];
        }
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

        if ($user->user_type != env("CUSTOMER_ROLE_ID")) {
            return [
                "status" => "fail",
                "message" => "Translator can not create booking",
            ];
        }

        $cuser = $user;

        if (!isset($data["from_language_id"])) {
            return [
                "status" => "fail",
                "message" => "Du måste fylla in alla fält",
                "field_name" => "from_language_id",
            ];
        }

        if ($data["immediate"] == "no") {
            if (isset($data["due_date"]) && $data["due_date"] == "") {
                return [
                    "status" => "fail",
                    "message" => "Du måste fylla in alla fält",
                    "field_name" => "due_date",
                ];
            }

            if (isset($data["due_time"]) && $data["due_time"] == "") {
                return [
                    "status" => "fail",
                    "message" => "Du måste fylla in alla fält",
                    "field_name" => "due_time",
                ];
            }

            if (
                !isset($data["customer_phone_type"]) &&
                !isset($data["customer_physical_type"])
            ) {
                return [
                    "status" => "fail",
                    "message" => "Du måste göra ett val här",
                    "field_name" => "customer_phone_type",
                ];
            }

            if (isset($data["duration"]) && $data["duration"] == "") {
                return [
                    "status" => "fail",
                    "message" => "Du måste fylla in alla fält",
                    "field_name" => "duration",
                ];
            }
        } else {
            if (isset($data["duration"]) && $data["duration"] == "") {
                return [
                    "status" => "fail",
                    "message" => "Du måste fylla in alla fält",
                    "field_name" => "duration",
                ];
            }
        }

        $data["customer_phone_type"] = isset($data["customer_phone_type"])
            ? "yes"
            : "no";
        $data["customer_physical_type"] = isset($data["customer_physical_type"])
            ? "yes"
            : "no";

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
                return [
                    "status" => "fail",
                    "message" => "Can't create booking in the past",
                ];
            }
        }

        $genderMap = [
            "male" => "Man",
            "female" => "Kvinna",
        ];

        $certifiedMap = [
            "both" => ["normal", "certified"],
            "yes" => ["certified"],
            "law" => ["certified_in_law"],
            "health" => ["certified_in_helth"],
        ];

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

        $data["by_admin"] = isset($data["by_admin"]) ? $data["by_admin"] : "no";

        $job = $cuser->jobs()->create($data);

        $response["status"] = "success";
        $response["id"] = $job->id;
        $data["job_for"] = [];

        if ($job->gender != null && isset($genderMap[$job->gender])) {
            $data["job_for"][] = $genderMap[$job->gender];
        }

        if ($job->certified != null && isset($certifiedMap[$job->certified])) {
            $data["job_for"] = array_merge(
                $data["job_for"],
                $certifiedMap[$job->certified]
            );
        }

        $data["customer_town"] = $cuser->userMeta->city;
        $data["customer_type"] = $cuser->userMeta->customer_type;

        return $response;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data["user_type"];
        $job = Job::findOrFail(@$data["user_email_job_id"]);
        $job->user_email = @$data["user_email"];
        $job->reference = $data["reference"] ?? "";
        $user = $job->user()->first();

        if (isset($data["address"])) {
            $job->address = $data["address"] ?: $user->userMeta->address;
            $job->instructions =
                $data["instructions"] ?: $user->userMeta->instructions;
            $job->town = $data["town"] ?: $user->userMeta->city;
        }

        $job->save();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = "Vi har mottagit er tolkbokning. Bokningsnr: #" . $job->id;
        $send_data = [
            "user" => $user,
            "job" => $job,
        ];
        $this->mailer->send(
            $email,
            $name,
            $subject,
            "emails.job-created",
            $send_data
        );

        $response = [
            "type" => $user_type,
            "job" => $job,
            "status" => "success",
        ];
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, "*"));

        return $response;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [
            "job_id" => $job->id,
            "from_language_id" => $job->from_language_id,
            "immediate" => $job->immediate,
            "duration" => $job->duration,
            "status" => $job->status,
            "gender" => $job->gender,
            "certified" => $job->certified,
            "due" => $job->due,
            "job_type" => $job->job_type,
            "customer_phone_type" => $job->customer_phone_type,
            "customer_physical_type" => $job->customer_physical_type,
            "customer_town" => $job->town,
            "customer_type" => $job->user->userMeta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $data["due_date"] = $due_Date[0];
        $data["due_time"] = $due_Date[1];

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
                $data["job_for"][] = "Godkänd tolk";
                $data["job_for"][] = "Auktoriserad";
            } elseif ($job->certified == "yes") {
                $data["job_for"][] = "Auktoriserad";
            } elseif ($job->certified == "n_health") {
                $data["job_for"][] = "Sjukvårdstolk";
            } elseif ($job->certified == "law" || $job->certified == "n_law") {
                $data["job_for"][] = "Rätttstolk";
            } else {
                $data["job_for"][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completeddate = date("Y-m-d H:i:s");
        $jobid = $post_data["job_id"];
        $job_detail = Job::with("translatorJobRel")->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format("%h:%i:%s");

        $job = $job_detail;
        $job->end_at = date("Y-m-d H:i:s");
        $job->status = "completed";
        $job->session_time = $interval;
        $job->save();

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject =
            "Information om avslutad tolkning för bokningsnummer # " . $job->id;
        $session_time = $diff->format("%h tim %i min");
        $data = [
            "user" => $user,
            "job" => $job,
            "session_time" => $session_time,
            "for_text" => "faktura",
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, "emails.session-ended", $data);

        $tr = $job->translatorJobRel
            ->where("completed_at", null)
            ->where("cancel_at", null)
            ->first();
        Event::fire(
            new SessionEnded(
                $job,
                $post_data["userid"] == $job->user_id
                    ? $tr->user_id
                    : $job->user_id
            )
        );

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject =
            "Information om avslutad tolkning för bokningsnummer # " . $job->id;
        $data = [
            "user" => $user,
            "job" => $job,
            "session_time" => $session_time,
            "for_text" => "lön",
        ];
        $mailer->send($email, $name, $subject, "emails.session-ended", $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data["userid"];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where("user_id", $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type =
            $translator_type == "professional"
                ? "paid"
                : ($translator_type == "rwstranslator"
                    ? "rws"
                    : "unpaid");

        $languages = UserLanguages::where("user_id", $user_id)
            ->pluck("lang_id")
            ->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs(
            $user_id,
            $job_type,
            "pending",
            $languages,
            $gender,
            $translator_level
        );

        foreach ($job_ids as $k => $v) {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);

            if (
                ($job->customer_phone_type == "no" ||
                    $job->customer_phone_type == "") &&
                $job->customer_physical_type == "yes" &&
                !$checktown
            ) {
                unset($job_ids[$k]);
            }
        }

        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator(
        $job,
        $data = [],
        $exclude_user_id
    ) {
        $translator_array = [];
        $delpay_translator_array = [];

        $users = User::where("user_type", "2")
            ->where("status", "1")
            ->where("id", "!=", $exclude_user_id)
            ->get();

        foreach ($users as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) {
                continue;
            }

            $not_get_emergency = TeHelper::getUsermeta(
                $oneUser->id,
                "not_get_emergency"
            );
            if ($data["immediate"] == "yes" && $not_get_emergency == "yes") {
                continue;
            }

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);

            foreach ($jobs as $oneJob) {
                if ($job->id == $oneJob->id) {
                    $userId = $oneUser->id;
                    $job_for_translator = Job::assignedToPaticularTranslator(
                        $userId,
                        $oneJob->id
                    );

                    if ($job_for_translator == "SpecificJob") {
                        $job_checker = Job::checkParticularJob(
                            $userId,
                            $oneJob
                        );

                        if ($job_checker != "userCanNotAcceptJob") {
                            $translator_array[] = $oneUser;
                            if ($this->isNeedToDelayPush($oneUser->id)) {
                                $delpay_translator_array[] = $oneUser;
                            }
                        }
                    }
                }
            }
        }

        $data["language"] = TeHelper::fetchLanguageFromJobId(
            $data["from_language_id"]
        );
        $data["notification_type"] = "suitable_job";

        $msg_contents =
            $data["immediate"] == "no"
                ? "Ny bokning för "
                : "Ny akutbokning för ";
        $msg_contents .=
            $data["language"] . "tolk " . $data["duration"] . "min";
        $msg_text = ["en" => $msg_contents];

        $logger = new Logger("push_logger");
        $logger->pushHandler(
            new StreamHandler(
                storage_path("logs/push/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo("Push send for job " . $job->id, [
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
        );
        $this->sendPushNotificationToSpecificUsers(
            $delpay_translator_array,
            $job->id,
            $data,
            $msg_text,
            true
        );
    }

    /**
     * Sends SMS to translators and returns the count of translators
     *
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where("user_id", $job->user_id)->first();
        $date = date("d.m.Y", strtotime($job->due));
        $time = date("H:i", strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;
        $phoneJobMessageTemplate = trans(
            "sms.phone_job",
            compact("date", "time", "duration", "jobId")
        );
        $physicalJobMessageTemplate = trans(
            "sms.physical_job",
            compact("date", "time", "city", "duration", "jobId")
        );

        if (
            $job->customer_physical_type == "yes" &&
            $job->customer_phone_type == "no"
        ) {
            $message = $physicalJobMessageTemplate;
        } else {
            $message = $phoneJobMessageTemplate;
        }

        Log::info($message);

        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(
                env("SMS_NUMBER"),
                $translator->mobile,
                $message
            );
            Log::info(
                "Send SMS to " .
                    $translator->email .
                    " (" .
                    $translator->mobile .
                    "), status: " .
                    print_r($status, true)
            );
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     *
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $not_get_nighttime = TeHelper::getUsermeta(
            $user_id,
            "not_get_nighttime"
        );
        return $not_get_nighttime == "yes";
    }

    /**
     * Function to check if need to send the push
     *
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta(
            $user_id,
            "not_get_notification"
        );
        return $not_get_notification != "yes";
    }

    /**
     * Function to send OneSignal Push Notifications with User-Tags
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param string $msg_text
     * @param bool $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers(
        array $users,
        int $job_id,
        array $data,
        string $msg_text,
        bool $is_need_delay
    ) {
        $logger = new Logger("push_logger");
        $logger->pushHandler(
            new StreamHandler(
                storage_path("logs/push/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo("Push send for job " . $job_id, [
            $users,
            $data,
            $msg_text,
            $is_need_delay,
        ]);

        $onesignalAppID =
            env("APP_ENV") == "prod"
                ? config("app.prodOnesignalAppID")
                : config("app.devOnesignalAppID");
        $onesignalRestAuthKey = sprintf(
            "Authorization: Basic %s",
            env("APP_ENV") == "prod"
                ? config("app.prodOnesignalApiKey")
                : config("app.devOnesignalApiKey")
        );

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data["job_id"] = $job_id;
        $ios_sound = "default";
        $android_sound = "default";

        if ($data["notification_type"] === "suitable_job") {
            if ($data["immediate"] === "no") {
                $android_sound = "normal_booking";
                $ios_sound = "normal_booking.mp3";
            } else {
                $android_sound = "emergency_booking";
                $ios_sound = "emergency_booking.mp3";
            }
        }

        $fields = [
            "app_id" => $onesignalAppID,
            "tags" => json_decode($user_tags),
            "data" => $data,
            "title" => ["en" => "DigitalTolk"],
            "contents" => $msg_text,
            "ios_badgeType" => "Increase",
            "ios_badgeCount" => 1,
            "android_sound" => $android_sound,
            "ios_sound" => $ios_sound,
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields["send_after"] = $next_business_time;
        }

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt(
            $ch,
            CURLOPT_URL,
            "https://onesignal.com/api/v1/notifications"
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            $onesignalRestAuthKey,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $logger->addInfo("Push send for job " . $job_id . " curl answer", [
            $response,
        ]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;
        $translator_type = "";

        switch ($job_type) {
            case "paid":
                $translator_type = "professional";
                break;
            case "rws":
                $translator_type = "rwstranslator";
                break;
            case "unpaid":
                $translator_type = "volunteer";
                break;
        }

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            if ($job->certified == "yes" || $job->certified == "both") {
                $translator_level[] = "Certified";
                $translator_level[] = "Certified with specialisation in law";
                $translator_level[] =
                    "Certified with specialisation in health care";
            } elseif ($job->certified == "law" || $job->certified == "n_law") {
                $translator_level[] = "Certified with specialisation in law";
            } elseif (
                $job->certified == "health" ||
                $job->certified == "n_health"
            ) {
                $translator_level[] =
                    "Certified with specialisation in health care";
            } elseif (
                $job->certified == "normal" ||
                $job->certified == "both"
            ) {
                $translator_level[] = "Layman";
                $translator_level[] = "Read Translation courses";
            } elseif ($job->certified == null) {
                $translator_level[] = "Certified";
                $translator_level[] = "Certified with specialisation in law";
                $translator_level[] =
                    "Certified with specialisation in health care";
                $translator_level[] = "Layman";
                $translator_level[] = "Read Translation courses";
            }
        }

        $blacklist = UsersBlacklist::where("user_id", $job->user_id)->get();
        $translatorsId = $blacklist->pluck("translator_id")->all();
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
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
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

        $logMessage =
            "USER #" .
            $cuser->id .
            "(" .
            $cuser->name .
            ")" .
            ' has updated booking <a class="openjob" href="/admin/jobs/' .
            $id .
            '">#' .
            $id .
            "</a> with data: ";
        $this->logger->addInfo($logMessage, $log_data);

        $job->reference = $data["reference"];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ["Updated"];
        } else {
            $job->save();
            if ($changeDue["dateChanged"]) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator["translatorChanged"]) {
                $this->sendChangedTranslatorNotification(
                    $job,
                    $current_translator,
                    $changeTranslator["new_translator"]
                );
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
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
        }

        if ($statusChanged) {
            $log_data = [
                "old_status" => $old_status,
                "new_status" => $data["status"],
            ];
            return ["statusChanged" => $statusChanged, "log_data" => $log_data];
        }

        return ["statusChanged" => $statusChanged];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data["status"];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
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

            $this->sendNotificationTranslator($job, $job_data, "*"); // send Push all suitable translators

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

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data["status"];

        if ($data["status"] == "timedout" && $data["admin_comments"] == "") {
            return false;
        }

        $job->admin_comments = $data["admin_comments"];
        $job->save();

        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
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

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
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
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data["status"];

        if ($data["admin_comments"] == "" && $data["status"] == "timedout") {
            return false;
        }

        $job->admin_comments = $data["admin_comments"];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
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

        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification(
        $user,
        $job,
        $language,
        $due,
        $duration
    ) {
        $this->logger->pushHandler(
            new StreamHandler(
                storage_path("logs/cron/laravel-" . date("Y-m-d") . ".log"),
                Logger::DEBUG
            )
        );
        $this->logger->pushHandler(new FirePHPHandler());

        $data = [
            "notification_type" => "session_start_remind",
        ];

        $due_explode = explode(" ", $due);
        $physical_type =
            $job->customer_physical_type == "yes"
                ? "på plats i " . $job->town
                : "telefon";
        $msg_text = "Detta är en påminnelse om att du har en $language tolkning ($physical_type) kl $due_explode[1] på $due_explode[0] som varar i $duration min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!";

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                ["en" => $msg_text],
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
            $this->logger->addInfo("sendSessionStartRemindNotification", [
                "job" => $job->id,
            ]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if ($data["status"] === "timedout") {
            $job->status = $data["status"];
            if ($data["admin_comments"] === "") {
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
    private function changeAssignedStatus($job, $data)
    {
        $validStatuses = ["withdrawbefore24", "withdrawafter24", "timedout"];

        if (in_array($data["status"], $validStatuses)) {
            $job->status = $data["status"];
            if (
                $data["admin_comments"] === "" &&
                $data["status"] === "timedout"
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
    private function changeTranslator($current_translator, $data, $job)
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

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;

        if ($old_due != $new_due) {
            $log_data = [
                "old_due" => $old_due,
                "new_due" => $new_due,
            ];
            $dateChanged = true;
            return ["dateChanged" => $dateChanged, "log_data" => $log_data];
        }

        return ["dateChanged" => $dateChanged];
    }

    /**
     * Send email notifications for changed translator.
     *
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotificationEmails(
        $job,
        $current_translator,
        $new_translator
    ) {
        [$email, $name] = $this->getUserEmailAndName($job);

        $subject =
            "Meddelande om tilldelning av tolkuppdrag för uppdrag # " .
            $job->id;

        $data = [
            "user" => $job->user()->first(),
            "job" => $job,
        ];

        $this->sendEmail(
            $email,
            $name,
            $subject,
            "emails.job-changed-translator-customer",
            $data
        );

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data["user"] = $user;

            $this->sendEmail(
                $email,
                $name,
                $subject,
                "emails.job-changed-translator-old-translator",
                $data
            );
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data["user"] = $user;

        $this->sendEmail(
            $email,
            $name,
            $subject,
            "emails.job-changed-translator-new-translator",
            $data
        );
    }

    /**
     * Send email notification for changed date.
     *
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        [$email, $name] = $this->getUserEmailAndName($job);

        $subject =
            "Meddelande om ändring av tolkbokning för uppdrag # " . $job->id;

        $data = [
            "user" => $job->user()->first(),
            "job" => $job,
            "old_time" => $old_time,
        ];

        $this->sendEmail(
            $email,
            $name,
            $subject,
            "emails.job-changed-date",
            $data
        );

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            "user" => $translator,
            "job" => $job,
            "old_time" => $old_time,
        ];

        $this->sendEmail(
            $translator->email,
            $translator->name,
            $subject,
            "emails.job-changed-date",
            $data
        );
    }

    /**
     * Send email notification for changed language.
     *
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        [$email, $name] = $this->getUserEmailAndName($job);

        $subject =
            "Meddelande om ändring av tolkbokning för uppdrag # " . $job->id;

        $data = [
            "user" => $job->user()->first(),
            "job" => $job,
            "old_lang" => $old_lang,
        ];

        $this->sendEmail(
            $email,
            $name,
            $subject,
            "emails.job-changed-lang",
            $data
        );

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->sendEmail(
            $translator->email,
            $translator->name,
            $subject,
            "emails.job-changed-date",
            $data
        );
    }

    /**
     * Send job expired push notification.
     *
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [
            "notification_type" => "job_expired",
        ];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" =>
                "Tyvärr har ingen tolk accepterat er bokning: (" .
                $language .
                ", " .
                $job->duration .
                "min, " .
                $job->due .
                "). Vänligen pröva boka om tiden.",
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

    /**
     * Send email notification for admin job cancellation.
     *
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [
            "job_id" => $job->id,
            "from_language_id" => $job->from_language_id,
            "immediate" => $job->immediate,
            "duration" => $job->duration,
            "status" => $job->status,
            "gender" => $job->gender,
            "certified" => $job->certified,
            "due" => $job->due,
            "job_type" => $job->job_type,
            "customer_phone_type" => $job->customer_phone_type,
            "customer_physical_type" => $job->customer_physical_type,
            "customer_town" => $user_meta->city,
            "customer_type" => $user_meta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data["due_date"] = $due_date;
        $data["due_time"] = $due_time;
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

        $this->sendNotificationTranslator($job, $data, "*");
    }

    /**
     * Send notification for change pending session.
     *
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendChangePendingSessionNotification(
        $user,
        $job,
        $language,
        $due,
        $duration
    ) {
        $data = [
            "notification_type" => "session_start_remind",
        ];

        if ($job->customer_physical_type == "yes") {
            $msg_text = [
                "en" =>
                    "Du har nu fått platstolkningen för " .
                    $language .
                    " kl " .
                    $duration .
                    " den " .
                    $due .
                    ". Vänligen säkerställ att du är förberedd för den tiden. Tack!",
            ];
        } else {
            $msg_text = [
                "en" =>
                    "Du har nu fått telefontolkningen för " .
                    $language .
                    " kl " .
                    $duration .
                    " den " .
                    $due .
                    ". Vänligen säkerställ att du är förberedd för den tiden. Tack!",
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

    /**
     * Send email notification.
     *
     * @param $email
     * @param $name
     * @param $subject
     * @param $view
     * @param $data
     */
    private function sendEmail($email, $name, $subject, $view, $data)
    {
        $this->mailer->send($email, $name, $subject, $view, $data);
    }

    /**
     * Get user's email and name.
     *
     * @param $job
     * @return array
     */
    private function getUserEmailAndName($job)
    {
        $user = $job->user()->first();
        $email = $user->email;
        $name = $user->name;

        return [$email, $name];
    }

    /**
     * Creates a user_tags string from the users array for creating OneSignal notifications.
     *
     * @param array $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;

        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }

            $userTags .=
                '{"key": "email", "relation": "=", "value": "' .
                strtolower($oneUser->email) .
                '"}';
        }

        $userTags .= "]";
        return $userTags;
    }

    /**
     * Accepts a job.
     *
     * @param array $data
     * @param User $user
     * @return array
     */
    public function acceptJob($data, $user)
    {
        $adminEmail = config("app.admin_email");
        $adminSenderEmail = config("app.admin_sender_email");

        $currentUser = $user;
        $jobId = $data["job_id"];
        $job = Job::findOrFail($jobId);

        if (
            !Job::isTranslatorAlreadyBooked($jobId, $currentUser->id, $job->due)
        ) {
            if (
                $job->status == "pending" &&
                Job::insertTranslatorJobRel($currentUser->id, $jobId)
            ) {
                $job->status = "assigned";
                $job->save();
                $user = $job->user()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject =
                        "Bekräftelse - tolk har accepterat er bokning (bokning # " .
                        $job->id .
                        ")";
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject =
                        "Bekräftelse - tolk har accepterat er bokning (bokning # " .
                        $job->id .
                        ")";
                }

                $data = [
                    "user" => $user,
                    "job" => $job,
                ];

                $mailer->send(
                    $email,
                    $name,
                    $subject,
                    "emails.job-accepted",
                    $data
                );
            }

            $jobs = $this->getPotentialJobs($currentUser);
            $response = [
                "list" => json_encode(["jobs" => $jobs, "job" => $job], true),
                "status" => "success",
            ];
        } else {
            $response = [
                "status" => "fail",
                "message" =>
                    "Du har redan en bokning den tiden! Bokningen är inte accepterad.",
            ];
        }

        return $response;
    }

    /**
     * Accepts a job with the provided job ID.
     *
     * @param int $jobId
     * @param User $currentUser
     * @return array
     */
    public function acceptJobWithId($jobId, $currentUser)
    {
        $adminEmail = config("app.admin_email");
        $adminSenderEmail = config("app.admin_sender_email");
        $job = Job::findOrFail($jobId);
        $response = [];

        if (
            !Job::isTranslatorAlreadyBooked($jobId, $currentUser->id, $job->due)
        ) {
            if (
                $job->status == "pending" &&
                Job::insertTranslatorJobRel($currentUser->id, $jobId)
            ) {
                $job->status = "assigned";
                $job->save();

                $user = $job->user()->first();
                $mailer = new AppMailer();
                $email = !empty($job->user_email)
                    ? $job->user_email
                    : $user->email;
                $name = $user->name;
                $subject =
                    "Bekräftelse - tolk har accepterat er bokning (bokning # " .
                    $job->id .
                    ")";
                $data = [
                    "user" => $user,
                    "job" => $job,
                ];
                $mailer->send(
                    $email,
                    $name,
                    $subject,
                    "emails.job-accepted",
                    $data
                );

                $data = [];
                $data["notification_type"] = "job_accepted";
                $language = TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                );
                $msg_text = [
                    "en" =>
                        "Din bokning för " .
                        $language .
                        " translators, " .
                        $job->duration .
                        "min, " .
                        $job->due .
                        " har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.",
                ];
                if ($this->isNeedToSendPush($user->id)) {
                    $usersArray = [$user];
                    $this->sendPushNotificationToSpecificUsers(
                        $usersArray,
                        $jobId,
                        $data,
                        $msg_text,
                        $this->isNeedToDelayPush($user->id)
                    );
                }

                $response["status"] = "success";
                $response["list"]["job"] = $job;
                $response["message"] =
                    "Du har nu accepterat och fått bokningen för " .
                    $language .
                    "tolk " .
                    $job->duration .
                    "min " .
                    $job->due;
            } else {
                $language = TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                );
                $response["status"] = "fail";
                $response["message"] =
                    "Denna " .
                    $language .
                    "tolkning " .
                    $job->duration .
                    "min " .
                    $job->due .
                    " har redan accepterats av annan tolk. Du har inte fått denna tolkning";
            }
        } else {
            $response["status"] = "fail";
            $response["message"] =
                "Du har redan en bokning den tiden " .
                $job->due .
                ". Du har inte fått denna tolkning";
        }

        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        /*@todo
        add 24hrs logging here.
        If the cancellation is before 24 hours before the booking time, the supplier will be informed. Flow ended.
        If the cancellation is within 24 hours:
            - The translator will be informed.
            - The customer will get an addition to their number of bookings, so we will charge for it if the cancellation is within 24 hours.
            - Treat it as if it was an executed session.
    */
        $response = [];

        $cuser = $user;
        $job_id = $data["job_id"];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is("customer")) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = "withdrawbefore24";
            } else {
                $job->status = "withdrawafter24";
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response["status"] = "success";
            $response["jobstatus"] = "success";

            if ($translator) {
                $data = [];
                $data["notification_type"] = "job_cancelled";
                $language = TeHelper::fetchLanguageFromJobId(
                    $job->from_language_id
                );
                $msg_text = [
                    "en" =>
                        "Kunden har avbokat bokningen för " .
                        $language .
                        "tolk, " .
                        $job->duration .
                        "min, " .
                        $job->due .
                        ". Var god och kolla dina tidigare bokningar för detaljer.",
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->sendPushNotificationToSpecificUsers(
                        $users_array,
                        $job_id,
                        $data,
                        $msg_text,
                        $this->isNeedToDelayPush($translator->id)
                    ); // send Session Cancel Push to Translator
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->first();

                if ($customer) {
                    $data = [];
                    $data["notification_type"] = "job_cancelled";
                    $language = TeHelper::fetchLanguageFromJobId(
                        $job->from_language_id
                    );
                    $msg_text = [
                        "en" =>
                            "Er " .
                            $language .
                            "tolk, " .
                            $job->duration .
                            "min " .
                            $job->due .
                            ", har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.",
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

                $job->status = "pending";
                $job->created_at = date("Y-m-d H:i:s");
                $job->will_expire_at = TeHelper::willExpireAt(
                    $job->due,
                    date("Y-m-d H:i:s")
                );
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id); // send Push all suitable translators

                $response["status"] = "success";
            } else {
                $response["status"] = "fail";
                $response["message"] =
                    "Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!";
            }
        }

        return $response;
    }

    /* Function to get the potential jobs for paid, rws, unpaid translators */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = "unpaid";
        $translator_type = $cuser_meta->translator_type;

        if ($translator_type == "professional") {
            $job_type = "paid"; // show all jobs for professionals.
        } elseif ($translator_type == "rwstranslator") {
            $job_type = "rws"; // for rwstranslator only show rws jobs.
        } elseif ($translator_type == "volunteer") {
            $job_type = "unpaid"; // for volunteers only show unpaid jobs.
        }

        $languages = UserLanguages::where("user_id", "=", $cuser->id)->get();
        $userlanguage = $languages->pluck("lang_id")->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        /* Call the town function for checking if the job is physical, then translators in one town can get the job */
        $job_ids = Job::getJobs(
            $cuser->id,
            $job_type,
            "pending",
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

            if (
                $job->specific_job == "SpecificJob" &&
                $job->check_particular_job == "userCanNotAcceptJob"
            ) {
                unset($job_ids[$k]);
            }

            if (
                ($job->customer_phone_type == "no" ||
                    $job->customer_phone_type == "") &&
                $job->customer_physical_type == "yes" &&
                !$checktown
            ) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date("Y-m-d H:i:s");
        $jobid = $post_data["job_id"];
        $job_detail = Job::with("translatorJobRel")->find($jobid);

        if ($job_detail->status != "started") {
            return ["status" => "success"];
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format("%h:%i:%s");
        $job = $job_detail;
        $job->end_at = date("Y-m-d H:i:s");
        $job->status = "completed";
        $job->session_time = $interval;

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject =
            "Information om avslutad tolkning för bokningsnummer # " . $job->id;
        $session_explode = explode(":", $job->session_time);
        $session_time =
            $session_explode[0] . " tim " . $session_explode[1] . " min";
        $data = [
            "user" => $user,
            "job" => $job,
            "session_time" => $session_time,
            "for_text" => "faktura",
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, "emails.session-ended", $data);

        $job->save();

        $tr = $job
            ->translatorJobRel()
            ->where("completed_at", null)
            ->where("cancel_at", null)
            ->first();

        Event::fire(
            new SessionEnded(
                $job,
                $post_data["user_id"] == $job->user_id
                    ? $tr->user_id
                    : $job->user_id
            )
        );

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject =
            "Information om avslutad tolkning för bokningsnummer # " . $job->id;
        $data = [
            "user" => $user,
            "job" => $job,
            "session_time" => $session_time,
            "for_text" => "lön",
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, "emails.session-ended", $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data["user_id"];
        $tr->save();
        $response["status"] = "success";
        return $response;
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
        $interval = $diff->format("%h:%i:%s");
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

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env("SUPERADMIN_ROLE_ID")) {
            if (
                isset($requestdata["feedback"]) &&
                $requestdata["feedback"] != "false"
            ) {
                $allJobs
                    ->where("ignore_feedback", "0")
                    ->whereHas("feedback", function ($q) {
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
                        ->pluck("job_id")
                        ->all();
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
                $allJobs
                    ->where("customer_physical_type", $requestdata["physical"])
                    ->where("ignore_physical", 0);
            }

            if (isset($requestdata["phone"])) {
                $allJobs->where("customer_phone_type", $requestdata["phone"]);
                if (isset($requestdata["physical"])) {
                    $allJobs->where("ignore_physical_phone", 0);
                }
            }

            if (isset($requestdata["flagged"])) {
                $allJobs
                    ->where("flagged", $requestdata["flagged"])
                    ->where("ignore_flagged", 0);
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
        } else {
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
                $allJobs
                    ->where("ignore_feedback", "0")
                    ->whereHas("feedback", function ($q) {
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

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];

        foreach ($jobs as $job) {
            $sessionTime = explode(":", $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[] =
                    $sessionTime[0] * 60 +
                    $sessionTime[1] +
                    $sessionTime[2] / 60;

                if ($diff[count($diff) - 1] >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }

        $jobId = array_column($sesJobs, "id");
        $languages = Language::where("active", "1")
            ->orderBy("language")
            ->get();
        $requestdata = Request::all();
        $all_customers = DB::table("users")
            ->where("user_type", "1")
            ->pluck("email");
        $all_translators = DB::table("users")
            ->where("user_type", "2")
            ->pluck("email");
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, "consumer_type");

        if ($cuser && $cuser->is("superadmin")) {
            $allJobs = DB::table("jobs")
                ->join(
                    "languages",
                    "jobs.from_language_id",
                    "=",
                    "languages.id"
                )
                ->whereIn("jobs.id", $jobId)
                ->whereIn("jobs.from_language_id", $requestdata["lang"] ?? [])
                ->whereIn("jobs.status", $requestdata["status"] ?? [])
                ->where("jobs.ignore", 0);

            if (
                isset($requestdata["customer_email"]) &&
                $requestdata["customer_email"] != ""
            ) {
                $user = DB::table("users")
                    ->where("email", $requestdata["customer_email"])
                    ->first();
                if ($user) {
                    $allJobs->where("jobs.user_id", "=", $user->id);
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
                        ->pluck("job_id");
                    $allJobs->whereIn("jobs.id", $allJobIDs);
                }
            }

            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "created"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where(
                        "jobs.created_at",
                        ">=",
                        $requestdata["from"]
                    );
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("jobs.created_at", "<=", $to);
                }
                $allJobs->orderBy("jobs.created_at", "desc");
            }

            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "due"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where("jobs.due", ">=", $requestdata["from"]);
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("jobs.due", "<=", $to);
                }
                $allJobs->orderBy("jobs.due", "desc");
            }

            if (
                isset($requestdata["job_type"]) &&
                $requestdata["job_type"] != ""
            ) {
                $allJobs->whereIn("jobs.job_type", $requestdata["job_type"]);
            }

            $allJobs
                ->select("jobs.*", "languages.language")
                ->orderBy("jobs.created_at", "desc")
                ->paginate(15);
        }

        return [
            "allJobs" => $allJobs,
            "languages" => $languages,
            "all_customers" => $all_customers,
            "all_translators" => $all_translators,
            "requestdata" => $requestdata,
        ];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where("ignore", 0)
            ->with("user")
            ->paginate(15);

        return [
            "throttles" => $throttles,
        ];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where("active", "1")
            ->orderBy("language")
            ->get();
        $requestdata = Request::all();
        $all_customers = DB::table("users")
            ->where("user_type", "1")
            ->pluck("email");
        $all_translators = DB::table("users")
            ->where("user_type", "2")
            ->pluck("email");

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
                ->where("jobs.ignore_expired", 0)
                ->whereIn("jobs.status", ["pending"])
                ->where("jobs.due", ">=", Carbon::now());

            if (isset($requestdata["lang"]) && $requestdata["lang"] != "") {
                $allJobs->whereIn(
                    "jobs.from_language_id",
                    $requestdata["lang"]
                );
            }

            if (isset($requestdata["status"]) && $requestdata["status"] != "") {
                $allJobs->whereIn("jobs.status", $requestdata["status"]);
            }

            if (
                isset($requestdata["customer_email"]) &&
                $requestdata["customer_email"] != ""
            ) {
                $user = DB::table("users")
                    ->where("email", $requestdata["customer_email"])
                    ->first();
                if ($user) {
                    $allJobs->where("jobs.user_id", $user->id);
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
                        ->pluck("job_id");
                    $allJobs->whereIn("jobs.id", $allJobIDs);
                }
            }

            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "created"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where(
                        "jobs.created_at",
                        ">=",
                        $requestdata["from"]
                    );
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("jobs.created_at", "<=", $to);
                }
                $allJobs->orderBy("jobs.created_at", "desc");
            }

            if (
                isset($requestdata["filter_timetype"]) &&
                $requestdata["filter_timetype"] == "due"
            ) {
                if (isset($requestdata["from"]) && $requestdata["from"] != "") {
                    $allJobs->where("jobs.due", ">=", $requestdata["from"]);
                }
                if (isset($requestdata["to"]) && $requestdata["to"] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where("jobs.due", "<=", $to);
                }
                $allJobs->orderBy("jobs.due", "desc");
            }

            if (
                isset($requestdata["job_type"]) &&
                $requestdata["job_type"] != ""
            ) {
                $allJobs->whereIn("jobs.job_type", $requestdata["job_type"]);
            }

            $allJobs
                ->select("jobs.*", "languages.language")
                ->orderBy("jobs.created_at", "desc")
                ->paginate(15);
        }

        return [
            "allJobs" => $allJobs,
            "languages" => $languages,
            "all_customers" => $all_customers,
            "all_translators" => $all_translators,
            "requestdata" => $requestdata,
        ];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::findOrFail($id);
        $job->ignore = 1;
        $job->save();

        return ["success" => true, "message" => "Changes saved"];
    }

    public function ignoreExpired($id)
    {
        $job = Job::findOrFail($id);
        $job->ignore_expired = 1;
        $job->save();

        return ["success" => true, "message" => "Changes saved"];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::findOrFail($id);
        $throttle->ignore = 1;
        $throttle->save();

        return ["success" => true, "message" => "Changes saved"];
    }

    public function reopen($request)
    {
        $jobid = $request["jobid"];
        $userid = $request["userid"];

        $job = Job::findOrFail($jobid);
        $jobData = $job->toArray();

        $data = [
            "created_at" => date("Y-m-d H:i:s"),
            "will_expire_at" => TeHelper::willExpireAt(
                $jobData["due"],
                date("Y-m-d H:i:s")
            ),
            "updated_at" => date("Y-m-d H:i:s"),
            "user_id" => $userid,
            "job_id" => $jobid,
            "cancel_at" => Carbon::now(),
        ];

        $datareopen = [
            "status" => "pending",
            "created_at" => Carbon::now(),
            "will_expire_at" => TeHelper::willExpireAt(
                $jobData["due"],
                Carbon::now()
            ),
        ];

        if ($jobData["status"] != "timedout") {
            Job::where("id", $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $jobData["status"] = "pending";
            $jobData["created_at"] = Carbon::now();
            $jobData["updated_at"] = Carbon::now();
            $jobData["will_expire_at"] = TeHelper::willExpireAt(
                $jobData["due"],
                date("Y-m-d H:i:s")
            );
            $jobData["updated_at"] = date("Y-m-d H:i:s");
            $jobData["cust_16_hour_email"] = 0;
            $jobData["cust_48_hour_email"] = 0;
            $jobData["admin_comments"] =
                "This booking is a reopening of booking #" . $jobid;
            $affectedRows = Job::create($jobData);
            $new_jobid = $affectedRows->id;
        }

        Translator::where("job_id", $jobid)
            ->whereNull("cancel_at")
            ->update(["cancel_at" => $data["cancel_at"]]);
        Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function convertToHoursMins($time, $format = "%02dh %02dmin")
    {
        if ($time < 60) {
            return $time . "min";
        } elseif ($time === 60) {
            return "1h";
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }
}