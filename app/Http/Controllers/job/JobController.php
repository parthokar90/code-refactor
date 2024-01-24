<?php

namespace DTApi\Http\Controllers\job;

use DTApi\Repository\job\JobInterface;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Config;

/**
 * Class JobController
 * @package DTApi\Http\Controllers
 */
class JobController extends Controller
{
    /**
     * @var JobInterface
     */
    protected $repository;

     /**
     * JobController constructor.
     * @param JobInterface $repository
     */
    public function __construct(JobInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Store an immediate job email.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');

        $requestData = $request->all();

        $response = $this->repository->storeJobEmail($requestData);

        return response()->json($response, Config::get('statusCode.OK'));
    }

    /**
     * Get job history based on user ID.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHistory(Request $request)
    {
        $userId = $request->user_id;

        if ($userId) {

            $response = $this->repository->getUsersJobsHistory(
                $userId,
                $request
            );

            return response()->json($response, Config::get('statusCode.OK'));
        }

            return response()->json(['error' => 'Invalid request'], Config::get('statusCode.BAD_REQUEST')
        );
    }

     /**
     * Accept a job request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJob(Request $request)
    {
        $requestData = $request->all();

        $authenticatedUser = $request->__authenticatedUser;

        $response = $this->repository->acceptJob($requestData,$authenticatedUser);

        return response()->json($response, Config::get('statusCode.OK'));
    }

   /**
     * Accept a job with a specific ID.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJobWithId(Request $request)
    {
        $jobId = $request->job_id;

        $authenticatedUser = $request->__authenticatedUser;

        $response = $this->repository->acceptJobWithId($jobId,$authenticatedUser);

        return response()->json($response, Config::get('statusCode.OK'));
    }

     /**
     * Cancel a job request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelJob(Request $request)
    {
        $requestData = $request->all();

        $authenticatedUser = $request->__authenticatedUser;

        $response = $this->repository->cancelJobAjax($requestData,$authenticatedUser);

        return response()->json($response, Config::get('statusCode.OK'));
    }

    /**
     * End a job.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function endJob(Request $request)
    {
        $requestData = $request->all();

        $response = $this->repository->endJob($requestData);

        return response()->json($response, Config::get('statusCode.OK'));
    }

    /**
     * Get potential jobs based on the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPotentialJobs(Request $request)
    {
        $requestData = $request->all();

        $authenticatedUser = $request->__authenticatedUser;

        $response = $this->repository->getPotentialJobs($authenticatedUser);

        return response()->json($response, Config::get('statusCode.OK'));
    }

    /**
     * Reopen a job request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reopen(Request $request)
    {
        $requestData = $request->all();

        $response = $this->repository->reopen($requestData);

        return response()->json($response, Config::get('statusCode.OK'));
    }
}
