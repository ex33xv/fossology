<?php
/***************************************************************
 * Copyright (C) 2020 Siemens AG
 * Author: Gaurav Mishra <mishra.gaurav@siemens.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***************************************************************/
/**
 * @file
 * @brief Unit tests for JobController
 */

namespace Fossology\UI\Api\Test\Controllers;

use Mockery as M;
use Fossology\UI\Api\Controllers\JobController;
use Fossology\UI\Api\Models\Job;
use Fossology\Lib\Dao\JobDao;
use Fossology\Lib\Dao\ShowJobsDao;
use Fossology\UI\Api\Models\Info;
use Fossology\UI\Api\Models\InfoType;
use Fossology\UI\Api\Helper\ResponseHelper;
use Slim\Psr7\Request;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Uri;
use Slim\Psr7\Headers;

/**
 * @class JobControllerTest
 * @brief Tests for JobController
 */
class JobControllerTest extends \PHPUnit\Framework\TestCase
{
  /**
   * @var DbHelper $dbHelper
   * DB Helper mock
   */
  private $dbHelper;

  /**
   * @var RestHelper $restHelper
   * RestHelper mock
   */
  private $restHelper;

  /**
   * @var JobDao $jobDao
   * JobDao mock
   */
  private $jobDao;

  /**
   * @var ShowJobsDao $showJobsDao
   * ShowJobsDao mock
   */
  private $showJobsDao;

  /**
   * @var JobController $jobController
   * JobController object to test
   */
  private $jobController;

  /**
   * @var integer $assertCountBefore
   * Assertions before running tests
   */
  private $assertCountBefore;

  /**
   * @var StreamFactory $streamFactory
   * Stream factory to create body streams.
   */
  private $streamFactory;

  /**
   * @brief Setup test objects
   * @see PHPUnit_Framework_TestCase::setUp()
   */
  protected function setUp() : void
  {
    global $container;
    $container = M::mock('ContainerBuilder');
    $this->dbHelper = M::mock(DbHelper::class);
    $this->restHelper = M::mock(RestHelper::class);
    $this->jobDao = M::mock(JobDao::class);
    $this->showJobsDao = M::mock(ShowJobsDao::class);

    $this->restHelper->shouldReceive('getDbHelper')->andReturn($this->dbHelper);
    $this->restHelper->shouldReceive('getJobDao')->andReturn($this->jobDao);
    $this->restHelper->shouldReceive('getShowJobDao')->andReturn($this->showJobsDao);

    $container->shouldReceive('get')->withArgs(array(
      'helper.restHelper'))->andReturn($this->restHelper);
    $this->jobController = new JobController($container);
    $this->assertCountBefore = \Hamcrest\MatcherAssert::getCount();
    $this->streamFactory = new StreamFactory();
  }

  /**
   * @brief Remove test objects
   * @see PHPUnit_Framework_TestCase::tearDown()
   */
  protected function tearDown() : void
  {
    $this->addToAssertionCount(
      \Hamcrest\MatcherAssert::getCount() - $this->assertCountBefore);
    M::close();
  }


  /**
   * Helper function to get JSON array from response
   *
   * @param Response $response
   * @return array Decoded response
   */
  private function getResponseJson($response)
  {
    $response->getBody()->seek(0);
    return json_decode($response->getBody()->getContents(), true);
  }

  /**
   * @test
   * -# Test JobController::getJobs() for all jobs
   * -# Check if response is 200
   */
  public function testGetJobs()
  {
    $job = new Job(11, "job_name", "01-01-2020", 4, 2, 2, 0, "Completed");
    $this->dbHelper->shouldReceive('getJobs')->withArgs(array(null, 0, 1))
      ->andReturn([[$job], 1]);
    $this->jobDao->shouldReceive('getAllJobStatus')->withArgs(array(4, 2, 2))
      ->andReturn(['11' => 0]);
    $this->showJobsDao->shouldReceive('getEstimatedTime')
      ->withArgs(array(11, '', 0, 4))->andReturn("0");
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs(array(11))->andReturn(["jq_endtext"=>'Completed']);

    $requestHeaders = new Headers();
    $body = $this->streamFactory->createStream();
    $request = new Request("GET", new Uri("HTTP", "localhost"),
      $requestHeaders, [], [], $body);
    $response = new ResponseHelper();
    $actualResponse = $this->jobController->getJobs($request, $response, []);
    $expectedResponse = $job->getArray();
    $this->assertEquals(200, $actualResponse->getStatusCode());
    $this->assertEquals($expectedResponse,
      $this->getResponseJson($actualResponse)[0]);
    $this->assertEquals('1',
      $actualResponse->getHeaderLine('X-Total-Pages'));
  }

