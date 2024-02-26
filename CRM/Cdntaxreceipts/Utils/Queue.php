<?php

class CRM_Cdntaxreceipts_Utils_Queue {
  
  static function createQueue($year, $previewMode, $tasks, $type) {

    $queueName = 'cdntaxreceipt_' . time();

    $queue = Civi::queue($queueName, [
      'type' => 'Sql',
      'runner' => 'task',
      'error' => 'abort',
    ]);
    
    \Civi\Api4\UserJob::create()->setValues([
      'job_type' => 'contact_import',
      'status_id:name' => 'in_progress',
      'queue_id.name' => $queue->getName(),
      'metadata' => [
        'preview' => $previewMode,
        'year' => $year,
        'print' => [],
        'type' => $type,
        'count' => [
          'email' => 0,
          'print' => 0,
          'data' => 0,
          'fail' => 0,
          'total' => count($tasks),
        ],
      ],
    ])->execute();

    foreach ($tasks as $task) {
      $queue->createItem($task);
    }

    return $queue;

  }

  static function getQueueId($queue) {
    // why can't we get the id from the queue object ?
    $queue = \Civi\Api4\Queue::get(TRUE)
      ->addSelect('*', 'custom.*')
      ->addWhere('name', '=', $queue->getName())
      ->execute()
      ->first();

    return $queue['id'];
  }

  static function updateMetadata($ret, $method, &$metadata) {
    if ($ret == 0)  {
      $metadata['count']['fail']++;
    }
    elseif ( $method == 'email' ) {
      $metadata['count']['email']++;
    }
    elseif ( $method == 'print' ) {
      $metadata['count']['print']++;
    }
    elseif ( $method == 'data' ) {
      $metadata['count']['data']++;
    }
  }

  static function processOneSingleContributionReceipt(CRM_Queue_TaskContext $ctx, $contributionId, $previewMode, $originalOnly) {

    $queueName = $ctx->queue->getName();

    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionId;
    if (!$contribution->find( TRUE )) {
      // FIXME: in a queue, what is the proper way of doing this ?
      CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution id." );
    }

    // if contribution is eligible for receipting, issue the tax receipt.  Otherwise ignore.
    if (cdntaxreceipts_eligibleForReceipt($contribution->id)) {

      $userJob = \Civi\Api4\UserJob::get(TRUE)
        ->addSelect('id', 'metadata', 'queue_id')
        ->addWhere('queue_id.name', '=', $queueName)
        ->setLimit(1)
        ->execute()
        ->first();

      $queueId = $userJob['queue_id'];
      $userJobId = $userJob['id'];
      $metadata = $userJob['metadata'];

      list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);
      if (empty($issued_on) || !$originalOnly) {

        $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
        list($ret, $method) = cdntaxreceipts_issueTaxReceipt($contribution, $receiptsForPrinting, $previewMode);
        self::updateMetadata($ret, $method, $metadata);
      
      }

      // in preview mode print everything
      if ($previewMode || $method == 'print') {
        if (self::saveReceiptForLaterPrint($receiptsForPrinting, $queueId, $metadata['type'], $contributionId)) {
          // add contactId to metadata to compute the pdf to print at the end
          if (!isset($metadata['print'])) $metadata['print'] = [];
          $metadata['print'][] = $contributionId;
        }
        else {
          $metadata['count']['fail']++;
        }
      }

      // save metadata
      $results = \Civi\Api4\UserJob::update(TRUE)
        ->addValue('metadata', $metadata)
        ->addWhere('id', '=', $userJobId)
        ->execute();

    }

