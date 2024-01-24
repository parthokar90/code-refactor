<?php

namespace DTApi\Http\Controllers\notification;

use DTApi\Repository\notification\NotificationInterface;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Config;

/**
 * Class NotificationController
 * @package DTApi\Http\Controllers
 */
class NotificationController extends Controller
{
     /**
     * @var NotificationInterface
     */
    protected $repository;

     /**
     * Notification Repository constructor.
     * @param NotificationInterface $repository
     */
    public function __construct(NotificationInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Resend push notifications for a specific job.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendNotifications(Request $request)
    {
        $jobId = $request->jobid;

        try {

            $job = $this->repository->show($jobId)->with('translatorJobRel.user');

            $jobData = $this->repository->jobToData($job);

            $this->repository->sendNotificationTranslator($job, $jobData, '*');

            return response()->json(['success' => 'Push sent'],Config::get('statusCode.OK'));
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json(['error' => 'Job not found'],Config::get('statusCode.NOT_FOUND')

            );
        }
    }

    /**
     * Resend SMS notifications to the translator for a specific job.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendSMSNotifications(Request $request)
    {
        $jobId = $request->jobid;

        try {

            $job = $this->repository->show($jobId)->with('translatorJobRel.user');

            $jobData = $this->repository->jobToData($job);

            $this->repository->sendSMSNotificationToTranslator($job);

            return response()->json(['success' => 'SMS sent'],Config::get('statusCode.OK'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json(['error' => 'Job not found'],Config::get('statusCode.NOT_FOUND'));

        } catch (\Exception $e) {

            return response()->json(['error' => $e->getMessage()],Config::get('statusCode.INTERNAL_SERVER_ERROR')

            );
        }
    }
}