  /**
   * @test
   * -# Test JobController::getJobs() with limit and page set
   * -# Check if response is 200 and have correct total pages header
   */
  public function testGetJobsLimitPage()
  {
    $jobTwo = new Job(12, "job_two", "01-01-2020", 5, 2, 2, 0, "Completed");
    $this->dbHelper->shouldReceive('getJobs')->withArgs(array(null, 1, 2))
      ->andReturn([[$jobTwo], 2]);
    $this->jobDao->shouldReceive('getAllJobStatus')->withArgs(array(4, 2, 2))
      ->andReturn(['11' => 0]);
    $this->jobDao->shouldReceive('getAllJobStatus')->withArgs(array(5, 2, 2))
      ->andReturn(['12' => 0]);
    $this->showJobsDao->shouldReceive('getEstimatedTime')
      ->withArgs(array(M::anyOf(11, 12), '', 0, M::anyOf(4, 5)))->andReturn("0");
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([M::anyOf(11, 12)])->andReturn(["jq_endtext"=>'Completed']);

    $requestHeaders = new Headers();
    $requestHeaders->setHeader('limit', '1');
    $requestHeaders->setHeader('page', '2');
    $body = $this->streamFactory->createStream();
    $request = new Request("GET", new Uri("HTTP", "localhost"),
      $requestHeaders, [], [], $body);
    $response = new ResponseHelper();
    $actualResponse = $this->jobController->getJobs($request, $response, []);
    $expectedResponse = $jobTwo->getArray();
    $this->assertEquals(200, $actualResponse->getStatusCode());
    $this->assertEquals($expectedResponse,
      $this->getResponseJson($actualResponse)[0]);
    $this->assertEquals('2',
      $actualResponse->getHeaderLine('X-Total-Pages'));
  }

  /**
   * @test
   * -# Test JobController::getJobs() with invalid job id
   * -# Check if response is 404
   */
  public function testGetInvalidJob()
  {
    $this->dbHelper->shouldReceive('doesIdExist')
      ->withArgs(["job", "job_pk", 2])->andReturn(false);

    $requestHeaders = new Headers();
    $requestHeaders->setHeader('limit', '1');
    $requestHeaders->setHeader('page', '2');
    $body = $this->streamFactory->createStream();
    $request = new Request("GET", new Uri("HTTP", "localhost"),
      $requestHeaders, [], [], $body);
    $response = new ResponseHelper();
    $actualResponse = $this->jobController->getJobs($request, $response, [
      "id" => 2]);
    $expectedResponse = new Info(404, "Job id 2 doesn't exist", InfoType::ERROR);
    $this->assertEquals($expectedResponse->getCode(),
      $actualResponse->getStatusCode());
    $this->assertEquals($expectedResponse->getArray(),
      $this->getResponseJson($actualResponse));
  }

  /**
   * @test
   * -# Test JobController::getJobs() with single job id
   * -# Check if response is 200
   */
  public function testGetJobFromId()
  {
    $job = new Job(12, "job_two", "01-01-2020", 5, 2, 2, 0, "Completed");
    $this->dbHelper->shouldReceive('doesIdExist')
      ->withArgs(["job", "job_pk", 12])->andReturn(true);
    $this->dbHelper->shouldReceive('getJobs')->withArgs(array(12, 0, 1))
      ->andReturn([[$job], 1]);
    $this->jobDao->shouldReceive('getAllJobStatus')->withArgs(array(5, 2, 2))
      ->andReturn(['12' => 0]);
    $this->showJobsDao->shouldReceive('getEstimatedTime')
      ->withArgs(array(12, '', 0, 5))->andReturn("0");
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([12])->andReturn(["jq_endtext"=>'Completed']);

    $requestHeaders = new Headers();
    $body = $this->streamFactory->createStream();
    $request = new Request("GET", new Uri("HTTP", "localhost"),
      $requestHeaders, [], [], $body);
    $response = new ResponseHelper();
    $actualResponse = $this->jobController->getJobs($request, $response, [
      "id" => 12]);
    $expectedResponse = $job->getArray();
    $this->assertEquals(200, $actualResponse->getStatusCode());
    $this->assertEquals($expectedResponse,
      $this->getResponseJson($actualResponse));
    $this->assertEquals('1',
      $actualResponse->getHeaderLine('X-Total-Pages'));
  }

