<?php

namespace DTApi\Http\Controllers\booking;

use DTApi\Repository\booking\BookingInterface;

use DTApi\Repository\job\JobInterface;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Config;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers\booking
 */

class BookingController extends Controller
{
    /**
     * @var BookingInterface
     */
    protected $repository;

     /**
     * @var JobInterface
     */

    protected $jobRepository;

    /**
     * BookingController constructor.
     * @param BookingInterface $repository
     * @param JobInterface $jobRepository
     */

    public function __construct(BookingInterface $repository, JobInterface $jobRepository) {

        $this->repository = $repository;

        $this->jobRepository = $jobRepository;
    }

    /**
     * Get bookings based on user ID or admin/superadmin roles.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userId = $request->user_id;

        $authenticatedUser = $request->__authenticatedUser;

        if ($userId) {

            $data = $this->jobRepository->getUsersJobs($userId);

            return response()->json($data, Config::get('statusCode.OK'));
        }

        if (
            $authenticatedUser->user_type ==

                Config::get('constants.admin_role_id') ||

            $authenticatedUser->user_type ==

                Config::get('constants.superadmin_role_id')
        )
        {
            $data = $this->repository->getAll($request);

            return response()->json($data, Config::get('statusCode.OK'));
        }

        return response()->json(['error' => 'Invalid request'],Config::get('statusCode.BAD_REQUEST'));
    }

    /**
     * Store a new booking.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $userData = $request->__authenticatedUser;

        $requestData = $request->all();

        $response = $this->repository->store($userData, $requestData);

        return response()->json($response, Config::get('statusCode.OK'));
    }

    /**
     * Show details of a specific booking.
     *
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {

            $job = $this->repository->show($id)->with('translatorJobRel.user');

            return response()->json($job, Config::get('statusCode.OK'));

        }   catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json(['error' => 'Job not found'],Config::get('statusCode.NOT_FOUND'));
        }
    }

    /**
     * Update a booking.
     *
     * @param $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, Request $request)
    {
        $requestData = $request->except(['_token', 'submit']);

        $authenticatedUser = $request->__authenticatedUser;

        $response = $this->repository->update($id,$requestData,$authenticatedUser);

        return response()->json($response, Config::get('statusCode.OK'));
    }

     /**
     * Handle customer not call scenario for a booking.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerNotCall(Request $request)
    {
        $requestData = $request->all();

        $response = $this->repository->customerNotCall($requestData);

        return response()->json($response, Config::get('statusCode.OK'));
    }

    /**
     * Update distance information for a booking.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->distanceFeed($data);

        return response()->json($response, Config::get('statusCode.OK'));
    }
}
