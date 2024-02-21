<?php

class CRM_Cdntaxreceipts_Utils {
  
  // TODO: ensure we don't do the same contactId twice (e.g. how does the queue deal with refresh?)
  static function processOneReceipt(CRM_Queue_TaskContext $ctx, $contactId, $year, $previewMode) {

    $queueName = $ctx->queue->getName();

	  list( $issuedOn, $receiptId ) = cdntaxreceipts_annual_issued_on($contactId, $year);
    $contributions = cdntaxreceipts_contributions_not_receipted($contactId, $year);

    if (empty($issuedOn)) {

      $userJob = \Civi\Api4\UserJob::get(TRUE)
        ->addSelect('id', 'metadata')
        ->addWhere('queue_id.name', '=', $queueName)
        ->setLimit(1)
        ->execute()
        ->first();
      $userJobId = $userJob['id'];
      $metadata = $userJob['metadata'];

      // we delay the ones that needs to be printed to the end of the process to avoid being marked as duplicates
      list($method, $email) = cdntaxreceipts_sendMethodForContact($contactId);
      $ret = TRUE;
      if ($method != 'print') {
        // dummy PDF to collect receipts that cannot be emailed
        // it will be re-generated later on
        // FIXME: this will likely create a Duplicate mode issue ?
        $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
        list($ret, $method) = cdntaxreceipts_issueAnnualTaxReceipt($contactId, $year, $receiptsForPrinting, $previewMode);
      }

      // update statistics
      if ($ret == 0) {
        $metadata['count']['fail']++;
      }
      elseif ($method == 'email') {
        $metadata['count']['email']++;
      }
      elseif ($method == 'print') {
        $metadata['count']['print']++;
      }
      elseif ( $method == 'data') {
        $metadata['count']['data']++;
      }

      // in preview mode print everything
      if ($previewMode || $method == 'print') {
        // add contactId to metadata to compute the pdf to print at the end
        if (!isset($metadata['print'])) $metadata['print'] = [];
        $metadata['print'][] = $contactId;
      }

      // save metadata
      $results = \Civi\Api4\UserJob::update(TRUE)
        ->addValue('metadata', $metadata)
        ->addWhere('id', '=', $userJobId)
        ->execute();

    }

    return TRUE;
  }

}