  /**
   * @test
   * -# Test JobController::getJobs() with single upload
   * -# Check if response is 200
   */
  public function testGetJobsFromUpload()
  {
    $job = new Job(12, "job_two", "01-01-2020", 5, 2, 2, 0, "Completed");
    $this->dbHelper->shouldReceive('doesIdExist')
      ->withArgs(["upload", "upload_pk", 5])->andReturn(true);
    $this->dbHelper->shouldReceive('doesIdExist')
      ->withArgs(['job', 'job_pk', 12])->andReturn(true);
    $this->dbHelper->shouldReceive('getJobs')->withArgs(array(null, 0, 1, 5))
      ->andReturn([[$job], 1]);
    $this->jobDao->shouldReceive('getAllJobStatus')->withArgs(array(5, 2, 2))
      ->andReturn(['12' => 0]);
    $this->showJobsDao->shouldReceive('getEstimatedTime')
      ->withArgs(array(12, '', 0, 5))->andReturn("0");
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([12])->andReturn(["jq_endtext"=>'Completed']);

    $requestHeaders = new Headers();
    $body = $this->streamFactory->createStream();
    $request = new Request("GET", new Uri("HTTP", "localhost"),
      $requestHeaders, [], [], $body);
    $request = $request->withQueryParams([JobController::UPLOAD_PARAM => 5]);
    $response = new ResponseHelper();
    $actualResponse = $this->jobController->getJobs($request, $response, []);
    $expectedResponse = $job->getArray();
    $this->assertEquals(200, $actualResponse->getStatusCode());
    $this->assertEquals($expectedResponse,
      $this->getResponseJson($actualResponse)[0]);
    $this->assertEquals('1',
      $actualResponse->getHeaderLine('X-Total-Pages'));
  }

  /**
   * @test
   * -# Test JobController::getUploadEtaInSeconds()
   * -# Test if HH:MM:SS can be translated to seconds
   * -# Test if empty response results in 0
   */
  public function testGetUploadEtaInSeconds()
  {
    $jobId = 11;
    $uploadId = 5;
    $completedJob = 5;
    $completedUpload = 3;
    $this->showJobsDao->shouldReceive('getEstimatedTime')
      ->withArgs([$jobId, '', 0, $uploadId])
      ->andReturn("3:10:23");
    $this->showJobsDao->shouldReceive('getEstimatedTime')
      ->withArgs([$completedJob, '', 0, $completedUpload])
      ->andReturn("0");
    $reflection = new \ReflectionClass(get_class($this->jobController));
    $method = $reflection->getMethod('getUploadEtaInSeconds');
    $method->setAccessible(true);

    $result = $method->invokeArgs($this->jobController, [$jobId, $uploadId]);
    $this->assertEquals((3 * 3600) + (10 * 60) + 23, $result);

    $result = $method->invokeArgs($this->jobController,
      [$completedJob, $completedUpload]);
    $this->assertEquals(0, $result);
  }

  /**
   * @test
   * -# Test JobController::getJobStatus()
   * -# Setup one job with two complete children => result Completed
   * -# Setup one job with one child processing and other in queue => result
   *    Processing
   * -# Setup one job with one child completed and one failed => result Failed
   */
  public function testGetJobStatus()
  {
    $jobCompleted = [1, 2];
    $jobQueued = [3, 4];
    $jobFailed = [5, 6];
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([M::anyof(1, 2, 5)])
      ->andReturn(["jq_endtext" => "Completed"]);
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([3])->andReturn(["jq_endtext" => "Started"]);
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([4])->andReturn(["jq_endtext" => "Processing",
        "jq_endtime" => ""]);
    $this->showJobsDao->shouldReceive('getDataForASingleJob')
      ->withArgs([6])->andReturn(["jq_endtext" => "Failed",
        "jq_endtime" => "01-01-2020 00:00:00"]);

    $reflection = new \ReflectionClass(get_class($this->jobController));
    $method = $reflection->getMethod('getJobStatus');
    $method->setAccessible(true);

    $result = $method->invokeArgs($this->jobController, [$jobCompleted]);
    $this->assertEquals("Completed", $result);

    $result = $method->invokeArgs($this->jobController, [$jobQueued]);
    $this->assertEquals("Processing", $result);

    $result = $method->invokeArgs($this->jobController, [$jobFailed]);
    $this->assertEquals("Failed", $result);
  }
}
