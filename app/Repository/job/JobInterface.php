<?php 

namespace DTApi\Repository\job;

interface JobInterface{

    public function storeJobEmail($data);

    public function getUsersJobsHistory($user_id, $request);

    public function acceptJob($data, $user);

    public function acceptJobWithId($job_id, $cuser);

    public function show($id);

    public function getUsersJobs($user_id);

    public function jobToData($job);

    public function cancelJobAjax($data, $user);

    public function jobEnd($post_data = []);

    public function getPotentialJobIdsWithUserId($user_id);

    public function endJob($post_data);

    public function getPotentialJobs($cuser);
}