    return TRUE;

  }

  // TODO: ensure we don't do the same contactId twice (e.g. how does the queue deal with refresh?)
  static function processOneAggregateReceipt(CRM_Queue_TaskContext $ctx, $contactId, $year, $previewMode, $contribution_status) {

    $queueName = $ctx->queue->getName();

	  $contributions = $contribution_status['contributions'];
    $method = $contribution_status['issue_method'];

    if (empty($issuedOn) && count($contributions) > 0) {

      $userJob = \Civi\Api4\UserJob::get(TRUE)
        ->addSelect('id', 'metadata', 'queue_id')
        ->addWhere('queue_id.name', '=', $queueName)
        ->setLimit(1)
        ->execute()
        ->first();
      $queueId = $userJob['queue_id'];
      $userJobId = $userJob['id'];
      $metadata = $userJob['metadata'];

      $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
      $ret = cdntaxreceipts_issueAggregateTaxReceipt($contactId, $year, $contributions, $method, $receiptsForPrinting, $previewMode);
      self::updateMetadata($ret, $method, $metadata);

      // in preview mode print everything
      if ($previewMode || $method == 'print') {
        if (self::saveReceiptForLaterPrint($receiptsForPrinting, $queueId, $metadata['type'], $contactId)) {
          // add contactId to metadata to compute the pdf to print at the end
          if (!isset($metadata['print'])) $metadata['print'] = [];
          $metadata['print'][] = $contactId;
        }
        else {
          $metadata['count']['fail']++;
        }
      }

      // save metadata
      $results = \Civi\Api4\UserJob::update(TRUE)
        ->addValue('metadata', $metadata)
        ->addWhere('id', '=', $userJobId)
        ->execute();

    }

    return TRUE;
  }


  // TODO: ensure we don't do the same contactId twice (e.g. how does the queue deal with refresh?)
  static function processOneContactReceipt(CRM_Queue_TaskContext $ctx, $contactId, $year, $previewMode) {

    $queueName = $ctx->queue->getName();

	  list($issuedOn, $receiptId) = cdntaxreceipts_annual_issued_on($contactId, $year);
    $contributions = cdntaxreceipts_contributions_not_receipted($contactId, $year);

    if (empty($issuedOn)) {

      $userJob = \Civi\Api4\UserJob::get(TRUE)
        ->addSelect('id', 'metadata', 'queue_id')
        ->addWhere('queue_id.name', '=', $queueName)
        ->setLimit(1)
        ->execute()
        ->first();
      $queueId = $userJob['queue_id'];
      $userJobId = $userJob['id'];
      $metadata = $userJob['metadata'];

      // keeping the legacy way for printing : collect in a file
      // but here, we save it to a temporary file for later use when the full queue is processed
      $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
      list($ret, $method) = cdntaxreceipts_issueAnnualTaxReceipt($contactId, $year, $receiptsForPrinting, $previewMode);

      // update statistics
      self::updateMetadata($ret, $method, $metadata);

      // in preview mode print everything
      if ($previewMode || $method == 'print') {
        if (self::saveReceiptForLaterPrint($receiptsForPrinting, $queueId, $metadata['type'], $contactId)) {
          // add contactId to metadata to compute the pdf to print at the end
          if (!isset($metadata['print'])) $metadata['print'] = [];
          $metadata['print'][] = $contactId;
        }
        else {
          $metadata['count']['fail']++;
        }
      }

      // save metadata
      $results = \Civi\Api4\UserJob::update(TRUE)
        ->addValue('metadata', $metadata)
        ->addWhere('id', '=', $userJobId)
        ->execute();

    }

    return TRUE;
  }

  static function saveReceiptForLaterPrint($receiptsForPrinting, $queueId, $type, $objectId) {

    // save the file for later usage
    $filename = self::makeTmpFileName($queueId, $type, $objectId);
    if ($receiptsForPrinting->getNumPages() > 0) {
      if (!file_exists($filename)) {
        $receiptsForPrinting->Output($filename, 'F');
        $receiptsForPrinting->Close();
      }
    } 
    else { 
      return False;
    }

    // Ok, created
    return True;
  }

  static function makeTmpFileName($queueId, $type, $objectId) {
    $config = CRM_Core_Config::singleton();
    // FIXME: don't munge to simplify the process but we should add a global munge for security
    return $config->uploadDir . CRM_Utils_File::makeFilenameWithUnicode('queuejob_' . $queueId . '_' . $type . $objectId) . '.pdf';
  }


  static function getAllCollectedPdfFilename($queueId) {
    $userJob = \Civi\Api4\UserJob::get(FALSE)
      ->addSelect('metadata')
      ->addWhere('queue_id', '=', $queueId)
      ->setLimit(1)
      ->execute()
      ->first();
    $metadata = $userJob['metadata'];
    $previewMode = $metadata['preview'];
    $year = $metadata['year'];
    $type = $metadata['type'];

    // get the pdf of printed
    $pdf = cdntaxreceipts_openCollectedPDF();
    $objects = $metadata['print'];
    foreach ($objects as $objectId) {
      $filename = self::makeTmpFileName($queueId, $type, $objectId);
      if (file_exists($filename)) {
        $pageCount = $pdf->setSourceFile($filename);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
          // import a page
          $templateId = $pdf->importPage($pageNo);
          // get the size of the imported page
          $size = $pdf->getTemplateSize($templateId);

          // create a page (landscape or portrait depending on the imported page size)
          if ($size['w'] > $size['h']) {
            $pdf->AddPage('L', array($size['w'], $size['h']));
          } else {
            $pdf->AddPage('P', array($size['w'], $size['h']));
          }

          // use the imported page
          $pdf->useTemplate($templateId);
        }
      }
      else {
        // error
        $metadata['count']['fail']++;
      }
    }

    // save metadata
    $results = \Civi\Api4\UserJob::update(TRUE)
      ->addValue('metadata', $metadata)
      ->addWhere('id', '=', $userJobId)
      ->execute();

    return $pdf;
  }

}
