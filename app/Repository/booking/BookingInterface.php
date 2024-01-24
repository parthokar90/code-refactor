<?php 

namespace DTApi\Repository\booking;

interface BookingInterface {

    public function getUsersJobs($user_id);

    public function getAll($request, $limit = null);

    public function store($user, array $data);

    public function show($id);

    public function update($id, array $data, $cuser);

    public function customerNotCall($post_data);

    public function distanceFeed($request);

    public function reopen($request);

    public function ignoreExpiring($id);

    public function ignoreExpired($id);

    public function ignoreThrottle($id);

    public function userLoginFailed();

    public function bookingExpireNoAccepted();

    public function changeStatus($job, $data, $changedTranslator);

    public function changeTimedoutStatus($job, $data, $changedTranslator);

    public function changeCompletedStatus($job, $data);

    public function changeStartedStatus($job, $data);

    public function changePendingStatus($job, $data, $changedTranslator);

    public function changeWithdrawafter24Status($job, $data);

    public function changeAssignedStatus($job, $data);

    public function changeTranslator($current_translator, $data, $job);
}