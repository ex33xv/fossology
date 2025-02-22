<?php
/*
 * Copyright (C) 2021 Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
namespace Fossology\CliXml;

use Fossology\Lib\Agent\Agent;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\Package\ComponentType;
use Fossology\Lib\Data\Upload\Upload;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Report\XpClearedGetter;
use Fossology\Lib\Report\LicenseMainGetter;
use Fossology\Lib\Report\LicenseClearedGetter;
use Fossology\Lib\Report\ObligationsGetter;
use Fossology\Lib\Report\OtherGetter;
use Fossology\Lib\Report\LicenseIrrelevantGetter;
use Fossology\Lib\Report\LicenseDNUGetter;

include_once(__DIR__ . "/version.php");
include_once(__DIR__ . "/services.php");

class CliXml extends Agent
{

  const OUTPUT_FORMAT_KEY = "outputFormat";
  const DEFAULT_OUTPUT_FORMAT = "clixml";
  const AVAILABLE_OUTPUT_FORMATS = "xml";
  const UPLOAD_ADDS = "uploadsAdd";

  /** @var UploadDao */
  private $uploadDao;

  /** @var DbManager */
  protected $dbManager;

  /** @var Twig_Environment */
  protected $renderer;

  /** @var string */
  protected $uri;

  /** @var string */
  protected $packageName;

  /** @var cpClearedGetter $cpClearedGetter
   * Copyright clearance object
   */
  private $cpClearedGetter;

  /** @var eccClearedGetter $eccClearedGetter
   * ECC clearance object
   */
  private $eccClearedGetter;
  /** @var LicenseDNUGetter $licenseDNUGetter
   * LicenseDNUGetter object
   */
  private $licenseDNUGetter;

  /** @var string */
  protected $outputFormat = self::DEFAULT_OUTPUT_FORMAT;

  function __construct()
  {
    parent::__construct('clixml', AGENT_VERSION, AGENT_REV);

    $this->uploadDao = $this->container->get('dao.upload');
    $this->dbManager = $this->container->get('db.manager');
    $this->renderer = $this->container->get('twig.environment');
    $this->renderer->setCache(false);

    $this->cpClearedGetter = new XpClearedGetter("copyright", "statement");
    $this->eccClearedGetter = new XpClearedGetter("ecc", "ecc");
    $this->licenseIrrelevantGetter = new LicenseIrrelevantGetter();
    $this->licenseIrrelevantGetterComments = new LicenseIrrelevantGetter(false);
    $this->licenseDNUGetter = new LicenseDNUGetter();
    $this->licenseDNUCommentGetter = new LicenseDNUGetter(false);
    $this->licenseClearedGetter = new LicenseClearedGetter();
    $this->licenseMainGetter = new LicenseMainGetter();
    $this->obligationsGetter = new ObligationsGetter();
    $this->otherGetter = new OtherGetter();
    $this->agentSpecifLongOptions[] = self::UPLOAD_ADDS.':';
    $this->agentSpecifLongOptions[] = self::OUTPUT_FORMAT_KEY.':';
  }

  /**
   * @param string[] $args
   * @param string $key1
   * @param string $key2
   *
   * @return string[] $args
   */
  protected function preWorkOnArgsFlp($args,$key1,$key2)
  {
    $needle = ' --'.$key2.'=';
    if (strpos($args[$key1],$needle) !== false) {
      $exploded = explode($needle,$args[$key1]);
      $args[$key1] = trim($exploded[0]);
      $args[$key2] = trim($exploded[1]);
    }
    return $args;
  }

  /**
   * @param string[] $args
   *
   * @return string[] $args
   */
  protected function preWorkOnArgs($args)
  {
    if ((!array_key_exists(self::OUTPUT_FORMAT_KEY,$args)
         || $args[self::OUTPUT_FORMAT_KEY] === "")
        && array_key_exists(self::UPLOAD_ADDS,$args)) {

        $args = $this->preWorkOnArgsFlp($args,self::UPLOAD_ADDS,self::OUTPUT_FORMAT_KEY);
    } else {
      if (!array_key_exists(self::UPLOAD_ADDS,$args) || $args[self::UPLOAD_ADDS] === "") {
        $args = $this->preWorkOnArgsFlp($args,self::UPLOAD_ADDS,self::OUTPUT_FORMAT_KEY);
      }
    }
    return $args;
  }

  function processUploadId($uploadId)
  {
    $groupId = $this->groupId;

    $args = $this->preWorkOnArgs($this->args);

    if (array_key_exists(self::OUTPUT_FORMAT_KEY,$args)) {
      $possibleOutputFormat = trim($args[self::OUTPUT_FORMAT_KEY]);
      if (in_array($possibleOutputFormat, explode(',',self::AVAILABLE_OUTPUT_FORMATS))) {
        $this->outputFormat = $possibleOutputFormat;
      }
    }
    $this->computeUri($uploadId);

    $contents = $this->renderPackage($uploadId, $groupId);

    $additionalUploadIds = array_key_exists(self::UPLOAD_ADDS,$args) ? explode(',',$args[self::UPLOAD_ADDS]) : array();
    $packageIds = array($uploadId);
    foreach ($additionalUploadIds as $additionalId) {
      $contents .= $this->renderPackage($additionalId, $groupId);
      $packageIds[] = $additionalId;
    }

    $this->writeReport($contents, $packageIds, $uploadId);
    return true;
  }

  protected function getTemplateFile($partname)
  {
    $prefix = $this->outputFormat . "-";
    $postfix = ".twig";
    $postfix = ".xml" . $postfix;
    return $prefix . $partname . $postfix;
  }

  protected function getUri($fileBase)
  {
    $fileName = $fileBase. strtoupper($this->outputFormat)."_".$this->packageName.'_'.date("Y-m-d_H:i:s");
    $fileName = $fileName .".xml" ;
    return $fileName;
  }

  protected function renderPackage($uploadId, $groupId)
  {
    $this->heartbeat(0);

    $otherStatement = $this->otherGetter->getReportData($uploadId);
    $this->heartbeat(empty($otherStatement) ? 0 : count($otherStatement));

    if (!empty($otherStatement['ri_unifiedcolumns'])) {
      $unifiedColumns = json_decode($otherStatement['ri_unifiedcolumns'], true);
    } else {
      $unifiedColumns = UploadDao::UNIFIED_REPORT_HEADINGS;
    }

    $licenses = $this->licenseClearedGetter->getCleared($uploadId, $this, $groupId, true, "license", false);
    $this->heartbeat(empty($licenses) ? 0 : count($licenses["statements"]));

    $licensesMain = $this->licenseMainGetter->getCleared($uploadId, $this, $groupId, true, null, false);
    $this->heartbeat(empty($licensesMain) ? 0 : count($licensesMain["statements"]));

    if (array_values($unifiedColumns['irrelevantfiles'])[0]) {
      $licensesIrre = $this->licenseIrrelevantGetter->getCleared($uploadId, $this, $groupId, true, null, false);
      $irreComments = $this->licenseIrrelevantGetterComments->getCleared($uploadId, $this, $groupId, true, null, false);
    } else {
      $licensesIrre = array("statements" => array());
      $irreComments = array("statements" => array());
    }
    $this->heartbeat(empty($licensesIrre) ? 0 : count($licensesIrre["statements"]));
    $this->heartbeat(empty($irreComments) ? 0 : count($irreComments["statements"]));

    if (array_values($unifiedColumns['dnufiles'])[0]) {
      $licensesDNU = $this->licenseDNUGetter->getCleared($uploadId, $this, $groupId, true, null, false);
      $licensesDNUComment = $this->licenseDNUCommentGetter->getCleared($uploadId, $this, $groupId, true, null, false);
    } else {
      $licensesDNU = array("statements" => array());
      $licensesDNUComment = array("statements" => array());
    }
    $this->heartbeat(empty($licensesDNU) ? 0 : count($licensesDNU["statements"]));
    $this->heartbeat(empty($licensesDNUComment) ? 0 : count($licensesDNUComment["statements"]));

    if (array_values($unifiedColumns['copyrights'])[0]) {
      $copyrights = $this->cpClearedGetter->getCleared($uploadId, $this, $groupId, true, "copyright", false);
    } else {
      $copyrights = array("statements" => array());
    }
    $this->heartbeat(empty($copyrights["statements"]) ? 0 : count($copyrights["statements"]));

    if (array_values($unifiedColumns['exportrestrictions'])[0]) {
      $ecc = $this->eccClearedGetter->getCleared($uploadId, $this, $groupId, true, "ecc", false);
    } else {
      $ecc = array("statements" => array());
    }
    $this->heartbeat(empty($ecc) ? 0 : count($ecc["statements"]));

    if (array_values($unifiedColumns['notes'])[0]) {
      $notes = htmlspecialchars($otherStatement['ri_ga_additional'], ENT_DISALLOWED);
    } else {
      $notes = "";
    }

    $countAcknowledgement = 0;
    $includeAcknowledgements = array_values($unifiedColumns['acknowledgements'])[0];
    $licensesWithAcknowledgement = $this->removeDuplicateAcknowledgements(
      $licenses["statements"], $countAcknowledgement, $includeAcknowledgements);

    if (array_values($unifiedColumns['overviewwithwithoutobligations'])[0]) {
      $obligations = $this->obligationsGetter->getObligations(
        $licenses['statements'], $licensesMain['statements'], $uploadId, $groupId)[0];
      $obligations = array_values($obligations);
    } else {
      $obligations = array();
    }

    if (array_values($unifiedColumns['mainlicenses'])[0]) {
      $mainLicenses = $licensesMain["statements"];
    } else {
      $mainLicenses = array();
    }
    $componentHash = $this->uploadDao->getUploadHashes($uploadId);
    $contents = array(
      "licensesMain" => $mainLicenses,
      "licenses" => $licensesWithAcknowledgement,
      "obligations" => $obligations,
      "copyrights" => $copyrights["statements"],
      "ecc" => $ecc["statements"],
      "licensesIrre" => $licensesIrre["statements"],
      "irreComments" => $irreComments["statements"],
      "licensesDNU" => $licensesDNU["statements"],
      "licensesDNUComment" => $licensesDNUComment["statements"],
      "countAcknowledgement" => $countAcknowledgement
    );
    $contents = $this->reArrangeMainLic($contents, $includeAcknowledgements);
    $contents = $this->reArrangeContent($contents);
    list($generalInformation, $assessmentSummary) = $this->getReportSummary($uploadId);
    $generalInformation['componentHash'] = $componentHash['sha1'];
    return $this->renderString($this->getTemplateFile('file'),array(
      'documentName' => $this->packageName,
      'version' => "1.5",
      'uri' => $this->uri,
      'userName' => $this->container->get('dao.user')->getUserName($this->userId),
      'organisation' => '',
      'componentHash' => strtolower($componentHash['sha1']),
      'contents' => $contents,
      'packageIds' => $packageIds,
      'commentAdditionalNotes' => $notes,
      'externalIdLink' => htmlspecialchars($otherStatement['ri_sw360_link']),
      'generalInformation' => $generalInformation,
      'assessmentSummary' => $assessmentSummary
    ));
  }

  protected function removeDuplicateAcknowledgements($licenses, &$countAcknowledgement, $includeAcknowledgements)
  {
    if (empty($licenses)) {
      return $licenses;
    }

    foreach ($licenses as $ackKey => $ackValue) {
      if (!$includeAcknowledgements) {
        $licenses[$ackKey]['acknowledgement'] = null;
      } else if (isset($ackValue['acknowledgement'])) {
        $licenses[$ackKey]['acknowledgement'] = array_unique(array_filter($ackValue['acknowledgement']));
        $countAcknowledgement += count($licenses[$ackKey]['acknowledgement']);
      }
    }
    return $licenses;
  }

  protected function riskMapping($licenseContent)
  {
    foreach ($licenseContent as $riskKey => $riskValue) {
      if ($riskValue['risk'] == '2' || $riskValue['risk'] == '3') {
        $licenseContent[$riskKey]['risk'] = 'otheryellow';
      } else if ($riskValue['risk'] == '4' || $riskValue['risk'] == '5') {
        $licenseContent[$riskKey]['risk'] = 'otherred';
      } else {
        $licenseContent[$riskKey]['risk'] = 'otherwhite';
      }
    }
    return $licenseContent;
  }

  protected function reArrangeMainLic($contents, $includeAcknowledgements)
  {
    $mainlic = array();
    $lenTotalLics = count($contents["licenses"]);
    // both of this variables have same value but used for different operations
    $lenMainLics = count($contents["licensesMain"]);
    for ($i=0; $i<$lenMainLics; $i++) {
      $count = 0 ;
      for ($j=0; $j<$lenTotalLics; $j++) {
        if (!strcmp($contents["licenses"][$j]["content"], $contents["licensesMain"][$i]["content"])) {
          $count = 1;
          $mainlic[] =  $contents["licenses"][$j];
          unset($contents["licenses"][$j]);
        }
      }
      if ($count == 1) {
        unset($contents["licensesMain"][$i]);
      } else {
        $mainlic[] = $contents["licensesMain"][$i];
        unset($contents["licensesMain"][$i]);
      }
    }
    $contents["licensesMain"] = $mainlic;

    $lenMainLicenses=count($contents["licensesMain"]);
    for ($i=0; $i<$lenMainLicenses; $i++) {
      $contents["licensesMain"][$i]["contentMain"] = $contents["licensesMain"][$i]["content"];
      $contents["licensesMain"][$i]["textMain"] = $contents["licensesMain"][$i]["text"];
      $contents["licensesMain"][$i]["riskMain"] = $contents["licensesMain"][$i]["risk"];
      if (array_key_exists('acknowledgement', $contents["licensesMain"][$i])) {
        if ($includeAcknowledgements) {
          $contents["licensesMain"][$i]["acknowledgementMain"] = $contents["licensesMain"][$i]["acknowledgement"];
        }
        unset($contents["licensesMain"][$i]["acknowledgement"]);
      }
      unset($contents["licensesMain"][$i]["content"]);
      unset($contents["licensesMain"][$i]["text"]);
      unset($contents["licensesMain"][$i]["risk"]);
    }
    return $contents;
  }

  protected function reArrangeContent($contents)
  {
    $contents['licensesMain'] = $this->riskMapping($contents['licensesMain']);
    $contents['licenses'] = $this->riskMapping($contents['licenses']);

    $contents["obligations"] = array_map(function($changeKey) {
      return array(
        'obliText' => $changeKey['text'],
        'topic' => $changeKey['topic'],
        'license' => $changeKey['license']
      );
    }, $contents["obligations"]);

    $contents["copyrights"] = array_map(function($changeKey) {
      $content = htmlspecialchars_decode($changeKey['content']);
      $content = str_replace("]]>", "]]&gt;", $content);
      $comments = htmlspecialchars_decode($changeKey['comments']);
      $comments = str_replace("]]>", "]]&gt;", $comments);
      return array(
        'contentCopy' => $content,
        'comments' => $comments,
        'files' => $changeKey['files'],
        'hash' => $changeKey['hash']
      );
    }, $contents["copyrights"]);

    $contents["ecc"] = array_map(function($changeKey) {
      $content = htmlspecialchars_decode($changeKey['content']);
      $content = str_replace("]]>", "]]&gt;", $content);
      $comments = htmlspecialchars_decode($changeKey['comments']);
      $comments = str_replace("]]>", "]]&gt;", $comments);
      return array(
        'contentEcc' => $content,
        'commentsEcc' => $comments,
        'files' => $changeKey['files'],
        'hash' => $changeKey['hash']
      );
    }, $contents["ecc"]);

    $contents["irreComments"] = array_map(function($changeKey) {
      return array(
        'contentIrre' => $changeKey['content'],
        'textIrre' => $changeKey['text']
      );
    }, $contents["irreComments"]);

    $contents["licensesIrre"] = array_map(function($changeKey) {
      return array(
        'filesIrre' => $changeKey['fullPath']
      );
    }, $contents["licensesIrre"]);

    $contents["licensesDNUComment"] = array_map(function($changeKey) {
      return array(
        'contentDNU' => $changeKey['content'],
        'textDNU' => $changeKey['text']
      );
    }, $contents["licensesDNUComment"]);

    $contents["licensesDNU"] = array_map(function($changeKey) {
      return array(
        'filesDNU' => $changeKey['fullPath']
      );
    }, $contents["licensesDNU"]);

    return $contents;
  }

  protected function computeUri($uploadId)
  {
    global $SysConf;
    $upload = $this->uploadDao->getUpload($uploadId);
    $this->packageName = $upload->getFilename();

    $fileBase = $SysConf['FOSSOLOGY']['path']."/report/";

    $this->uri = $this->getUri($fileBase);
  }

  protected function writeReport($contents, $packageIds, $uploadId)
  {
    $fileBase = dirname($this->uri);

    if (!is_dir($fileBase)) {
      mkdir($fileBase, 0777, true);
    }
    umask(0133);

    $message = $this->renderString($this->getTemplateFile('document'),
      array('content' => $contents));

    // To ensure the file is valid, replace any non-printable characters with a question mark.
    // 'Non-printable' is ASCII < 0x20 (excluding \r, \n and tab) and 0x7F - 0x9F.
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u','?',$message);
    file_put_contents($this->uri, $message);
    $this->updateReportTable($uploadId, $this->jobId, $this->uri);
  }

  protected function updateReportTable($uploadId, $jobId, $fileName)
  {
    $this->dbManager->insertTableRow('reportgen',
            array('upload_fk'=>$uploadId, 'job_fk'=>$jobId, 'filepath'=>$fileName),
            __METHOD__);
  }

  /**
   * @param string $templateName
   * @param array $vars
   * @return string
   */
  protected function renderString($templateName, $vars)
  {
    return $this->renderer->load($templateName)->render($vars);
  }

  /**
   * Generate the GeneralInformation and AssessmentSummary components for the
   * report.
   * @param int $uploadId Upload ID
   * @return array First element as associative array for GeneralInformation
   *               and second as associative array for AssessmentSummary
   */
  private function getReportSummary($uploadId)
  {
    $row = $this->uploadDao->getReportInfo($uploadId);

    $review = htmlspecialchars($row['ri_reviewed']);
    if ($review == 'NA') {
      $review = '';
    }
    $critical = 'None';
    $dependency = 'None';
    $ecc = 'None';
    $usage = 'None';
    if (!empty($row['ri_ga_checkbox_selection'])) {
      $listURCheckbox = explode(',', $row['ri_ga_checkbox_selection']);
      if ($listURCheckbox[0] == 'checked') {
        $critical = 'None';
      }
      if ($listURCheckbox[1] == 'checked') {
        $critical = 'Found';
      }
      if ($listURCheckbox[2] == 'checked') {
        $dependency = 'None';
      }
      if ($listURCheckbox[3] == 'checked') {
        $dependency = 'SourceDependenciesFound';
      }
      if ($listURCheckbox[4] == 'checked') {
        $dependency = 'BinaryDependenciesFound';
      }
      if ($listURCheckbox[5] == 'checked') {
        $ecc = 'None';
      }
      if ($listURCheckbox[6] == 'checked') {
        $ecc = 'Found';
      }
      if ($listURCheckbox[7] == 'checked') {
        $usage = 'None';
      }
      if ($listURCheckbox[8] == 'checked') {
        $usage = 'Found';
      }
    }
    $componentType = $row['ri_component_type'];
    $componentType = ComponentType::TYPE_MAP[$componentType];
    $componentId = $row['ri_component_id'];
    if (empty($componentId) || $componentId == "NA") {
      $componentId = "";
    }

    return [[
      'reportId' => uuid_create(UUID_TYPE_TIME),
      'reviewedBy' => $review,
      'componentName' => htmlspecialchars($row['ri_component']),
      'community' => htmlspecialchars($row['ri_community']),
      'version' => htmlspecialchars($row['ri_version']),
      'componentHash' => '',
      'componentReleaseDate' => htmlspecialchars($row['ri_release_date']),
      'linkComponentManagement' => htmlspecialchars($row['ri_sw360_link']),
      'componentType' => htmlspecialchars($componentType),
      'componentId' => htmlspecialchars($componentId)
    ], [
      'generalAssessment' => $row['ri_general_assesment'],
      'criticalFilesFound' => $critical,
      'dependencyNotes' => $dependency,
      'exportRestrictionsFound' => $ecc,
      'usageRestrictionsFound' => $usage,
      'additionalNotes' => $row['ri_ga_additional']
    ]];
  }
}

$agent = new CliXml();
$agent->scheduler_connect();
$agent->run_scheduler_event_loop();
$agent->scheduler_disconnect(0);
